<?php
/** CLI entry point; no HTTP route or WordPress bootstrap. */
declare(strict_types=1);

$source = is_file(__DIR__ . '/Local.php') ? __DIR__ : dirname(__DIR__, 2) . '/runtime/Zero/Deployment';
require_once (is_file(__DIR__ . '/Local.php') ? __DIR__ : dirname($source)) . '/Json.php';
require_once $source . '/Artifact.php';
require_once $source . '/Local.php';
exit(\Zero\Deployment\Local::main(array_slice($argv, 1)));
