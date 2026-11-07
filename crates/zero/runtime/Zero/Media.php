<?php

declare(strict_types=1);

namespace Zero;

/*
 * CHANGED vs design §3.6 (additive): upload() takes a trailing ?string $alt (the multipart `alt`
 * field of POST /media); getMany() batches `with: { cover: true }` and existence checks; list()
 * serves GET /media.
 */

/**
 * The built-in media collection (§3.6, §4.6), implemented by Wp\WpMedia (attachments).
 * A media row is ['id' => int, 'url' => string, 'mime' => string, 'size' => int, 'width' => ?int,
 * 'height' => ?int, 'alt' => ?string, 'filename' => string, 'createdAt' => ISO string,
 * 'sizes' => array<name, {url, width, height}> (an empty object for non-images), 'focal' => ?{x, y}]
 * (phase 2 §7.2). Access is enforced by Db and the media routes (read anyone, create author,
 * update/delete editor), never here.
 */
interface Media
{
    /** Store an uploaded file ($tmpPath is consumed). Returns the media row. */
    public function upload(string $tmpPath, string $filename, ?array $user, ?string $alt = null): array;

    public function get(int $id): ?array;

    /**
     * @param list<int> $ids
     * @return array<int, array> media rows keyed by id (missing ids absent)
     */
    public function getMany(array $ids): array;

    /**
     * `GET /media`: $opts holds where/orderBy/limit/offset over the media row fields
     * (id, filename, mime, size, width, height, alt, createdAt).
     *
     * @return list<array>
     */
    public function list(array $opts): array;

    public function delete(int $id): void;

    /**
     * `PATCH /media/{id}`: $patch holds `alt` (?string) and/or `focal` (?{x, y}); a changed focal
     * regenerates every `cover` size. Returns the updated row, null when the id is unknown.
     */
    public function update(int $id, array $patch): ?array;
}
