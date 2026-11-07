<?php

declare(strict_types=1);

ini_set('precision', '-1');
ini_set('serialize_precision', '-1');
// Warnings must never leak into a JSON response; they go to the error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/vendor-prefixed/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Zero\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Db.php also defines the function Zero\db(); functions cannot be autoloaded.
require_once __DIR__ . '/Db.php';

\Zero\Features::register();
