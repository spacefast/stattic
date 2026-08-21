<?php
declare(strict_types=1);

// PHP's built-in server does not execute auto_prepend_file before its
// router script. Load the CI bootstrap explicitly so each request starts and
// flushes PCOV; require_once keeps PHP versions that do prepend it from
// registering the collector twice.
$coverageBootstrap = getenv('SPACEFAST_PHP_COVERAGE_BOOTSTRAP');
if (is_string($coverageBootstrap) && $coverageBootstrap !== '') {
    require_once $coverageBootstrap;
}
unset($coverageBootstrap);

$root = realpath((string) getcwd());
require_once __DIR__ . '/atomic-prepend.php';

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$scriptPath = preg_match('#^(/__spacefast/.+?\.php)(?:/|$)#', $requestPath, $matches) === 1
    ? $matches[1]
    : $requestPath;
$target = is_string($root) ? realpath($root . $scriptPath) : false;

if (
    is_string($root)
    && is_string($target)
    && str_starts_with($target, $root . DIRECTORY_SEPARATOR)
    && is_file($target)
    && str_ends_with($target, '.php')
) {
    // wp.cloud evaluates custom-redirects.php ahead of direct PHP execution;
    // it returns only for SPACEFAST_RUNTIME_ENTRYPOINT_PATHS and otherwise
    // hands the request to the engine (which exits). Emulating that here means
    // every test exercises the same gate production requests hit.
    // Nothing pre-loads bootstrap-config here, exactly as on wp.cloud: the
    // three entrypoints that need it require it themselves (and it self-latches
    // on include), while health.php deliberately does not — it is the public
    // no-auth path monitors poll most often and must not pay the
    // persistent-data decrypt.
    require $root . '/custom-redirects.php';
    require $target;
    return true;
}

$documentRoot = is_string($root) ? $root : (string) getcwd();
require $documentRoot . '/custom-redirects.php';
// wp.cloud continues to the selected front-controller script after its
// auto-prepend returns. The built-in server stops at this router instead, so
// execute the same index.php target explicitly after the gate defers.
require $documentRoot . '/index.php';
return true;
