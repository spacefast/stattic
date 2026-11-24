<?php

declare(strict_types=1);

namespace TypedPress\Abilities;

use TypedPress\Extensions;
use TypedPress\HttpError;
use TypedPress\Wp\Abilities\Invoke;
use TypedPress\Wp\Abilities\Register;

/**
 * Feature 5 (§7.1): abilities, their category, the app's MCP server and `wp typedpress run|abilities`
 * (all in TypedPress\Wp\Abilities). `agents: false` keeps an entry out of those surfaces; `/_schema` still
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
        // TypedPress's API (`/wp-json/typedpress/v1`) dispatches to core routes but never to `/mcp`: no MCP
        // server is served there, so none is built (building one builds every ability of the site).
        add_filter('mcp_adapter_create_default_server', static fn (mixed $create): bool => (bool) $create && \TypedPress\Wp\Facade::path() === null);
        add_filter('wp_ability_validate_input', [Register::class, 'validate'], 10, 3);
        add_filter('rest_pre_dispatch', [Register::class, 'jsonInput'], -1, 3);
        add_filter('rest_pre_dispatch', [Register::class, 'startSanitizing'], 0, 3);
        add_filter('rest_pre_dispatch', [Register::class, 'discardShortCircuit'], PHP_INT_MAX, 3);
        add_filter('rest_request_before_callbacks', [Register::class, 'beginCallbacks'], 0, 3);
        add_filter('rest_request_before_callbacks', [Register::class, 'beforePermission'], PHP_INT_MAX, 3);
        add_filter('rest_dispatch_request', [Register::class, 'beforeExecute'], PHP_INT_MAX, 2);
        add_filter('rest_post_dispatch', [Register::class, 'dispatched'], 0, 3);
        // An identity session cookie (where the host turns that on) signs in a run of the app's own
        // abilities, as it does on the app API: the typed client runs them there.
        add_filter('tp_identity_rest_cookie_route', [Register::class, 'cookieRoute'], 10, 2);
        // An internal dispatch (rest_do_request, the API's run of an action) skips rest_post_dispatch:
        // the operation's own headers go on the run response here.
        add_filter('rest_request_after_callbacks', [Register::class, 'answered'], 0, 3);
        Extensions::cli('abilities', static fn (): array => array_map(Register::describe(...), Register::operations()));
        Extensions::cli('run', self::run(...));
    }

    /**
     * `wp typedpress run <ability> [--input=<json>]`, as WP-CLI's `--user`: core's own execute path
     * (validation, permission, output check), whether or not the ability is visible to agents.
     */
    private static function run(array $args, array $flags): mixed
    {
        $name = $args[0] ?? throw HttpError::invalidInput('Usage: wp typedpress run <ability> [--input=<json>]');
        $ability = wp_get_ability($name) ?? throw HttpError::notFound("Unknown ability '$name'");
        $input = null;
        if (isset($flags['input'])) {
            try {
                $input = \TypedPress\Js::decodeJson((string) $flags['input']); // {} stays {} inside json() values
            } catch (\JsonException $e) {
                throw new HttpError(400, 'invalid_json', '--input is not valid JSON: ' . $e->getMessage(), '/input');
            }
        }
        $result = $ability->execute($input);

        return $result instanceof \WP_Error ? Invoke::throw($result) : $result;
    }
}
