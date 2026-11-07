<?php

declare(strict_types=1);

namespace Zero\Wp;

/**
 * A write-only password travelling through the Db pipeline to WpUserStore. The pipeline carries
 * an opaque marker in `passwordHash`; the plaintext stays here, in memory, for one request, and
 * never reaches a row, a hook argument that persists, or a response.
 */
final class PendingPassword
{
    /** @var array<string, string> marker → plaintext */
    private static array $pending = [];

    public static function put(string $password): string
    {
        $marker = 'zero-pending:' . bin2hex(random_bytes(16));
        self::$pending[$marker] = $password;

        return $marker;
    }

    public static function take(mixed $marker): ?string
    {
        if (!is_string($marker) || !isset(self::$pending[$marker])) {
            return null;
        }
        $password = self::$pending[$marker];
        unset(self::$pending[$marker]);

        return $password;
    }
}
