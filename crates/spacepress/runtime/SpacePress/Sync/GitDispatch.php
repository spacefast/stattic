<?php

declare(strict_types=1);

namespace SpacePress\Sync;

use SpacePress\Env;
use SpacePress\HttpError;
use SpacePress\Http;
use SpacePress\Jobs\Jobs;

/**
 * Content in git, the site's half: a save of a synced record tells the project's GitHub repository,
 * with a `repository_dispatch` of type `spacepress-content-changed`, so the workflow `spacepress init --github`
 * wrote runs `spacepress sync --pr` and the edit comes back as a pull request.
 *
 * Debounced: a save enqueues the built-in job `spacepress.git-dispatch` under one dedupe key with a delay
 * (the window, 60 s by default). Saves while it waits join it, so a burst of edits sends one dispatch
 * at the end of its window. The job runs through SpacePress's job runner on Action Scheduler; nothing here
 * schedules work of its own. Writes made by `spacepress sync` itself send nothing: that run already has them.
 *
 * Settings, the environment (or wp-config.php constants) first:
 * - SP_GITHUB_REPOSITORY, else the option `sp_git_dispatch_repository`: `owner/name`.
 * - SP_GITHUB_DISPATCH_TOKEN, else the Secrets API's `sp/github-dispatch-token` when WordPress
 *   has it ({@see wp_is_secrets_api_available()}, WordPress 7.2). Never an option: without the Secrets
 *   API (WordPress 7.1) the token lives in the environment only, and with neither, saves queue nothing
 *   and `status` says why; the workflow's hourly sync still runs.
 * - SP_GITHUB_API_URL (GitHub Enterprise; tests) and SP_GITHUB_DISPATCH_WINDOW (seconds).
 */
final class GitDispatch
{
    public const JOB = 'spacepress.git-dispatch';
    public const EVENT = 'spacepress-content-changed';
    public const SECRET = 'sp/github-dispatch-token';
    public const REPOSITORY_OPTION = 'sp_git_dispatch_repository';
    private const KEY = 'dispatch';
    private const WINDOW_S = 60;
    private const NO_SECRETS = 'SP_GITHUB_DISPATCH_TOKEN is not set, and this WordPress has no Secrets API to keep one (it is in WordPress 7.2): saves send nothing, and the hourly sync still runs';
    private const UNAVAILABLE = 'SP_GITHUB_DISPATCH_TOKEN is not set, and the Secrets API is unavailable (its provider or key is broken), so a stored token cannot be read';
    private const NO_TOKEN = 'no token is set: wp spacepress git-dispatch set --token, or SP_GITHUB_DISPATCH_TOKEN';
    private const USAGE = 'Usage: git-dispatch status | set --repo=<owner/name> [--token] (the token on stdin) | clear [--token]';

    /** True while `spacepress sync`'s own routes write: those changes are already in the run that made them. */
    private static bool $quiet = false;

    public static function spec(): array
    {
        return [
            'input' => [],
            'handler' => self::deliver(...),
            'retries' => 5,
            'agents' => false,
        ];
    }

    /** Run $fn with dispatches off (the `/sync` routes). */
    public static function quietly(callable $fn): mixed
    {
        $was = self::$quiet;
        self::$quiet = true;
        try {
            return $fn();
        } finally {
            self::$quiet = $was;
        }
    }

    /** Extensions::onWritten: inside the write's transaction, so a rollback drops the dispatch too. */
    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        if (self::$quiet || (\SpacePress\db()->schema()->collection($collection)['sync'] ?? null) === null || self::problem() !== null) {
            return;
        }
        $window = Env::get('SP_GITHUB_DISPATCH_WINDOW');
        $delay = $window !== null && preg_match('/^\d{1,5}$/D', $window) ? (int) $window : self::WINDOW_S;
        Jobs::enqueue(self::JOB, [], ['key' => self::KEY, 'delay' => $delay]);
    }

    /** `owner/name`, or null when no repository is set (nothing is sent). */
    public static function repository(): ?string
    {
        $repo = Env::get('SP_GITHUB_REPOSITORY');
        if ($repo === null || $repo === '') {
            $repo = function_exists('get_option') ? get_option(self::REPOSITORY_OPTION, '') : '';
        }

        return is_string($repo) && self::validRepository($repo) ? $repo : null;
    }

    private static function validRepository(string $repo): bool
    {
        return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo) === 1 && !preg_match('#(^|/)\.{1,2}(/|$)#', $repo);
    }

    /**
     * Why a save sends nothing, or null when it can: a repository, and a token in the environment or a
     * Secrets API that may hold one. Without either (WordPress 7.1) a dispatch could only fail, so none
     * is queued. A Secrets API that is there but unavailable may hold one once it is fixed: the save
     * queues, and the dispatch fails loudly and retries.
     */
    private static function problem(): ?string
    {
        if (self::repository() === null) {
            return 'no repository is set: wp spacepress git-dispatch set --repo=<owner/name>';
        }
        if ((Env::get('SP_GITHUB_DISPATCH_TOKEN') ?? '') === '' && !function_exists('wp_get_secret')) {
            return self::NO_SECRETS;
        }

        return null;
    }

    private static function secretsAvailable(): bool
    {
        return function_exists('wp_is_secrets_api_available') && wp_is_secrets_api_available() && function_exists('wp_get_secret');
    }

    /** Whether WordPress can keep the token: the Secrets API is there and its provider takes writes. */
    public static function secretsWritable(): bool
    {
        return function_exists('wp_is_secrets_api_available') && wp_is_secrets_api_available()
            && (!function_exists('wp_secrets_provider_is_writable') || wp_secrets_provider_is_writable());
    }

    /** The token and where it came from, or [null, why]. Never logged or returned to a caller. */
    private static function token(): array
    {
        $token = Env::get('SP_GITHUB_DISPATCH_TOKEN');
        if ($token !== null && $token !== '') {
            return [$token, 'environment'];
        }
        if (!self::secretsAvailable()) {
            return [null, function_exists('wp_get_secret') ? self::UNAVAILABLE : self::NO_SECRETS];
        }
        $secret = wp_get_secret(self::SECRET);
        if ($secret === null) {
            return [null, self::NO_TOKEN];
        }
        if (function_exists('is_wp_error') && is_wp_error($secret)) {
            // Stored but unreadable now (key or provider): a retry may succeed; never ask for a new one.
            throw new \RuntimeException('the dispatch token is stored but unreadable: ' . $secret->get_error_message());
        }

        return [(string) $secret->reveal(), 'secrets'];
    }

    /** The job: one `repository_dispatch`. A non-2xx answer throws, so the runner retries with backoff. */
    public static function deliver(array $input, array $ctx): void
    {
        $repo = self::repository();
        if ($repo === null) {
            return; // unset since the save: nothing to tell
        }
        [$token, $why] = self::token();
        if ($token === null) {
            throw new \RuntimeException("content changed but the repository can't be told: $why");
        }
        $api = rtrim(Env::get('SP_GITHUB_API_URL') ?? 'https://api.github.com', '/');
        $body = \SpacePress\Js::jsonStringify(['event_type' => self::EVENT, 'client_payload' => ['site' => function_exists('home_url') ? home_url() : '']]) ?? '{}';
        $response = Http::post("$api/repos/$repo/dispatches", $body, [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'User-Agent' => 'spacepress-runtime',
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);
        if ($response['status'] < 200 || $response['status'] > 299) {
            $hint = in_array($response['status'], [401, 403, 404], true) ? ' (the token needs contents: write on ' . $repo . ')' : '';
            throw new \RuntimeException("GitHub answered {$response['status']} to the repository_dispatch$hint");
        }
    }

    /** `wp spacepress git-dispatch …`. */
    public static function cli(array $args, array $flags): array
    {
        $usage = static fn () => new HttpError(400, 'invalid_command', self::USAGE);

        return match ($args[0] ?? '') {
            'status' => self::status(),
            'set' => self::set($flags),
            'clear' => self::clear(isset($flags['token'])),
            default => throw $usage(),
        };
    }

    private static function status(): array
    {
        $env = Env::get('SP_GITHUB_DISPATCH_TOKEN');
        $stored = false;
        $unreadable = null;
        if (($env === null || $env === '') && self::secretsAvailable()) {
            $secret = wp_get_secret(self::SECRET);
            $stored = $secret !== null && !(function_exists('is_wp_error') && is_wp_error($secret));
            $unreadable = $secret !== null && !$stored ? 'the dispatch token is stored but unreadable: ' . $secret->get_error_message() : null;
        }

        $token = ($env ?? '') !== '' ? 'environment' : ($stored ? 'secrets' : null);

        return [
            'repository' => self::repository(),
            'repositoryFrom' => (Env::get('SP_GITHUB_REPOSITORY') ?? '') !== '' ? 'environment' : 'option',
            'token' => $token,
            'secretsApi' => function_exists('wp_is_secrets_api_available') ? wp_is_secrets_api_available() : null,
            'event' => self::EVENT,
            // Why a save sends nothing now, or null when it sends a dispatch.
            'problem' => self::problem() ?? ($token !== null ? null : (!self::secretsAvailable() ? self::UNAVAILABLE : ($unreadable ?? self::NO_TOKEN))),
        ];
    }

    private static function set(array $flags): array
    {
        $repo = $flags['repo'] ?? null;
        if ($repo !== null) {
            if (!is_string($repo) || !self::validRepository($repo)) {
                throw HttpError::invalidInput('--repo must be owner/name', '/repo');
            }
            update_option(self::REPOSITORY_OPTION, $repo, false);
        }
        if (isset($flags['token'])) {
            if ($flags['token'] !== true) {
                // A value on the command line would be in the shell's history and the process list.
                throw HttpError::invalidInput('--token takes no value: pipe the token on stdin', '/token');
            }
            self::storeToken(defined('STDIN') ? trim((string) stream_get_contents(STDIN)) : '');
        }
        if ($repo === null && !isset($flags['token'])) {
            throw HttpError::invalidInput(self::USAGE);
        }

        return self::status();
    }

    /** Keep the token in WordPress's Secrets API; refused where there is none, never kept elsewhere. */
    public static function storeToken(string $token): void
    {
        if (!self::secretsWritable()) {
            throw new HttpError(409, 'secrets_unavailable', 'This WordPress has no writable Secrets API, so the token is never stored in the database: set SP_GITHUB_DISPATCH_TOKEN in the environment or wp-config.php instead.');
        }
        if ($token === '' || preg_match('/\s/', $token)) {
            throw HttpError::invalidInput('the token on stdin is empty or has whitespace in it', '/token');
        }
        $done = wp_set_secret(self::SECRET, $token);
        if (function_exists('is_wp_error') && is_wp_error($done)) {
            throw new HttpError(500, 'secrets_error', 'The Secrets API refused the token: ' . $done->get_error_message());
        }
    }

    private static function clear(bool $token): array
    {
        delete_option(self::REPOSITORY_OPTION);
        if ($token && function_exists('wp_delete_secret') && self::secretsWritable()) {
            wp_delete_secret(self::SECRET);
        }

        return self::status();
    }
}
