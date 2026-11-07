<?php

declare(strict_types=1);

namespace Zero;

/** Runtime variables, isolated to the host's explicit values for managed apps. */
final class Env
{
    /** @var array<string, string>|null Null retains standalone environment lookup. */
    private static ?array $variables = null;

    /** Bind a managed app before loading its schema or booting its runtime. */
    public static function isolate(array $variables): void
    {
        self::$variables = [];
        foreach ($variables as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new \InvalidArgumentException('Runtime variables must map names to strings');
            }
        }
        self::$variables = $variables;
    }

    public static function get(string $name): ?string
    {
        if (self::$variables !== null) {
            return self::$variables[$name] ?? null;
        }
        $value = getenv($name);
        if ($value !== false) {
            return $value;
        }
        if (defined($name)) {
            $constant = constant($name);

            return is_scalar($constant) ? (string) $constant : null;
        }

        return null;
    }

    /** A schema.php value that is either a literal string or `['env' => NAME]`. */
    public static function resolve(mixed $value): ?string
    {
        if (is_array($value) && isset($value['env'])) {
            return self::get((string) $value['env']);
        }

        return is_string($value) ? $value : null;
    }
}
