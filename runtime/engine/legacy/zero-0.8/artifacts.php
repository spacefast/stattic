<?php
declare(strict_types=1);

/**
 * The execution mode an endpoint from before the execution law never declared.
 * `admin/generate.php` stamps exactly this derivation onto an entry that
 * arrives without one. The serve path applies the same rule, because a capsule
 * published before the law is immutable and both paths read the same bytes.
 */
function _stattic_zero_derived_endpoint_execution_mode(string $method): string
{
    return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true) ? 'read' : 'write';
}

/**
 * The execution lanes a run id is allowed to declare.
 *
 * A run's operation and its lane are one fact named twice: the client sends
 * `query.run`, `query.subscribe`, `mutation.run` or `action.run`, and the
 * engine derives the run id from that operation. A query is a read-only
 * snapshot; a mutation is the write transaction the cookie mutation gate
 * guards; an action owns no transaction — or, for a capsule published before
 * the split, the write lane every run used to share. An id with none of those
 * prefixes is undispatchable and gets no lane at all.
 */
function _stattic_zero_run_execution_modes(string $runId): array
{
    return match (true) {
        str_starts_with($runId, 'query_') => ['read'],
        str_starts_with($runId, 'mutation_') => ['write'],
        str_starts_with($runId, 'action_') => ['action', 'write'],
        default => [],
    };
}

/**
 * The lane a run from before the execution law always had: a query was already
 * confined to the read-only snapshot, and everything else shared the one write
 * transaction. `admin/generate.php` stamps this onto a run that arrives with no
 * mode, and the serve path falls back to it for an artifact that carries none.
 */
function _stattic_zero_derived_run_execution_mode(string $runId): string
{
    return str_starts_with($runId, 'query_') ? 'read' : 'write';
}

function _stattic_zero_route_entry_shape_valid(mixed $entry): bool
{
    return is_array($entry)
        && is_string($entry['method'] ?? null)
        && in_array($entry['method'], STATTIC_VISITOR_METHODS, true)
        && is_string($entry['pattern'] ?? null)
        && $entry['pattern'] !== ''
        && $entry['pattern'][0] === '/'
        && is_string($entry['endpoint_id'] ?? null)
        && $entry['endpoint_id'] !== ''
        // A routes artifact compiled before the execution law carries no mode.
        // Absent is valid and the resolver derives it; a stale entry must not
        // fail the shape check, because that verdict condemns the whole bucket
        // — every other route in it included.
        && (!array_key_exists('execution_mode', $entry)
            || in_array($entry['execution_mode'], ['read', 'write'], true))
        && is_string($entry['artifact'] ?? null)
        && _stattic_runtime_relative_artifact_path_valid($entry['artifact'])
        && (!array_key_exists('schema_hash', $entry) || is_string($entry['schema_hash']) || $entry['schema_hash'] === null);
}

function _stattic_zero_debug_message(string $message, mixed $detail): string
{
    if (_stattic_config_value('SPACEFAST_ZERO_RUNNER_DEBUG') !== '1' || !is_string($detail) || $detail === '') {
        return $message;
    }

    return $message . ' ' . substr($detail, 0, 2048);
}

function _stattic_zero_runtime_config(string $versionRoot): array
{
    $decoded = _stattic_runtime_read_json($versionRoot . '/' . STATTIC_ZERO_CONFIG_PATH);
    return is_array($decoded) ? $decoded : [];
}

const STATTIC_ZERO_CONFIG_PATH = 'zero/config.json';
