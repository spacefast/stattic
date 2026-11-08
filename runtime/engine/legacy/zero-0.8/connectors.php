<?php
declare(strict_types=1);

// Versions finalized before connector roles moved out of the Zero capsule keep
// their credential in `zero/config.json`, and their bundled Zero 0.8 client
// manages visitor accounts under `/__spacefast/zero/connectors/<role>`. These
// keep those versions serving with no republish.

const STATTIC_LEGACY_ZERO_CONNECTOR_PATHS = [
    ['path' => STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/connectors/', 'match' => 'prefix', 'admit' => '_stattic_legacy_zero_connectors_route', 'visitor' => true, 'tenant' => false, 'stage' => 'entry', 'handler' => 'connectors'],
];

/** The credential an older finalize wrote into the capsule config; '' when it has none. */
function _stattic_legacy_zero_connectors_token(string $versionRoot): string
{
    $path = $versionRoot . '/zero/config.json';
    $raw = is_file($path) ? file_get_contents($path) : false;
    $config = is_string($raw) ? json_decode($raw, true) : null;
    $token = is_array($config) ? ($config['connectors']['token'] ?? null) : null;
    return is_string($token) ? trim($token) : '';
}

/**
 * The old visitor account routes as the operations of the new route:
 * `<role>/{connect,callback,disconnect}`, and the bare `<role>` the old
 * client's disconnect() sends as a DELETE.
 *
 * @return array{role: string, operation: string, methods?: list<string>}|null
 */
function _stattic_legacy_zero_connectors_route(string $path): ?array
{
    if (preg_match('~\A' . STATTIC_RUNTIME_NAMESPACE_PATH . '/zero/connectors/([a-z][a-zA-Z0-9]{0,63})(?:/(connect|callback|disconnect))?\z~D', $path, $matches) !== 1) {
        return null;
    }
    return isset($matches[2])
        ? ['role' => $matches[1], 'operation' => $matches[2]]
        : ['role' => $matches[1], 'operation' => 'disconnect', 'methods' => ['DELETE']];
}
