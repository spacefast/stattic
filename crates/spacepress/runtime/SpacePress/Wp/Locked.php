<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * 409 `locked`: another user holds the item's edit lock (core's `_edit_lock`, {@see Rev::lock()}).
 * The problem document names the holder. Reading the item again does not clear it; wait, or send
 * `takeover: true`.
 */
final class Locked extends \SpacePress\HttpError
{
    public function __construct(public readonly int $holderId, public readonly string $holder)
    {
        parent::__construct(409, 'locked', ($holder === '' ? 'Another user' : $holder) . ' is editing this item; retry later, or send takeover: true to take over');
    }

    /** @return array{holder: array{id: int, name: string}} the problem document's extra member */
    public function details(): array
    {
        return ['holder' => ['id' => $this->holderId, 'name' => $this->holder]];
    }
}
