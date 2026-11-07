<?php

declare(strict_types=1);

namespace Zero\Abilities;

use Zero\Extensions;
use Zero\HttpError;
use Zero\Wp\Abilities\Invoke;
use Zero\Wp\Abilities\Register;

/**
 * Feature 5 (§7.1): abilities, their category, the app's MCP server and `wp zero run|abilities`
 * (all in Zero\Wp\Abilities). `agents: false` keeps an entry out of those surfaces; `/_schema` still
 * describes it, with the flag.
 */
final class Module
{
    /** @var ?\Closure(string, array): mixed */
    private static ?\Closure $enqueue = null;

    /**
     * Lane D's one line, from its module's register(): `Module::jobs(fn (string $job, array $input)
     * => $jobId)` adds `<app>/job-<name>` for `agents: true` jobs. The enqueue runs in a transaction
     * with the calling user bound as actor. Sync needs no line: abilities appear for collections
     * with `sync` as soon as a `/sync` route is registered (§5.3).
     */
    public static function jobs(callable $enqueue): void
    {
        self::$enqueue = $enqueue(...);
    }

    public static function enqueuer(): ?\Closure
    {
        return self::$enqueue;
    }

    public static function register(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        add_action('wp_abilities_api_categories_init', [Register::class, 'categories']);
        add_action('wp_abilities_api_init', [Register::class, 'abilities']);
        add_action('mcp_adapter_init', [Register::class, 'mcp']);
        add_filter('wp_ability_validate_input', [Register::class, 'validate'], 10, 3);
        add_filter('rest_pre_dispatch', Register::sanitizing(true), 0, 3);
        add_filter('rest_request_before_callbacks', Register::sanitizing(false), 0);
        add_filter('rest_post_dispatch', [Register::class, 'dispatched'], 0);
        Extensions::cli('abilities', static fn (): array => array_map(Register::describe(...), Register::operations()));
        Extensions::cli('run', self::run(...));
    }

    /**
     * `wp zero run <ability> [--input=<json>]`, as WP-CLI's `--user`: core's own execute path
     * (validation, permission, output check), whether or not the ability is visible to agents.
     */
    private static function run(array $args, array $flags): mixed
    {
        $name = $args[0] ?? throw HttpError::invalidInput('Usage: wp zero run <ability> [--input=<json>]');
        $ability = wp_get_ability($name) ?? throw HttpError::notFound("Unknown ability '$name'");
        $input = null;
        if (isset($flags['input'])) {
            try {
                $input = \Zero\Js::decodeJson((string) $flags['input']); // {} stays {} inside json() values
            } catch (\JsonException $e) {
                throw new HttpError(400, 'invalid_json', '--input is not valid JSON: ' . $e->getMessage(), '/input');
            }
        }
        $result = $ability->execute($input);

        return $result instanceof \WP_Error ? Invoke::throw($result) : $result;
    }
}
