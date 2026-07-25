<?php
declare(strict_types=1);

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
    if (in_array($scriptPath, [
        '/__spacefast/access-callback.php',
        '/__spacefast/api.php',
        '/__spacefast/health.php',
        '/__spacefast/upload.php',
    ], true)) {
        require $root . '/.stattic/engine/shared/bootstrap-config.php';
    }
    require $target;
    return true;
}

$staticTarget = is_string($root) ? realpath($root . $requestPath) : false;
if (
    is_string($root)
    && is_string($staticTarget)
    && str_starts_with($staticTarget, $root . DIRECTORY_SEPARATOR)
    && is_file($staticTarget)
) {
    return false;
}

require (is_string($root) ? $root : (string) getcwd()) . '/custom-redirects.php';
return true;
