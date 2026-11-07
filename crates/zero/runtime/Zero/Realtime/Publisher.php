<?php

declare(strict_types=1);

namespace Zero\Realtime;

/**
 * A host's change-notice transport (Cast, Pusher, a WebSocket relay, ...). Zero ships none.
 *
 * `publish` runs after the outermost commit with one notice for the whole transaction:
 *
 *     ['id' => string, 'at' => ISO-8601, 'changes' => list<['collection' => string,
 *       'ops' => list<'create'|'update'|'delete'>, 'ids' => list<int|string>|null]>]
 *
 * `ids` is null when a collection changed too many rows to list. A notice never carries row
 * values. Keep `publish` fast and bounded: it runs on the request that wrote.
 */
interface Publisher
{
    /** @param array{id: string, at: string, changes: list<array{collection: string, ops: list<string>, ids: ?list<int|string>}>} $notice */
    public function publish(array $notice): void;
}
