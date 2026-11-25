<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    static $polyfills = null;
    $polyfills ??= require dirname(__DIR__) . '/vendor-prefixed/composer/polyfill_classes.php';
    if (isset($polyfills[$class]) || str_starts_with($class, "SpacePress\\Identity\\Vendor\\")) {
        static $vendor = null;
        $vendor ??= require dirname(__DIR__) . "/vendor-prefixed/autoload.php";
        $vendor->loadClass($class);
    } elseif (str_starts_with($class, "SpacePress\\Identity\\")) {
        $file = __DIR__ . "/" . str_replace("\\", "/", substr($class, strlen("SpacePress\\Identity\\"))) . ".php";
        if (is_file($file)) {
            require $file;
        }
    }
});
// Keep Composer's function and PHP polyfills available to every WordPress plugin.
(static function (): void {
    require dirname(__DIR__) . '/vendor-prefixed/composer/platform_check.php';
    foreach (require dirname(__DIR__) . '/vendor-prefixed/composer/autoload_files.php' as $id => $file) {
        if (empty($GLOBALS['__sp_identity_vendor_autoload_files'][$id])) {
            $GLOBALS['__sp_identity_vendor_autoload_files'][$id] = true;
            require $file;
        }
    }
})();
