<?php

declare(strict_types=1);

namespace SpacePress;

use SpacePress\Jobs\Jobs;

/**
 * `connectors` from "spacepress" / "spacepress": host-managed connectors (such as a Spacefast
 * Connector), reached through the `sp_connectors_call` WordPress filter.
 *
 * - `connectors.call(role, tool, args?)` runs the connector now and returns its result.
 * - `connectors.enqueue(role, tool, args?, { key?, delay? })` queues the call as the built-in
 *   `spacepress.connector` job in the caller's transaction. A rollback queues nothing; after commit
 *   the job runner makes the call outside any transaction, so no database lock is held across the
 *   connector's HTTP request. A failed call is retried with the runner's backoff, and a `key`
 *   keeps one queued call per key.
 *
 * The filter receives `(null, $role, $tool, $args, $context)` (register it with 5 accepted args to
 * see `$context`). `$context` is `['mode' => 'call']`, or `['mode' => 'queued', 'jobId' => …,
 * 'attempt' => …]` for a queued call. A queued call runs at least once and may repeat (a crash after
 * the call, before the job is marked done), so a host de-duplicates writes by `jobId`. Returning a
 * `WP_Error` or throwing fails the call; a queued call that gets `null` back was handled by no
 * connector and is retried too.
 *
 * Credentials and authorization stay owned by the host; compiled app code never handles raw tokens.
 */
final class Connectors
{
    public const JOB = 'spacepress.connector';
    private const RETRIES = 8;

    /**
     * Invoke a host connector tool for a bound role.
     *
     * @param string $role Connector role name declared by the app / Space (e.g. "slack", "github").
     * @param string $tool Tool or operation name on that connector.
     * @param mixed $args Optional JSON-compatible arguments object passed to the connector.
     */
    public static function call(string $role, string $tool, mixed $args = null): mixed
    {
        [$role, $tool, $payload] = self::request($role, $tool, $args);
        $result = self::invoke($role, $tool, $payload, ['mode' => 'call']);
        if ($result instanceof \WP_Error) {
            $data = $result->get_error_data();
            $status = is_array($data) && is_int($data['status'] ?? null) ? $data['status'] : 502;
            $code = (string) ($result->get_error_code() ?: 'connector_error');
            throw new HttpError($status, $code, $result->get_error_message() ?: 'Connector call failed.');
        }

        return Seam::toJs($result, ['type' => 'json']);
    }

    /**
     * Queue a connector call in the caller's transaction; it runs after commit.
     *
     * @param array{key?: string, delay?: int|float}|JsObject|null $opts
     * @return JsObject `{ id }` of the queued job
     */
    public static function enqueue(string $role, string $tool, mixed $args = null, array|JsObject|null $opts = null): JsObject
    {
        [$role, $tool, $payload] = self::request($role, $tool, $args);
        $opts = $opts === null ? [] : Seam::toPhp($opts);
        if (!is_array($opts)) {
            throw HttpError::invalidInput('Connector options must be an object.', '/opts');
        }
        foreach (array_keys($opts) as $name) {
            if (!in_array($name, ['key', 'delay'], true)) {
                throw HttpError::invalidInput("Unknown connector option $name.", '/opts/' . $name);
            }
        }
        // `{ key: undefined }` is an option left out.
        $opts = array_filter($opts, static fn (mixed $value): bool => $value !== null && $value !== JsUndefined::Value);
        $job = Jobs::enqueue(self::JOB, ['role' => $role, 'tool' => $tool, 'args' => $payload], $opts);

        return new JsObject(['id' => $job['id']]);
    }

    public static function spec(): array
    {
        return [
            'input' => [
                'role' => ['type' => 'text', 'notNull' => true],
                'tool' => ['type' => 'text', 'notNull' => true],
                'args' => ['type' => 'json', 'notNull' => true],
            ],
            'handler' => self::deliver(...),
            'retries' => self::RETRIES,
            'agents' => false,
        ];
    }

    /** The job handler: one call; a failure throws so the runner backs off and retries. */
    public static function deliver(array $input, array $ctx): void
    {
        // json() job input arrives with JavaScript object semantics; the host reads plain arrays.
        $args = json_decode((string) Js::jsonStringify($input['args'] ?? null), true);
        $role = $input['role'] ?? null;
        $tool = $input['tool'] ?? null;
        if (!is_string($role) || !is_string($tool) || !is_array($args)) {
            throw new \RuntimeException('Queued connector call is missing');
        }
        $result = self::invoke($role, $tool, $args, ['mode' => 'queued', 'jobId' => $ctx['jobId'] ?? null, 'attempt' => $ctx['attempt'] ?? null]);
        // A filter that passes the null through handled nothing: retry rather than mark the call done.
        if ($result === null) {
            throw new \RuntimeException('No connector answered ' . $role . ':' . $tool . ' (sp_connectors_call returned null)');
        }
        if ($result instanceof \WP_Error) {
            throw new \RuntimeException('Connector ' . $role . ':' . $tool . ' failed: ' . ($result->get_error_message() ?: $result->get_error_code()));
        }
    }

    /** @return array{string, string, array} the trimmed role and tool, and the arguments as a PHP array */
    private static function request(string $role, string $tool, mixed $args): array
    {
        $role = trim($role);
        $tool = trim($tool);
        if ($role === '') {
            throw HttpError::invalidInput('Connector role must be a non-empty string.', '/role');
        }
        if ($tool === '') {
            throw HttpError::invalidInput('Connector tool must be a non-empty string.', '/tool');
        }
        if (!self::connected()) {
            throw new HttpError(503, 'connectors_unavailable', 'This host has not connected a connectors transport (sp_connectors_call).');
        }
        $payload = [];
        if ($args !== null && $args !== JsUndefined::Value) {
            $converted = Seam::toPhp($args);
            if (!is_array($converted)) {
                throw HttpError::invalidInput('Connector arguments must be an object.', '/args');
            }
            $payload = self::withoutUndefined($converted);
        }

        return [$role, $tool, $payload];
    }

    private static function invoke(string $role, string $tool, array $args, array $context): mixed
    {
        if (!self::connected()) {
            throw new HttpError(503, 'connectors_unavailable', 'This host has not connected a connectors transport (sp_connectors_call).');
        }

        return apply_filters('sp_connectors_call', null, $role, $tool, $args, $context);
    }

    /** A value with every field that holds JavaScript's undefined removed, at any depth (as JSON would). */
    private static function withoutUndefined(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if ($item === JsUndefined::Value) {
                continue;
            }
            $out[$key] = is_array($item) ? self::withoutUndefined($item) : $item;
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    private static function connected(): bool
    {
        return function_exists('has_filter') && has_filter('sp_connectors_call') !== false;
    }
}
