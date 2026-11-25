<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * 409 `stale_revision`: the item changed since the caller read it ({@see Rev}). The problem document
 * carries the item's current `_rev` and what changed: `fields` as the caller may read them (null when
 * there is no revision to compare with), and `staged` for a staged version's token.
 */
final class StaleRevision extends \SpacePress\HttpError
{
    /** @param array{fields: ?list<string>, staged?: bool} $changed */
    public function __construct(public readonly string $current, public readonly array $changed)
    {
        parent::__construct(409, 'stale_revision', 'This item changed since you read it; read it again and retry with its new _rev', '/' . Rev::FIELD);
    }

    /** @return array{_rev: string, changed: array} the problem document's extra members */
    public function details(): array
    {
        return [Rev::FIELD => $this->current, 'changed' => $this->changed];
    }
}
