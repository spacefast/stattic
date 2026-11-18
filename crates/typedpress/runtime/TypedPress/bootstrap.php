<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'TypedPress\\Vendor\\') || str_starts_with($class, 'TypedPress_Vendor_')) {
        static $vendor = null;
        $vendor ??= require __DIR__ . '/vendor-prefixed/autoload.php';
        $vendor->loadClass($class);

        return;
    }
    if (str_starts_with($class, 'TypedPress\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('TypedPress\\'))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Db.php also defines the function TypedPress\db(); functions cannot be autoloaded.
require_once __DIR__ . '/Db.php';

\TypedPress\Features::register();
