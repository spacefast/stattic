<?php
declare(strict_types=1);

function _spacefast_runtime_define_bootstrap_config(string $key, mixed $value): void
{
    if (defined($key) || preg_match('/\ASPACEFAST_[A-Z0-9_]+\z/', $key) !== 1) {
        return;
    }
    if (str_starts_with($key, 'SPACEFAST_RUNTIME_ENGINE_')) {
        return;
    }
    if (!is_string($value) || trim($value) === '') {
        return;
    }
    define($key, trim($value));
}

function _spacefast_runtime_bootstrap_config(): void
{
    static $bootstrapped = false;
    if ($bootstrapped) {
        return;
    }
    $bootstrapped = true;

    if (!class_exists('Atomic_Persistent_Data')) {
        return;
    }

    try {
        $persistent = new Atomic_Persistent_Data();
        foreach ($persistent as $key => $value) {
            if (is_string($key)) {
                _spacefast_runtime_define_bootstrap_config($key, $value);
            }
        }
    } catch (Throwable) {
        return;
    }
}

_spacefast_runtime_bootstrap_config();
