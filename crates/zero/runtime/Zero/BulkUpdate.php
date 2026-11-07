<?php

declare(strict_types=1);

namespace Zero;

/**
 * One open `Db::update` over several rows. A row it hasn't written yet can be written early, once,
 * by Db::updateWithin (translation group sync reaching a sibling this update covers too); the loop
 * then skips it, so each row's write, hooks and revision happen exactly once.
 */
final class BulkUpdate
{
    /** @var array<string, true> rows not written yet, by Db::rowKey() */
    public array $pending;
    /** @var array<string, array> rows written early, by Db::rowKey() */
    public array $done = [];
    /** The row the loop is writing right now, by Db::rowKey(). */
    public ?string $current = null;

    /** @param list<string> $keys */
    public function __construct(
        public readonly string $collection,
        public readonly array $spec,
        public readonly array $set,
        public readonly ?Viewer $viewer,
        array $keys,
        public readonly ?Viewer $errorViewer = null,
    ) {
        $this->pending = array_fill_keys($keys, true);
    }
}
