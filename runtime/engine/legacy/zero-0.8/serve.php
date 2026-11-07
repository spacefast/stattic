<?php
declare(strict_types=1);

// One stat, cached per version: a Zero page's document response settles the
// visitor's guest identity, and every purely static version has to answer that
// question without paying for a read. `zero/config.json` is the artifact the
// Zero lane itself reads, so its presence is the same "this version HAS Zero"
// the runtime already trusts.
function _stattic_v4_version_has_zero_runtime(string $versionDir): bool
{
    static $cache = [];
    $path = $versionDir . '/zero/config.json';
    return $cache[$path] ??= is_file($path);
}

/**
 * Settle a Zero visitor's anonymous session on the DOCUMENT, before the page can
 * open a single XHR.
 *
 * A Zero client's first render fires auth.get, query.subscribe and mutation.run
 * together. Each of those requests arrives cookie-less, each mints its own
 * anonymous session, and the browser keeps whichever Set-Cookie landed last: the
 * identity the page renders and the identity a mutation ran under disagree.
 * Serializing that in the client cannot fix it for capsules, which bake their
 * client at build time and may never be rebuilt — so the server establishes the
 * session on the one response that provably precedes every XHR.
 *
 * Writing the cookie also marks the response no-store
 * (_stattic_identity_cookie_mutated), so this document never warms a shared
 * cache with one visitor's Set-Cookie. It does NOT make the document private
 * content: the pseudonym carries no authority and the bytes are the public
 * page, so it keeps the publisher's X-Robots-Tag and gains no platform noindex.
 */
function _stattic_zero_prime_anonymous_document_session(
    array $context,
    array $headers,
    int $status,
    string $method
): void {
    if (
        $status !== 200
        || !in_array($method, ['GET', 'HEAD'], true)
        || !str_starts_with(strtolower((string) ($headers['content-type'] ?? '')), 'text/html')
        || !_stattic_v4_version_has_zero_runtime((string) $context['version_dir'])
    ) {
        return;
    }
    require_once __DIR__ . '/../../runtime/access-rules.php';
    _stattic_anonymous_session_ensure_anonymous_id(
        is_array($context['serving'] ?? null) ? $context['serving'] : [],
        (string) $context['host']
    );
}

function _stattic_legacy_zero_dispatch_action(array $context, array $action, string $requestPath): void
{
    $serving = $context['serving'];
    // Control routes carry an operation; exact endpoints carry one handler
    // per method. HEAD uses GET, as in the compiled Zero route index.
    if (array_key_exists('endpoints', $action)) {
        $method = (string) $context['method'];
        $endpoint = $action['endpoints'][$method]
            ?? ($method === 'HEAD' ? ($action['endpoints']['GET'] ?? null) : null);
        if (!is_array($endpoint)) {
            _stattic_render_runtime_invariant_error_lazy('zero-route-metadata-missing', 'Runtime Zero endpoint metadata is missing.');
        }
        $action = $endpoint;
    }
    require_once __DIR__ . '/../../shared/admission.php';
    _stattic_admission_acquire_once((string) $context['private_root'], $serving, 'zero');
    require_once __DIR__ . '/../../runtime/zero.php';
    _stattic_invoke_zero(
        $action,
        (string) $context['version_dir'],
        $serving,
        (string) $context['host'],
        $requestPath,
        (string) $context['request_uri'],
        (string) $context['method']
    );
}

function _stattic_legacy_zero_dispatch_patterns(array $context, string $requestPath, string $requestMethod, string $requestUri): void
{
    $versionDir = (string) $context['version_dir'];
    $lookup = ltrim($requestPath, '/');
    if (!is_file($versionDir . '/zero/routes.php')) return;
    require_once __DIR__ . '/../../runtime/zero-routes.php';
    $result = _stattic_resolve_zero_route_action($versionDir, $lookup, $requestMethod);
    if (!empty($result['method_not_allowed'])) {
        // Zero's resolver reports no per-route Allow, so the recorded set is
        // the default its terminal 405 always advertised. Recording instead
        // of terminating lets a Functions route below still claim the
        // method; the caller renders the union when nothing does.
        _stattic_method_decline(['GET', 'HEAD']);
    }
    if (is_array($result['action'] ?? null)) {
        require_once __DIR__ . '/../../shared/admission.php';
        _stattic_admission_acquire_once((string) $context['private_root'], $context['serving'], 'zero');
        require_once __DIR__ . '/../../runtime/zero.php';
        _stattic_invoke_zero(
            $result['action'],
            $versionDir,
            $context['serving'],
            (string) $context['host'],
            $requestPath,
            $requestUri,
            $requestMethod
        );
    }
}

function _stattic_legacy_zero_control_request(string $versionDir, array $serving, string $requestHost, string $requestPath, string $requestUri, string $requestMethod): void
{
    $authControl = STATTIC_ZERO_CONTROL_ROUTES[trim($requestPath, '/')] ?? null;
    if (is_array($authControl) && in_array($authControl['operation'], ['config', 'auth_start', 'auth_sign_out', 'run'], true)) {
        if (!in_array($requestMethod, $authControl['methods'], true)) _stattic_method_not_allowed(implode(', ', $authControl['methods']));
        require_once __DIR__ . '/../../runtime/zero.php';
        _stattic_invoke_zero($authControl, $versionDir, $serving, $requestHost, $requestPath, $requestUri, $requestMethod);
    }
}

function _stattic_legacy_zero_relay_run(string $body, ?array $visitor, array $identity, string $spaceId, array $config, string $versionRoot, array $serving, string $host): void
{
    require_once __DIR__ . '/../../runtime/zero.php';
    $frame = json_decode($body, true);
    if (!is_array($frame) || !in_array($frame['op'] ?? null, ['query.run', 'mutation.run'], true)) {
        _stattic_problem_refused(400, 'zero_run_operation_unsupported', 'Use query or mutate to call the capsule.');
    }
    if ($visitor === null) {
        $principal = 'service:' . $spaceId;
        $identity = [
            'user' => ['id' => $principal, 'displayName' => 'Worker'],
            'userId' => $principal, 'displayName' => 'Worker', 'provider' => 'service',
            'isGuest' => false, 'isAuthenticated' => true,
        ];
    }
    _stattic_zero_send_run_response($config, $versionRoot, $serving, 'POST', $host, $body, $identity);
}
