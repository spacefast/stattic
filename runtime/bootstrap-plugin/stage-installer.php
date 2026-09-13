<?php
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$names = ['installer.php', 'spacefast-bootstrap.php', 'restore-config.php'];
$files = $input['files'];
$hashes = '';
foreach ($names as $name) {
    if (!is_string($files[$name] ?? null)) throw new RuntimeException('installer_source_missing');
    $hashes .= hash('sha256', $files[$name]);
}
$digest = hash('sha256', $hashes);
if (!hash_equals($input['digest'], $digest)) throw new RuntimeException('installer_source_mismatch');
foreach (['.stattic', '.stattic/installers'] as $root) {
    if (is_link($root)) throw new RuntimeException('installer_directory_symlink');
    if (!is_dir($root) && !mkdir($root, 0755) && !is_dir($root)) throw new RuntimeException('installer_directory_failed');
}
$root = '.stattic/installers/' . $digest;
if (is_link($root)) throw new RuntimeException('installer_directory_symlink');
if (!is_dir($root) && !mkdir($root, 0755) && !is_dir($root)) throw new RuntimeException('installer_directory_failed');
foreach ($names as $name) {
    $target = $root . '/' . $name;
    if (!file_exists($target) && !is_link($target)) {
        $tmp = tempnam($root, '.stage-');
        if (!is_string($tmp)) throw new RuntimeException('installer_stage_failed');
        try {
            if (file_put_contents($tmp, $files[$name]) !== strlen($files[$name]) || !chmod($tmp, 0444)) throw new RuntimeException('installer_stage_failed');
            if (!file_exists($target) && !link($tmp, $target) && !file_exists($target)) throw new RuntimeException('installer_publish_failed');
        } finally { unlink($tmp); }
    }
    if (is_link($target) || !is_file($target) || !hash_equals(hash('sha256', $files[$name]), hash_file('sha256', $target))) throw new RuntimeException('installer_existing_mismatch');
}
echo json_encode(['digest' => $digest, 'config_present' => is_file('.stattic/storage/config.php') && !is_link('.stattic/storage/config.php')]);