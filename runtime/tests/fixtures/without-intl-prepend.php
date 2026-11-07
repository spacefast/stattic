<?php
declare(strict_types=1);

// Run with disable_functions=class_exists to exercise the supported no-intl
// branch even when the local PHP binary has Normalizer compiled in.
if (!function_exists('class_exists')) {
    function class_exists(string $class, bool $autoload = true): bool
    {
        if (strcasecmp(ltrim($class, '\\'), 'Normalizer') === 0) {
            return false;
        }
        try {
            new ReflectionClass($class);
            return true;
        } catch (ReflectionException) {
            return false;
        }
    }
}

require dirname(__DIR__) . '/atomic-prepend.php';
