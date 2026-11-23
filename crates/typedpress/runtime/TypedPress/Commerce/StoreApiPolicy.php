<?php

declare(strict_types=1);

namespace TypedPress\Commerce;

use TypedPress\Render\Cache;

final class StoreApiPolicy
{
    private const STORE_PREFIX = '/wc/store/';

    public static function register(string|false|null $frontend): void
    {
        $frontend = is_string($frontend) ? self::origin($frontend) : null;
        add_filter('rest_jsonp_enabled', static function (bool $enabled): bool {
            $route = self::httpRoute();
            return $enabled && !self::storeRoute($route) && !self::batchRoute($route);
        });
        add_filter('rest_pre_dispatch', static function ($result, $server, $request) use ($frontend) {
            $route = $request->get_route();
            if ($result !== null || !self::storeRoute($route)) { return $result; }
            // WordPress's route regex accepts case aliases that bypass Woo's authentication and CORS hooks.
            if (!str_starts_with($route, self::STORE_PREFIX)) {
                return rest_convert_error_to_response(new \WP_Error('rest_no_route', __('No route was found matching the URL and request method.'), ['status' => 404]));
            }
            if (self::batchRoute(self::httpRoute())) {
                if (self::externalOrigin($frontend)) {
                    // The batch envelope includes inner headers even on errors. Stop before Woo opens a session.
                    return rest_convert_error_to_response(new \WP_Error('tp_store_batch_forbidden', 'Use the Store API route directly.', ['status' => 403]));
                }
            } elseif (self::frontendRequest($frontend) && self::preflight()) {
                // Check the HTTP method itself, before a REST method override can dispatch a handler.
                return new \WP_REST_Response(null, 204);
            }
            return $result;
        }, 10, 3);
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (self::storeRoute($request->get_route()) || self::includesStoreRequest($request)) {
                $response->header('Cache-Control', Cache::PRIVATE);
                $response->header(Cache::EDGE_HEADER, 'no-cache');
                if (function_exists('batcache_cancel')) { batcache_cancel(); }
            }
            return $response;
        }, 10, 3);
        add_filter('rest_pre_serve_request', static function ($served, $result, $request, $server) use ($frontend) {
            if (!str_starts_with($request->get_route(), self::STORE_PREFIX) || !self::frontendRequest($frontend) || !self::preflight()) {
                return $served;
            }
            // Woo sends credentialed headers and exits at priority 10 on a preflight.
            self::sendFrontendCors($server, $frontend);
            $server->send_header('Access-Control-Allow-Methods', 'OPTIONS, GET, POST, PUT, PATCH, DELETE');
            $server->send_header('Vary', 'Origin', false);
            status_header(204);
            exit;
        }, 9, 4);
        add_filter('rest_pre_serve_request', static function ($served, $result, $request, $server) use ($frontend) {
            $route = $request->get_route();
            $store = str_starts_with($route, self::STORE_PREFIX);
            $batch = self::includesStoreRequest($request);
            if ((!$store && self::storeRoute($route)) || ($batch && self::externalOrigin($frontend))) {
                $server->remove_header('Access-Control-Allow-Origin');
                $server->remove_header('Access-Control-Allow-Credentials');
            }
            if (($store || $batch) && self::frontendRequest($frontend)) {
                self::sendFrontendCors($server, $frontend);
            }
            return $served;
        }, 11, 4);
    }

    private static function httpRoute(): string
    {
        $route = $GLOBALS['wp']->query_vars['rest_route'] ?? '';
        return is_string($route) ? $route : '';
    }

    private static function storeRoute(string $route): bool
    {
        return strncasecmp($route, self::STORE_PREFIX, strlen(self::STORE_PREFIX)) === 0;
    }

    private static function batchRoute(string $route): bool
    {
        return strcasecmp(rtrim($route, '/'), '/batch/v1') === 0;
    }

    private static function includesStoreRequest(\WP_REST_Request $request): bool
    {
        if (!self::batchRoute($request->get_route())) { return false; }
        $requests = $request['requests'];
        if (!is_array($requests)) { return false; }
        foreach ($requests as $inner) {
            $path = $inner['path'] ?? null;
            if (is_string($path) && self::storeRoute((string) wp_parse_url($path, PHP_URL_PATH))) { return true; }
        }
        return false;
    }

    private static function origin(string $frontend): ?string
    {
        $url = wp_parse_url($frontend);
        if (!isset($url['scheme'], $url['host'])) { return null; }
        $scheme = strtolower($url['scheme']);
        $port = isset($url['port']) && $url['port'] !== (['http' => 80, 'https' => 443][$scheme] ?? 0) ? ':' . $url['port'] : '';
        return $scheme . '://' . strtolower($url['host']) . $port;
    }

    private static function frontendRequest(?string $frontend): bool
    {
        return $frontend !== null && ($_SERVER['HTTP_ORIGIN'] ?? null) === $frontend;
    }

    private static function externalOrigin(?string $frontend): bool
    {
        $origin = get_http_origin();
        return $origin !== '' && (self::frontendRequest($frontend) || !is_allowed_http_origin($origin));
    }

    private static function preflight(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
            && isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'], $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
    }

    private static function sendFrontendCors(\WP_REST_Server $server, string $origin): void
    {
        $server->send_header('Access-Control-Allow-Origin', $origin);
        $server->remove_header('Access-Control-Allow-Credentials');
    }
}
