<?php

declare(strict_types=1);

namespace TypedPress\Render;

use TypedPress\Commerce\Module as Commerce;
use TypedPress\Wp\AppRouter;
use TypedPress\Wp\Plugin;

/** The compiler's cache upper bound and the fixed paths that a write can invalidate directly. */
final class RoutePlan
{
    public static function publicRequest(): bool
    {
        return in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
            && ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === '' && ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '') === ''
            && !isset($_SERVER['PHP_AUTH_USER'])
            && !isset($_COOKIE['pll_language']) && !Cache::viewerCookie()
            && (int) get_option('blog_public', 1) >= 0 && !get_option('wpcom_public_coming_soon') && !get_option('wpcom_coming_soon');
    }

    public static function requestAllowsSharing(Plugin $plugin): bool
    {
        return self::publicRequest() && !Commerce::privateRequest($plugin) && !Commerce::cartSession($plugin);
    }

    /** @return list<string> URLs to put through the normal commit and Markdown purge handling */
    public static function urls(?Plugin $plugin, string $key): array
    {
        $paths = $plugin?->schema->app()['purgeRoutes'][$key] ?? [];
        return array_map(static fn (string $path): string => home_url(AppRouter::address($path)), $paths);
    }

    /** Does the plan already cover every observed dependency at this exact, query-free URL? */
    public static function covers(array $plan, string $pattern, string $uri, array $reads, array $also): bool
    {
        if (!($plan['fixedPurge'] ?? false) || $uri !== wp_parse_url(home_url(AppRouter::address($pattern)), PHP_URL_PATH)) {
            return false;
        }
        $kinds = [];
        $other = [];
        foreach ($plan['dependencies'] as $dep) {
            switch ($dep['type']) {
                case 'list':
                case 'count':
                case 'search':
                    $kinds[$dep['collection']] = true;
                    break;
                case 'relation':
                    $kinds[$dep['target']] = true;
                    break;
                case 'global':
                case 'option':
                    $other[$dep['type'] . ':' . $dep['name']] = true;
                    break;
            }
        }
        return array_diff_key($reads, $kinds) === [] && array_diff_key(array_fill_keys($also, true), $other) === [];
    }
}
