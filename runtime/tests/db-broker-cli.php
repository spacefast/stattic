<?php
declare(strict_types=1);

// CLI entry point for driving shared/db-broker.php from the bun test runner,
// mirroring s3-cli.php and subprocess-cli.php. The broker has no HTTP surface of
// its own, so the manifest-driven startRuntime() harness has nothing to hit.
//
// Protocol: argv[1] is a JSON request object, stdout is one JSON response line.
// Operation responses are raw text, never re-encoded, because the differential
// test compares bytes against the Rust engine's output.
require_once __DIR__ . '/../engine/shared/context.php';
require_once __DIR__ . '/../engine/shared/db-broker.php';

$request = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($request)) {
    fwrite(STDERR, "invalid_request_json\n");
    exit(2);
}

if (is_string($request['url'] ?? null)) {
    _stattic_db_broker_bind($request['url'], $request['source'] ?? null);
}
if (is_int($request['readDeadlineMs'] ?? null)) {
    _stattic_db_broker_set_read_deadline_ms($request['readDeadlineMs']);
}
if (is_array($request['capabilities'] ?? null)) {
    _stattic_db_broker_grant($request['capabilities']);
}

$out = [];

switch ((string) ($request['action'] ?? 'operations')) {
    case 'operations':
        // Each entry is raw operation JSON text, executed in order in one
        // process so transaction and connection-reuse behaviour is observable.
        $responses = [];
        foreach ($request['operations'] ?? [] as $operation) {
            $responses[] = _stattic_db_broker_execute((string) $operation);
        }
        $out['responses'] = $responses;
        $out['metrics'] = _stattic_db_broker_take_metrics();
        break;

    case 'migrate':
        // Applies one migrations.json the way generate.php does at publish, so
        // artifact validation, replay tolerance and a real failure are all
        // observable from the test.
        $out['migrate'] = _stattic_db_broker_apply_migrations((string) ($request['path'] ?? ''));
        break;

    case 'abandon_transaction':
        // Opens a transaction, writes, and leaves without committing. The
        // `hard` variant dies before PHP's shutdown functions run, which only
        // the pooled connection's reuse reset can clean up.
        foreach ($request['operations'] ?? [] as $operation) {
            $responses[] = _stattic_db_broker_execute((string) $operation);
        }
        if (($request['hard'] ?? false) === true) {
            echo json_encode(['abandoned' => 'hard']) . "\n";
            // SIGKILL to self: no shutdown functions, no destructors, no
            // ROLLBACK from this process.
            posix_kill(posix_getpid(), SIGKILL);
        }
        $out['abandoned'] = 'soft';
        break;

    default:
        fwrite(STDERR, "unknown_action\n");
        exit(2);
}

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
