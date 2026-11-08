<?php

declare(strict_types=1);

namespace Zero;

/** A store-owned row policy. Values bind as parameters; {a} is the current source alias. */
final class SqlPredicate
{
    /** @param list<int|float|string|null> $params */
    public function __construct(public readonly string $sql, public readonly array $params = [])
    {
    }
}
