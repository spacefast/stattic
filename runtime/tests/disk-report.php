<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/engine/admin/tier.php';

$privateRoot = $argv[1];
$spaceRoot = $privateRoot . '/spaces/spc_report';
mkdir($spaceRoot . '/nested', 0777, true);
file_put_contents($spaceRoot . '/a', 'one');
file_put_contents($spaceRoot . '/nested/b', 'seven!!');
link($spaceRoot . '/a', $spaceRoot . '/nested/c');
mkdir($spaceRoot . "/\x80-dir");
file_put_contents($spaceRoot . "/\x80-dir/\xff-a", 'raw');
file_put_contents($spaceRoot . "/\x80-dir/\xff-b", 'four');
$checkpoint = $privateRoot . '/runtime/disk-reports/spc_report.json';
$passes = [];
for ($attempt = 0; $attempt < 20; $attempt++) {
    $clock = 0;
    $pass = _stattic_tier_space_disk_usage($spaceRoot, 3.0, $checkpoint, static function () use (&$clock): float {
        return (float) ++$clock;
    });
    $passes[] = $pass;
    if ($pass['complete']) {
        break;
    }
}
$valid = [
    'version' => 1,
    'root' => base64_encode($spaceRoot),
    'stack' => [['path' => '', 'after' => '']],
    'seen' => [],
    'bytes' => 0,
    'inodes' => 0,
];
$invalid = [
    'corrupt_json' => '{broken',
    'missing_stack' => array_diff_key($valid, ['stack' => true]),
    'wrong_root' => array_replace($valid, ['root' => base64_encode($privateRoot)]),
    'bad_frame' => array_replace($valid, ['stack' => [['path' => '', 'after' => []]]]),
    'bad_seen' => array_replace($valid, ['seen' => [1 => [2 => 'yes']]]),
    'bad_bytes' => array_replace($valid, ['bytes' => -1]),
    'bad_inodes' => array_replace($valid, ['inodes' => '0']),
    'unsafe_path' => array_replace($valid, ['stack' => [
        ['path' => '', 'after' => ''],
        ['path' => base64_encode('../outside'), 'after' => ''],
    ]]),
];
$recovered = [];
foreach ($invalid as $name => $value) {
    file_put_contents($checkpoint, is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR));
    $recovered[$name] = _stattic_tier_space_disk_usage($spaceRoot, null, $checkpoint);
    if (is_file($checkpoint)) throw new RuntimeException('Invalid checkpoint was retained: ' . $name);
}
echo json_encode(['passes' => $passes, 'checkpointExists' => is_file($checkpoint), 'recovered' => $recovered], JSON_THROW_ON_ERROR);
