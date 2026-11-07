<?php
declare(strict_types=1);

const STATTIC_ZERO_REALTIME_TICKET_PATH = STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/realtime-ticket';

// The canonical Zero control namespace. `/__spacefast/zero/*` is served forever
// as an alias: frozen capsule clients baked those paths at build time and a
// republish cannot be assumed. Registration covers both prefixes; every
// emission (config JSON, invocation descriptors) uses the canonical one.
const STATTIC_ZERO_CANONICAL_NAMESPACE_PATH = '/__zero';

const STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH = STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/realtime-ticket';

// The namespace is private-by-default: a Zero control route missing from this
// table silently 403s at the front door (init.php).
const STATTIC_ZERO_CONTROL_ROUTES = [
    '__zero/config' => ['operation' => 'config', 'methods' => ['GET', 'HEAD']],
    '__zero/run' => ['operation' => 'run', 'methods' => ['POST']],
    '__zero/auth/start' => ['operation' => 'auth_start', 'methods' => ['GET', 'HEAD']],
    '__zero/auth/sign-out' => ['operation' => 'auth_sign_out', 'methods' => ['GET', 'HEAD', 'POST']],
    '__zero/realtime/events' => ['operation' => 'realtime_events', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/config' => ['operation' => 'config', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/run' => ['operation' => 'run', 'methods' => ['POST']],
    '__spacefast/zero/auth/gravatar/start' => ['operation' => 'auth_start', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/auth/sign-out' => ['operation' => 'auth_sign_out', 'methods' => ['GET', 'HEAD']],
    '__spacefast/zero/realtime/events' => ['operation' => 'realtime_events', 'methods' => ['GET', 'HEAD']],
];

function _stattic_control_path_is_zero_route(string $path): bool
{
    return isset(STATTIC_ZERO_CONTROL_ROUTES[trim(strtolower($path), '/')]) || _stattic_zero_connector_route($path) !== null;
}

// Response tables compiled before the /__zero cutover carry only the legacy
// spellings, and a version is immutable: the serve ladder folds a canonical
// control path to its legacy twin when the table has no canonical row.
function _stattic_zero_legacy_control_path(string $path): ?string
{
    $normalized = '/' . trim(strtolower($path), '/');
    if ($normalized === STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/auth/start') {
        return STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/auth/gravatar/start';
    }
    if (str_starts_with($normalized, STATTIC_ZERO_CANONICAL_NAMESPACE_PATH . '/')) {
        return STATTIC_RUNTIME_NAMESPACE_PATH . '/zero'
            . substr($normalized, strlen(STATTIC_ZERO_CANONICAL_NAMESPACE_PATH));
    }
    return null;
}

const STATTIC_LEGACY_ZERO_TICKET_PATHS = [
    ['path' => STATTIC_ZERO_REALTIME_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
    ['path' => STATTIC_ZERO_CANONICAL_REALTIME_TICKET_PATH, 'match' => 'exact', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'comments_exchange'],
];

const STATTIC_LEGACY_ZERO_CONTROL_PATHS = [
    ['path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'admit' => '_stattic_control_path_is_zero_route', 'visitor' => true, 'tenant' => false, 'stage' => 'route-index', 'handler' => 'invoke_zero'],
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH . '/zero', 'match' => 'namespace', 'fold' => true, 'admit' => '_stattic_control_path_is_zero_route', 'visitor' => true, 'tenant' => false, 'stage' => 'route-index', 'handler' => 'invoke_zero'],
];

const STATTIC_LEGACY_ZERO_PRIVATE_PATHS = [
    ['path' => STATTIC_ZERO_CANONICAL_NAMESPACE_PATH, 'match' => 'namespace', 'fold' => true, 'visitor' => false, 'tenant' => false, 'stage' => null, 'handler' => null],
];

function _stattic_zero_connector_route(string $path): ?array
{
    if (preg_match('~^/__spacefast/zero/connectors/([a-z][a-zA-Z0-9]{0,63})(?:/(connect|callback|disconnect))?$~D', $path, $matches) !== 1) return null;
    return ['role' => $matches[1], 'operation' => $matches[2] ?? 'disconnect'];
}
