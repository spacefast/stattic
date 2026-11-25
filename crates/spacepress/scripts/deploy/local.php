<?php
/** CLI entry point; no HTTP route or WordPress bootstrap. */
declare(strict_types=1);

$embedded = is_file(__DIR__ . '/Artifact.php');
$source = $embedded ? __DIR__ : dirname(__DIR__, 2) . '/runtime/SpacePress/Deployment';
$shared = $embedded ? __DIR__ : dirname($source);
require_once $shared . '/Json.php';
// Artifact::inspect asks Env which names are host settings an app can never read.
require_once $shared . '/Env.php';
require_once $source . '/Artifact.php';
require_once $source . '/Local.php';
exit(\SpacePress\Deployment\Local::main(array_slice($argv, 1)));
