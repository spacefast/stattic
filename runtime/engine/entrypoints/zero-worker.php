<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../runtime/cli-invoke.php';
require_once __DIR__ . '/../runtime/zero-v2-worker.php';
$flags = _stattic_cli_flags($argv)['flags'];
$root = _stattic_cli_private_root(dirname(__DIR__), $flags['private-root'] ?? '');
$environment = $flags['environment'] ?? 'production';
if ($environment !== 'production') _stattic_cli_fail('Only the production environment is currently supported.');
$limit = max(1, min(100, (int) ($flags['limit'] ?? 25)));
$budget = max(1, min(50000, (int) ($flags['budget-ms'] ?? 45000)));
$deadline = microtime(true) + $budget / 1000;
$selected = $flags['space'] ?? null;
if (is_string($selected) && (!_stattic_id_valid($selected) || !is_dir($root . '/spaces/' . $selected))) _stattic_cli_fail('Unknown worker Space.');
$spaces = $selected === null ? array_values(array_filter(scandir($root . '/spaces') ?: [], static fn (string $id): bool => _stattic_id_valid($id) && is_dir($root . '/spaces/' . $id))) : [$selected];
// A busy Space cannot starve later Spaces when the whole box's time budget runs out.
$cursor = _stattic_runtime_read_json($root . '/runtime/zero-worker-cursor.json')['space_id'] ?? '';
if ($selected === null && is_string($cursor)) {
    $spaces = array_merge(array_values(array_filter($spaces, static fn (string $id): bool => strcmp($id, $cursor) > 0)), array_values(array_filter($spaces, static fn (string $id): bool => strcmp($id, $cursor) <= 0)));
}
$failed = false;
foreach ($spaces as $space) {
    $remaining = (int) (($deadline - microtime(true)) * 1000);
    if ($remaining <= 0) break;
    $result = _stattic_zero_worker_run($root, $space, $environment, $limit, $remaining);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    $failed = $failed || ($result['failures'] ?? 0) > 0;
    if ($selected === null) _stattic_runtime_write_json_atomic($root . '/runtime/zero-worker-cursor.json', ['space_id' => $space]);
}
exit($failed ? 1 : 0);
