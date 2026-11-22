<?php
declare(strict_types=1);

// Single lazy fallback entrypoint for deprecated runtime lanes:
// - Zero 0.8 (`deprecated/zero-0.8/`)
// - Zero v2 (`deprecated/zero-v2/`)
// - Legacy `content.managed` (`deprecated/content/`)
//
// Normal static, Cloudflare Functions, and SpacePress requests NEVER require
// this file or anything under `engine/deprecated/`.

/** Run the legacy Zero 0.8 / Zero v2 generation cordon when applicable. */
function _stattic_legacy_serve_cordon(string $versionDir, array $serving): void
{
    require_once __DIR__ . '/zero-v2/zero-v2.php';
    _stattic_zero_generation_serve($versionDir, $serving);
}

/** Fold canonical `/__zero/*` control paths to legacy `/__spacefast/zero/*` keys. */
function _stattic_legacy_serve_zero_table_key(string $requestPath): ?string
{
    require_once __DIR__ . '/zero-0.8/context.php';
    return _stattic_zero_legacy_control_path($requestPath);
}

/** Handle legacy Zero v2 client/realtime scripts and Zero 0.8 control routes. */
function _stattic_legacy_serve_control_request(
    string $versionDir,
    array $serving,
    string $requestHost,
    string $requestPath,
    string $requestUri,
    string $requestMethod
): void {
    if ($requestPath === STATTIC_ZERO_V2_REALTIME_PATH) {
        require_once __DIR__ . '/zero-v2/zero-v2-realtime.php';
        _stattic_zero_v2_realtime_script($versionDir, $requestMethod);
    }
    if ($requestPath === STATTIC_ZERO_V2_CLIENT_PATH || $requestPath === STATTIC_ZERO_V2_CLIENT_TYPES_PATH) {
        require_once __DIR__ . '/zero-v2/zero-v2.php';
        _stattic_zero_v2_client($versionDir, $requestPath, $requestMethod);
    }
    require_once __DIR__ . '/zero-0.8/context.php';
    require_once __DIR__ . '/zero-0.8/artifacts.php';
    require_once __DIR__ . '/zero-0.8/connectors.php';
    require_once __DIR__ . '/zero-0.8/serve.php';
    _stattic_legacy_zero_control_request($versionDir, $serving, $requestHost, $requestPath, $requestUri, $requestMethod);
}

/** Dispatch an exact Zero 0.8 response-table action entry. */
function _stattic_legacy_serve_zero_action(array $context, array $action, string $requestPath): void
{
    require_once __DIR__ . '/zero-0.8/context.php';
    require_once __DIR__ . '/zero-0.8/artifacts.php';
    require_once __DIR__ . '/zero-0.8/connectors.php';
    require_once __DIR__ . '/zero-0.8/serve.php';
    _stattic_legacy_zero_dispatch_action($context, $action, $requestPath);
}

/** Prime anonymous visitor session on legacy Zero 0.8 HTML documents. */
function _stattic_legacy_serve_prime_zero_document_session(
    array $context,
    array $headers,
    int $status,
    string $method
): void {
    require_once __DIR__ . '/zero-0.8/serve.php';
    _stattic_zero_prime_anonymous_document_session($context, $headers, $status, $method);
}

/** Handle the legacy `zero` broker lane in `functions-relay.php`. */
function _stattic_legacy_serve_relay_zero(
    string $body,
    ?array $visitor,
    array $identity,
    string $spaceId,
    array $config,
    string $versionRoot,
    array $serving,
    string $host
): void {
    require_once __DIR__ . '/zero-0.8/context.php';
    require_once __DIR__ . '/zero-0.8/artifacts.php';
    require_once __DIR__ . '/zero-0.8/connectors.php';
    require_once __DIR__ . '/zero-0.8/serve.php';
    _stattic_legacy_zero_relay_run($body, $visitor, $identity, $spaceId, $config, $versionRoot, $serving, $host);
}

/**
 * Fallback ladder for deployed Zero 0.8 pattern routes and legacy
 * `content.managed` Spaces (content redirects, `pages.php` documents, and
 * `wordpress-routes.json` fallbacks).
 *
 * Returns true ONLY when a WordPress page is deferred to document-root reentry.
 */
function _stattic_legacy_serve_fallback(
    array $sendContext,
    string $requestPath,
    string $requestMethod,
    string $requestUri,
    ?array $page,
    bool $hasPages
): bool {
    $privateRoot = (string) $sendContext['private_root'];
    $spaceId = (string) $sendContext['space_id'];
    $versionDir = (string) $sendContext['version_dir'];
    $root = is_array($sendContext['root'] ?? null) ? $sendContext['root'] : [];
    $serving = is_array($sendContext['serving'] ?? null) ? $sendContext['serving'] : [];
    $requestHost = (string) $sendContext['host'];

    // 1. Legacy Zero 0.8 pattern routes (`zero/routes.php`).
    if (is_file($versionDir . '/zero/routes.php')) {
        require_once __DIR__ . '/zero-0.8/context.php';
        require_once __DIR__ . '/zero-0.8/artifacts.php';
        require_once __DIR__ . '/zero-0.8/connectors.php';
        require_once __DIR__ . '/zero-0.8/serve.php';
        _stattic_legacy_zero_dispatch_patterns($sendContext, $requestPath, $requestMethod, $requestUri);
    }

    // 2. Legacy WordPress content redirects scoped to the Space.
    if ($page === null && !_stattic_path_is_reserved($requestPath) && empty($serving['immutable'])) {
        $contentRedirectFile = $privateRoot . '/spaces/' . $spaceId . '/content-redirects.json';
        if (is_file($contentRedirectFile)) {
            $contentRedirects = json_decode((string) file_get_contents($contentRedirectFile), true);
            if (is_array($contentRedirects)) {
                require_once __DIR__ . '/../runtime/redirects.php';
                $redirectKey = _stattic_redirect_match_path($requestPath);
                if (is_array($contentRedirects['exact'][$redirectKey] ?? null)) {
                    $contentRedirects['exact'][$redirectKey] = array_values(array_filter(
                        $contentRedirects['exact'][$redirectKey],
                        static fn (array $rule): bool => empty($rule['requiresPublishedDestination'])
                            || _stattic_v4_entry($versionDir, $root, $rule['destination']) !== null
                            || ($hasPages && _stattic_page_resolve($versionDir, $rule['destination']) !== null)
                    ));
                }
                _stattic_apply_redirects(
                    $contentRedirects,
                    $serving,
                    static fn (): bool => false,
                    $requestHost,
                    $requestPath,
                    $requestMethod,
                    false,
                    true
                );
            }
        }
    }

    // 3. Legacy `pages.php` document/client routes.
    if ($page !== null) {
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            _stattic_method_decline(['GET', 'HEAD']);
            _stattic_render_method_declined_405_if_any();
        }
        if (($page['render'] ?? null) === 'document') {
            require_once __DIR__ . '/content/content-page.php';
            if (_stattic_wordpress_page_try_serve($sendContext, $requestPath, $requestMethod, $page)) {
                return true;
            }
        } elseif (($page['render'] ?? null) === 'client' && is_string($page['shell'] ?? null)) {
            $shell = _stattic_v4_entry($versionDir, $root, '/' . ltrim($page['shell'], '/'));
            if (is_array($shell)) {
                _stattic_v4_send_entry($sendContext, $shell, $requestPath);
            }
        }
    }

    // 4. Legacy WordPress fallback routes (`wordpress-routes.json`).
    if ($page === null && is_file($privateRoot . '/spaces/' . $spaceId . '/wordpress-routes.json')) {
        require_once __DIR__ . '/content/content-page.php';
        if (_stattic_wordpress_fallback_try_serve($sendContext, $requestPath, $requestMethod)) {
            return true;
        }
    }

    return false;
}
