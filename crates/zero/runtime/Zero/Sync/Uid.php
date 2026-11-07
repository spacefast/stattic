<?php

declare(strict_types=1);

namespace Zero\Sync;

/** Stable record identity across sites and files: `zr_` + 20 lowercase hex (§5.3). */
final class Uid
{
    public const PATTERN = '/^zr_[0-9a-f]{20}$/D';

    public static function mint(): string
    {
        return 'zr_' . substr(hash('sha256', random_bytes(16)), 0, 20);
    }

    public static function forFile(string $collection, string $basename): string
    {
        return 'zr_' . substr(hash('sha256', "zero-sync-file\0" . $collection . "\0" . $basename), 0, 20);
    }

    public static function valid(mixed $uid): bool
    {
        return is_string($uid) && preg_match(self::PATTERN, $uid) === 1;
    }
}
