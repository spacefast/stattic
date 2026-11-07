<?php

declare(strict_types=1);

namespace Zero\Wp;

/**
 * A statement parameter for a binary column (`VARBINARY`): compared byte for byte on every database.
 * mysqli binds it as the string it is; the SQLite driver gets it as a blob literal, since the plugin
 * stores VARBINARY as BLOB and a text literal never equals one.
 */
final class Bytes
{
    public function __construct(public readonly string $value)
    {
    }
}
