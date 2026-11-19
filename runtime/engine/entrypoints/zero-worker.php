<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../runtime/cli-invoke.php';
require_once __DIR__ . '/../runtime/zero-v2-worker.php';
$flags = _stattic_cli_flags($argv)['flags'];
$root = _stattic_cli_private_root(dirname(__DIR__), $flags['private-root'] ?? '');
$environment = $flags['environment'] ?? 'production';
if ($environment !== 'production') _stattic_cli_fail('Only the production environment is currently supported.');
$budget = max(1, min(50000, (int) ($flags['budget-ms'] ?? 45000)));
$deadline = microtime(true) + $budget / 1000;
$selected = $flags['space'] ?? null;
if (is_string($selected) && (!_stattic_id_valid($selected) || !is_dir($root . '/spaces/' . $selected))) _stattic_cli_fail('Unknown worker Space.');
// A box holds one Space; a local fixture may hold more, each ticked in turn.
$spaces = $selected === null ? array_values(array_filter(scandir($root . '/spaces') ?: [], static fn (string $id): bool => _stattic_id_valid($id) && is_dir($root . '/spaces/' . $id))) : [$selected];
$failed = false;
foreach ($spaces as $space) {
    $remaining = (int) (($deadline - microtime(true)) * 1000);
    if ($remaining <= 0) break;
    $result = _stattic_zero_worker_run($root, $space, $environment, $remaining);
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    $failed = $failed || ($result['failures'] ?? 0) > 0;
}
exit($failed ? 1 : 0);
