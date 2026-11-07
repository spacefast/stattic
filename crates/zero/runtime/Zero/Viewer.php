<?php

declare(strict_types=1);

namespace Zero;

/** Who is asking (§5.3). Reads/writes with a Viewer are access-checked; a null Viewer is trusted. */
final class Viewer
{
    /** @param ?array{id: int, name: string, role: string} $user */
    public function __construct(public readonly ?array $user)
    {
    }
}
