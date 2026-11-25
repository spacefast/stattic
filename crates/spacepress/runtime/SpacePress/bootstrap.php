<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'SpacePress\\Vendor\\') || str_starts_with($class, 'SpacePress_Vendor_')) {
        static $vendor = null;
        $vendor ??= require __DIR__ . '/vendor-prefixed/autoload.php';
        $vendor->loadClass($class);

        return;
    }
    if (str_starts_with($class, 'SpacePress\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('SpacePress\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Db.php also defines the function SpacePress\db(); functions cannot be autoloaded.
require_once __DIR__ . '/Db.php';

\SpacePress\Features::register();
