<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\JsArray;
use TypedPress\JsObject;

/**
 * WordPress functions called from TypeScript (`import { get_option } from "php/wordpress"`). The
 * compiler only emits calls to the catalog's allowlist (src/wordpress/catalog.json) with the
 * arguments the author may pass and the fixed ones the catalog adds; this converts values at the
 * boundary: JS values in as PHP arrays, results out as JS values or typed WP_Post/WP_User views.
 * Guard refuses protected options and post types first, for names the build could not see.
 */
final class Functions
{
    /** @param list<mixed> $args the author's arguments, then the catalog's fixed ones */
    public static function call(string $function, array $args): mixed
    {
        if (in_array($function, ['is_user_logged_in', 'current_user_can', 'get_current_user_id'], true)) {
            \TypedPress\Render\Prebuilt::viewerCall($function);
        }
        $args = array_map(self::toPhp(...), $args);
        Guard::call($function, $args);
        if ($function === 'get_option' && is_string($args[0] ?? null)
            && in_array($args[0], Runtime::plugin()?->schema->app()['purgeOptions'] ?? [], true)) {
            \TypedPress\Render\Reads::option($args[0]);
        }
        $result = self::tracked(static fn () => $function(...$args));
        if ($result instanceof \WP_Error) {
            // The error's own status when it carries one; otherwise WordPress refused the input.
            $data = $result->get_error_data();
            $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : 422;
            throw new HttpError($status, (string) $result->get_error_code(), $result->get_error_message());
        }
        $plugin = Runtime::plugin();

        return Hooks::toJs($result, $plugin?->schema->wpViews() ?? []);
    }

    /**
     * Inside a TypedPress transaction, a core write rolls back with the handler, but WordPress's object
     * cache does not: record every option and post the call writes so a rollback cleans them.
     * Native plugin CRUD (such as shipping reconciliation) uses the same tracking as TS calls.
     */
    public static function tracked(\Closure $call): mixed
    {
        $connection = Runtime::plugin()?->connection();
        if ($connection === null || !$connection->inTransaction()) {
            return $call();
        }
        $option = static function (string $name) use ($connection): void {
            $connection->touchOption($name);
        };
        $post = static function (int $id) use ($connection): void {
            $connection->touchPost($id);
        };
        $hooks = [['added_option', $option], ['updated_option', $option], ['deleted_option', $option],
            ['wp_insert_post', $post], ['clean_post_cache', $post]];
        foreach ($hooks as [$hook, $fn]) {
            add_action($hook, $fn, PHP_INT_MIN, 1);
        }
        try {
            return $call();
        } finally {
            foreach ($hooks as [$hook, $fn]) {
                remove_action($hook, $fn, PHP_INT_MIN);
            }
        }
    }

    private static function toPhp(mixed $value): mixed
    {
        return match (true) {
            $value instanceof JsObject, $value instanceof JsArray => \TypedPress\Seam::toPhp($value),
            $value === \TypedPress\JsUndefined::Value => null,
            default => $value,
        };
    }
}
