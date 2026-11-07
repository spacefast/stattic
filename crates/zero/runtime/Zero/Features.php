<?php

declare(strict_types=1);

namespace Zero;

/** The one ordered list of runtime feature modules, registered once per process. */
final class Features
{
    private const MODULES = [Media\Module::class, Sync\Module::class, Abilities\Module::class, Jobs\Module::class, Services\Module::class, FieldAccess::class, Auth\Module::class, Locale\Module::class, Realtime\Module::class];
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        foreach (self::MODULES as $module) {
            $module::register();
        }
        self::$registered = true;
    }
}
