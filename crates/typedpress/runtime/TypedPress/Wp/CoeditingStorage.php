<?php

declare(strict_types=1);

namespace TypedPress\Wp;

/** Keep each schema's opaque updates in its own upstream storage room. */
final class CoeditingStorage implements \WP_Sync_Storage
{
    public function __construct(private readonly \WP_Sync_Storage $storage)
    {
    }

    public function add_update(string $room, $update): bool
    {
        return $this->storage->add_update(Coediting::storageRoom($room), $update);
    }

    public function get_awareness_state(string $room): array
    {
        return $this->storage->get_awareness_state(Coediting::storageRoom($room));
    }

    public function get_cursor(string $room): int
    {
        return $this->storage->get_cursor(Coediting::storageRoom($room));
    }

    public function get_update_count(string $room): int
    {
        return $this->storage->get_update_count(Coediting::storageRoom($room));
    }

    public function get_updates_after_cursor(string $room, int $cursor): array
    {
        return $this->storage->get_updates_after_cursor(Coediting::storageRoom($room), $cursor);
    }

    public function remove_updates_before_cursor(string $room, int $cursor): bool
    {
        return $this->storage->remove_updates_before_cursor(Coediting::storageRoom($room), $cursor);
    }

    public function set_awareness_state(string $room, array $awareness): bool
    {
        return $this->storage->set_awareness_state(Coediting::storageRoom($room), $awareness);
    }
}
