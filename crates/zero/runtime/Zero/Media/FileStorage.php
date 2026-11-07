<?php

declare(strict_types=1);

namespace Zero\Media;

/**
 * Private-file metadata and byte persistence. Files owns the rules; WordPress stores rows
 * in `{prefix}zero__files` and bytes in the app's configured upload backend.
 */
interface FileStorage
{
    public function secret(): string;

    /** Null when uploads are safe; otherwise why not (answered as 503 files_unavailable). */
    public function unavailable(): ?string;

    /** Persist $tmpPath under private identity $rel (removed again if the transaction rolls back). */
    public function store(string $tmpPath, string $rel): void;

    /** @param array{bucket: string, filename: string, mime: string, size: int, path: string, ownerId: ?int, createdAt: string} $row */
    public function insert(array $row): int;

    /**
     * @param list<int> $ids
     * @return array<int, array{id: int, bucket: string, filename: string, mime: string, size: int, path: string, ownerId: ?int, createdAt: string}>
     */
    public function getMany(array $ids): array;

    /** Delete the row now and its bytes once the transaction commits. */
    public function delete(array $row): void;

    /** Prepare a bounded download; provider failures must occur before response headers. */
    public function stream(string $rel): \Closure;

    /** Absolute or root-relative URL of an API route ('/files/decks/1/download'). */
    public function apiUrl(string $route): string;

    /** `GET /_schema` `runtime.privateFiles`. */
    public function describe(): array;
}
