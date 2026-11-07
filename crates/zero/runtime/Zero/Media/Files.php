<?php

declare(strict_types=1);

namespace Zero\Media;

use Zero\Access;
use Zero\Extensions;
use Zero\HttpError;
use Zero\Js;
use Zero\Response;
use Zero\Schema;
use Zero\Signer;
use Zero\Viewer;

use function Zero\db;

/**
 * Private file buckets (phase 2 §7.2): `files("decks", {...})` rows owned by their uploader, bytes
 * outside any public directory, read only through a signed 5-minute download URL that PHP streams.
 * FileStorage (WpFiles) owns the persistence.
 */
final class Files
{
    public const TTL = 300;
    private const RULES = ['read' => ['owner' => true], 'create' => ['role' => 'authenticated'], 'delete' => ['owner' => true]];

    private static ?FileStorage $storage = null;
    private static ?\Zero\Db $storageFor = null;

    public static function register(): void
    {
        Extensions::route('files', FileRoutes::dispatch(...));
        \Zero\SystemTables::register('__zero_files', [
            'CREATE TABLE IF NOT EXISTS `{prefix}zero__files` (`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `app` VARCHAR(191) NOT NULL DEFAULT \'\', `bucket` TEXT NOT NULL, `filename` TEXT NOT NULL, `mime` TEXT NOT NULL, `size` BIGINT NOT NULL, `path` TEXT NOT NULL, `ownerId` BIGINT UNSIGNED NULL, `createdAt` CHAR(24) NOT NULL, PRIMARY KEY (`id`), KEY `app` (`app`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            static fn (\Zero\Store $store) => \Zero\Wp\WpFiles::upgrade($store),
        ]);
        // `file({ bucket })` columns: existence + bucket on write, populate with `with`, null on delete.
        // After before-hooks and persistence, still inside the transaction: hooks can't slip an unchecked id in.
        Extensions::onWritten(self::checkColumns(...));
        Extensions::beforeRead(self::takeWith(...), constraintSafe: true);
        Extensions::onRead(self::populate(...), constraintSafe: true);
        Extensions::schemaDescribe(static function (array $description): array {
            if (($description['files'] ?? []) !== []) {
                try {
                    $description['runtime']['privateFiles'] = self::storage()->describe();
                } catch (\LogicException) {
                    // A schema described outside a booted app has no storage to report on.
                }
            }

            return $description;
        });
    }

    /** The active app's private-file storage. */
    public static function storage(): FileStorage
    {
        if (self::$storage === null || self::$storageFor !== db()) {
            self::$storage = new \Zero\Wp\WpFiles(\Zero\Wp\Runtime::plugin()?->connection() ?? throw new \LogicException('No Zero app is active'), db()->schema()->name());
            self::$storageFor = db();
        }

        return self::$storage;
    }

    /** @return array<string, array{accept: list<string>, maxBytes: int, access: array}> */
    public static function buckets(): array
    {
        return db()->schema()->files();
    }

    public static function bucket(string $name): array
    {
        return self::buckets()[$name] ?? throw HttpError::notFound("Unknown file bucket '$name'");
    }

    /**
     * One bucket rule for one row (or none, for create). "owner" compares ownerId to the viewer;
     * a null viewer is trusted.
     */
    public static function allowed(array $bucket, string $op, ?Viewer $viewer, ?array $row): bool
    {
        if ($viewer === null) {
            return true;
        }
        $rule = $bucket['access'][$op] ?? self::RULES[$op];
        if (($rule['owner'] ?? false) === true) {
            return $viewer->user !== null && $row !== null && $row['ownerId'] === (int) $viewer->user['id'];
        }
        $r = Access::evaluate($rule, $viewer, $op, null);
        if (is_array($r)) {
            throw new \LogicException("The $op rule of a file bucket returned a filter; it must return a boolean");
        }

        return $r;
    }

    public static function require(array $bucket, string $name, string $op, ?Viewer $viewer, ?array $row): void
    {
        if (!self::allowed($bucket, $op, $viewer, $row)) {
            throw Access::deny($viewer?->user, "$op files in $name");
        }
    }

    // ================================================================ rows

    public static function upload(string $name, string $tmpPath, string $filename, ?Viewer $viewer): array
    {
        $bucket = self::bucket($name);
        self::require($bucket, $name, 'create', $viewer, null);
        $storage = self::storage();
        if (($why = $storage->unavailable()) !== null) {
            throw new HttpError(503, 'files_unavailable', $why);
        }
        if (!is_file($tmpPath)) {
            throw HttpError::invalidInput('No file was uploaded', '/file');
        }
        $size = (int) filesize($tmpPath);
        if ($size > (int) $bucket['maxBytes']) {
            throw new HttpError(413, 'payload_too_large', "Files in $name are limited to {$bucket['maxBytes']} bytes");
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: 'application/octet-stream';
        if (!self::accepts($bucket['accept'], $mime)) {
            throw HttpError::invalidInput("$name accepts " . implode(', ', $bucket['accept']) . "; this file is $mime", '/file');
        }
        $rel = $name . '/' . Js::uuid();
        $storage->store($tmpPath, $rel);
        $row = [
            'bucket' => $name,
            'filename' => self::filename($filename),
            'mime' => $mime,
            'size' => $size,
            'path' => $rel,
            'ownerId' => isset(db()->actor()['id']) ? (int) db()->actor()['id'] : null,
            'createdAt' => Js::iso(Js::now()),
        ];
        $id = $storage->insert($row);

        return self::present(['id' => $id] + $row);
    }

    /** Readable row with a fresh signed url, or a 404 for a missing row / wrong bucket. */
    public static function get(string $name, int $id, ?Viewer $viewer): array
    {
        $bucket = self::bucket($name);
        $row = self::find($name, $id);
        self::require($bucket, $name, 'read', $viewer, $row);

        return self::present($row);
    }

    /** Delete a file: null every `file` column holding it (hooks + revisions apply), then the row. */
    public static function delete(string $name, int $id, ?Viewer $viewer): array
    {
        $bucket = self::bucket($name);
        $row = self::find($name, $id);
        self::require($bucket, $name, 'delete', $viewer, $row);
        $db = db();
        foreach ($db->schema()->collections() as $c => $spec) {
            foreach ($spec['columns'] as $col => $def) {
                if ($def['type'] === 'file' && ($def['bucket'] ?? null) === $name) {
                    $db->update((string) $c, [$col => null], [$col => $id]);
                }
            }
        }
        // Only once every column is nulled: a hook on a later update may have put an id back.
        foreach ($db->schema()->collections() as $c => $spec) {
            foreach ($spec['columns'] as $col => $def) {
                if ($def['type'] === 'file' && ($def['bucket'] ?? null) === $name && $db->findFirst((string) $c, ['where' => [$col => $id]]) !== null) {
                    throw new HttpError(409, 'reference_violation', "Rows in $c still reference file $id", HttpError::seg((string) $col));
                }
            }
        }
        self::refuseNested($name, $id);
        self::storage()->delete($row);

        return self::present($row);
    }

    private static function find(string $name, int $id): array
    {
        $row = self::storage()->getMany([$id])[$id] ?? null;
        if ($row === null || $row['bucket'] !== $name) {
            throw HttpError::notFound("No file $id in $name");
        }

        return $row;
    }

    /** The public row: no storage path, a signed url valid for TTL seconds. */
    public static function present(array $row): array
    {
        $exp = intdiv(Js::now(), 1000) + self::TTL;
        $route = "/files/{$row['bucket']}/{$row['id']}/download";
        $query = http_build_query(['exp' => $exp, 'sig' => self::signer()->sign(self::message($row['bucket'], $row['id'], $exp))]);

        return [
            'id' => $row['id'], 'bucket' => $row['bucket'], 'filename' => $row['filename'], 'mime' => $row['mime'],
            'size' => $row['size'], 'ownerId' => $row['ownerId'], 'createdAt' => $row['createdAt'],
            'url' => self::storage()->apiUrl($route) . (str_contains(self::storage()->apiUrl($route), '?') ? '&' : '?') . $query,
        ];
    }

    /** Stream a file for a valid signature: no session, no access rule; the signature is the grant. */
    public static function download(string $name, int $id, array $query): Response
    {
        self::bucket($name);
        $exp = $query['exp'] ?? null;
        $now = intdiv(Js::now(), 1000);
        $valid = is_string($exp) && preg_match('/^\d{1,12}$/D', $exp) === 1
            && (int) $exp >= $now && (int) $exp <= $now + self::TTL
            && self::signer()->verify(self::message($name, $id, (int) $exp), $query['sig'] ?? null);
        if (!$valid) {
            throw new HttpError(403, 'forbidden', 'This download link is invalid or has expired; request a fresh one');
        }
        $row = self::find($name, $id);
        $stream = self::storage()->stream($row['path']);
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $row['filename']);

        return Response::raw($stream, 200, [
            'Content-Type' => $row['mime'],
            'Content-Length' => (string) $row['size'],
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($row['filename']),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function signer(): Signer
    {
        return new Signer(self::storage()->secret());
    }

    private static function message(string $bucket, int $id, int $exp): string
    {
        return "$bucket/$id/$exp";
    }

    /** @param list<string> $accept `type/subtype` or `type/*` */
    private static function accepts(array $accept, string $mime): bool
    {
        foreach ($accept as $a) {
            if ($a === $mime || (str_ends_with($a, '/*') && str_starts_with($mime, substr($a, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    private static function filename(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return $name === '' ? 'file' : mb_substr($name, 0, 200, 'UTF-8');
    }

    /** Rows as stored → typed. */
    public static function normalizeRow(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'bucket' => (string) $r['bucket'], 'filename' => (string) $r['filename'], 'mime' => (string) $r['mime'],
            'size' => (int) $r['size'], 'path' => (string) $r['path'], 'ownerId' => $r['ownerId'] === null ? null : (int) $r['ownerId'],
            'createdAt' => (string) $r['createdAt'],
        ];
    }

    // ================================================================ file({ bucket }) columns

    /** @return array<string, string> column => bucket */
    private static function fileColumns(string $collection): array
    {
        $out = [];
        foreach (db()->schema()->collection($collection)['columns'] as $col => $def) {
            if ($def['type'] === 'file') {
                $out[(string) $col] = (string) $def['bucket'];
            }
        }

        return $out;
    }

    /** Newly written ids must exist in the column's bucket (422 with the column pointer; the write rolls back). */
    private static function checkColumns(string $op, string $collection, array $row, ?array $previous): void
    {
        if ($op === 'delete') {
            return;
        }
        $refs = [];
        foreach (db()->schema()->collection($collection)['columns'] as $col => $def) {
            if (($row[$col] ?? null) !== ($previous[$col] ?? null)) {
                array_push($refs, ...self::refs($def, $row[$col] ?? null, HttpError::seg((string) $col)));
            }
        }
        if ($refs === []) {
            return;
        }
        $rows = self::storage()->getMany(array_map(static fn ($r) => $r[0], $refs));
        foreach ($refs as [$id, $bucket, $ptr]) {
            if (($rows[$id]['bucket'] ?? null) !== $bucket) {
                throw HttpError::invalidInput("No file $id in $bucket", $ptr);
            }
        }
    }

    /**
     * Top-level file columns are nulled on delete; nested ones (group/repeater/blocks) are not, so
     * a delete is refused while one still holds the id. (file() is rejected in globals at build time.)
     */
    private static function refuseNested(string $bucket, int $id): void
    {
        $db = db();
        $holds = static function (array $defs, array $row) use ($bucket, $id): bool {
            foreach ($defs as $col => $def) {
                foreach (self::refs($def, $row[$col] ?? null, '') as [$ref, $refBucket]) {
                    if ($ref === $id && $refBucket === $bucket) {
                        return true;
                    }
                }
            }

            return false;
        };
        foreach ($db->schema()->collections() as $c => $spec) {
            $nested = array_filter($spec['columns'], static fn (array $d) => in_array($d['type'], ['group', 'repeater', 'blocks'], true));
            foreach ($nested === [] ? [] : $db->findMany((string) $c, ['columns' => array_fill_keys(array_keys($nested), true)]) as $row) {
                if ($holds($nested, $row)) {
                    throw new HttpError(409, 'reference_violation', "Rows in $c still reference file $id in a nested field; clear it first");
                }
            }
        }
    }

    /**
     * File ids in a decoded value, at any group/repeater/blocks depth.
     *
     * @return list<array{int, string, string}> [id, bucket, pointer]
     */
    public static function refs(array $def, mixed $v, string $ptr): array
    {
        if ($v === null) {
            return [];
        }
        $fields = static function (array $fields, mixed $obj, string $base): array {
            $out = [];
            foreach ($fields as $name => $f) {
                array_push($out, ...self::refs($f, is_array($obj) ? ($obj[$name] ?? null) : null, $base . HttpError::seg((string) $name)));
            }

            return $out;
        };

        return match ($def['type']) {
            'file' => is_int($v) ? [[$v, (string) $def['bucket'], $ptr]] : [],
            'group' => $fields($def['fields'] ?? [], $v, $ptr),
            'repeater' => is_array($v) ? array_merge([], ...array_map(static fn ($item, $i) => $fields($def['fields'] ?? [], $item, "$ptr/$i"), $v, array_keys($v))) : [],
            'blocks' => is_array($v) ? array_merge([], ...array_map(static fn ($item, $i) => $fields($def['layouts'][$item['layout'] ?? ''] ?? [], $item, "$ptr/$i"), $v, array_keys($v))) : [],
            default => [],
        };
    }

    /** Null when file $id is in $bucket, else the message editor validation shows. */
    public static function missing(int $id, string $bucket): ?string
    {
        return (self::storage()->getMany([$id])[$id]['bucket'] ?? null) === $bucket ? null : "No file $id in $bucket";
    }

    /** @var array<string, list<string>> collection => file columns `with` asked to populate (this read) */
    private static array $wanted = [];

    /** Consume `with: { <fileColumn>: true }` before Db validates relations. */
    private static function takeWith(string $collection, array &$opts, ?Viewer $viewer, string $pointer): void
    {
        self::$wanted[$collection] = [];
        if (!isset($opts['with']) || !is_array($opts['with'])) {
            return;
        }
        foreach (self::fileColumns($collection) as $col => $_) {
            if (!array_key_exists($col, $opts['with'])) {
                continue;
            }
            $v = $opts['with'][$col];
            unset($opts['with'][$col]);
            if ($v === true) {
                self::$wanted[$collection][] = $col;
            } elseif ($v !== false && $v !== null) {
                throw HttpError::invalidQuery('File columns take `true`', $pointer . '/with' . HttpError::seg($col));
            }
        }
        if ($opts['with'] === []) {
            unset($opts['with']);
        }
    }

    /** Replace requested file ids by rows with a fresh url, for viewers the bucket's read rule admits. */
    private static function populate(array $rows, string $collection, ?Viewer $viewer): array
    {
        $cols = self::$wanted[$collection] ?? [];
        self::$wanted[$collection] = [];
        if ($cols === [] || $rows === []) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $row) {
            foreach ($cols as $col) {
                if (is_int($row[$col] ?? null)) {
                    $ids[] = $row[$col];
                }
            }
        }
        $files = self::storage()->getMany($ids);
        $columns = self::fileColumns($collection);
        foreach ($rows as &$row) {
            foreach ($cols as $col) {
                $file = is_int($row[$col] ?? null) ? ($files[$row[$col]] ?? null) : null;
                if ($file !== null && $file['bucket'] === $columns[$col] && self::allowed(self::bucket($columns[$col]), 'read', $viewer, $file)) {
                    $row[$col] = self::present($file);
                }
            }
        }
        unset($row);

        return $rows;
    }
}
