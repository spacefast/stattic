<?php

declare(strict_types=1);

namespace Zero\Wp\Legacy;

use Zero\HttpError;
use Zero\Js;
use Zero\Schema;
use Zero\Wp\Connection;
use Zero\Wp\Scf;

/**
 * `wp zero migrate-legacy <content-model.php> [--dry-run]`: turn an original Zero (0.8) site into
 * the new app's post types in place.
 *
 * Each post tagged `zero_collection` keeps its id, so references, media ids, revisions, comments
 * and links that name it stay valid: its `post_type` becomes `zero_<table>`, the term is removed,
 * and its `_zero_<resource>_<field>` rows become the new `zero_<column>` meta in the storage form
 * WpPostStore reads, plus SCF's field-key reference. Status, dates and author are the post row's
 * own and stay untouched. Revisions keep their parent, author and date; they gain the record's
 * field values (see revisionValues), so restoring one changes the title as it did before.
 *
 * Every record is decoded and validated by Zero's codecs and rules before anything is written, and
 * the run is one transaction (the CLI's). A value Zero would reject (an unknown option, a dangling
 * reference or media id, a missing required value, a unique clash) refuses the run with the record
 * and field as an RFC 6901 pointer, `/records/<post id>/<field>`, and nothing changes. A migrated
 * post no longer carries the term, so a second run finds nothing to do; a failed run rolled back,
 * so re-running starts over cleanly.
 */
final class Migrate
{
    /** Post statuses that are records. Trash, auto-drafts and inherit rows are left alone. */
    private const STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];
    /** Original Zero stamped each Space's posts and collection terms with this meta. */
    private const SPACE_META = '_spacefast_space_id';

    public function __construct(private readonly Connection $db, private readonly Schema $schema)
    {
    }

    /**
     * @param array<string, mixed> $release the original release (`require content-model.php`)
     * @param ?string $space the original Space id, required when the site hosted several
     * @return array{dryRun: bool, collections: array<string, array{records: list<int>, revisions: int}>, skipped: list<array{id: int, status: string}>, review: list<array{pointer: string, reason: string}>}
     */
    public function run(array $release, bool $dryRun, ?string $space = null): array
    {
        $model = Model::map($release, $this->schema);
        $this->requireTransactionalTables();
        $space = $this->space($model, $space);
        $native = $this->nativePosts($space);
        $plan = [];
        $skipped = [];
        $review = [];
        foreach ($model->collections as $resource => $map) {
            $plan[$resource] = [];
            foreach ($this->postsOf($resource, $space) as $post) {
                $id = (int) $post['ID'];
                if (!in_array($post['post_status'], self::STATUSES, true)) {
                    $skipped[] = ['id' => $id, 'status' => (string) $post['post_status']];
                    continue;
                }
                if (in_array($post['post_status'], ['pending', 'private'], true)) {
                    $review[] = ['pointer' => "/records/$id", 'reason' => "WordPress status {$post['post_status']} is kept; Zero reads it as draft (not public)"];
                }
                $values = $this->values($map, $id, $post, $this->legacyMeta($id), "/records/$id");
                $plan[$resource][$id] = ['title' => (string) $post['post_title'], 'values' => $values, 'revisions' => []];
                foreach ($this->revisionIds($id) as $rid) {
                    $plan[$resource][$id]['revisions'][$rid] = $this->revisionValues($map, $rid, $this->legacyMeta($rid), "/records/$id/revisions/$rid", $values);
                }
            }
        }
        $this->checkReferences($model, $plan);
        $this->checkUnique($model, $plan);
        $report = ['dryRun' => $dryRun, 'collections' => [], 'skipped' => $skipped, 'review' => $review];
        // The terms this run's posts carry; their counts are recomputed after the writes.
        $touched = $this->collectionTerms(array_merge([], ...array_map('array_keys', array_values($plan))));
        $this->requireKnownTermCounts($touched);
        foreach ($plan as $resource => $records) {
            foreach ($records as $id => $record) {
                if (!$dryRun) {
                    $this->write($model->collections[$resource], $id, $record);
                }
            }
            $report['collections'][$resource] = [
                'records' => array_keys($records),
                'revisions' => array_sum(array_map(static fn (array $r) => count($r['revisions']), $records)),
            ];
        }
        if ($native !== null) {
            [$spec, $ids] = $native;
            foreach ($ids as $id) {
                if (!$dryRun) {
                    $this->db->run("UPDATE `{$this->db->wpdb()->posts}` SET post_type = ? WHERE ID = ?", [$spec['wp']['postType'], $id]);
                    $this->db->touchPost($id);
                }
            }
            $report['collections']['posts'] = ['records' => $ids, 'revisions' => array_sum(array_map(fn (int $id) => count($this->revisionIds($id)), $ids))];
        }
        if (!$dryRun) {
            $this->recount($touched);
        }

        return $report;
    }

    private function nativePosts(?string $space): ?array
    {
        if (!$this->schema->hasCollection('posts')) { return null; }
        if ($space === null || $space === '') { throw Model::refuse('/space', 'native posts adoption requires --space'); }
        $spec = $this->schema->collection('posts');
        if (!isset($spec['wp']['postType']) || $spec['title'] !== 'title' || ($spec['content'] ?? null) !== 'content') {
            throw Model::refuse('/collections/posts', 'native posts need a post-backed title and content collection');
        }
        $w = $this->db->wpdb();
        $posts = $this->db->run("SELECT p.* FROM `{$w->posts}` p WHERE p.post_type = 'post'"
            . " AND EXISTS (SELECT 1 FROM `{$w->postmeta}` m WHERE m.post_id=p.ID AND m.meta_key=? AND m.meta_value=?)"
            . " AND NOT EXISTS (SELECT 1 FROM `{$w->term_relationships}` tr JOIN `{$w->term_taxonomy}` tt ON tt.term_taxonomy_id=tr.term_taxonomy_id JOIN `{$w->termmeta}` tm ON tm.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy=? AND tm.meta_key=? AND tm.meta_value <> 'posts') ORDER BY p.ID",
            [self::SPACE_META, $space, Model::TAXONOMY, Model::RESOURCE_META]);
        foreach ($posts as $post) {
            foreach (['title' => 'post_title', 'content' => 'post_content'] as $column => $native) {
                $def = $spec['columns'][$column];
                $ptr = '/records/' . $post['ID'] . '/' . $column;
                $value = Schema::normalize($def, $post[$native], $column, $ptr);
                Schema::checkRules($def, $value, $column, $ptr, ['data' => [$column => $value], 'user' => null], $spec['columns']);
            }
        }
        return [$spec, array_map('intval', array_column($posts, 'ID'))];
    }

    /**
     * The one original Space this run migrates. An installation could host several Spaces, each
     * with its own collection terms; then the Space must be named, even if each collection
     * happens to exist in only one of them.
     */
    private function space(Model $model, ?string $space): ?string
    {
        if ($space !== null) {
            return $space;
        }
        $w = $this->db->wpdb();
        $resources = array_keys($model->collections);
        if ($resources === []) {
            return null;
        }
        $marks = implode(', ', array_fill(0, count($resources), '?'));
        $spaces = array_values(array_unique(array_map(static fn (array $r) => (string) $r['space'], $this->db->run(
            "SELECT COALESCE(sm.meta_value, '') AS space FROM `{$w->term_taxonomy}` tt"
            . " JOIN `{$w->termmeta}` tm ON tm.term_id = tt.term_id AND tm.meta_key = ? AND tm.meta_value IN ($marks)"
            . " LEFT JOIN `{$w->termmeta}` sm ON sm.term_id = tt.term_id AND sm.meta_key = ?"
            . ' WHERE tt.taxonomy = ?',
            [Model::RESOURCE_META, ...$resources, self::SPACE_META, Model::TAXONOMY],
        ))));
        if (count($spaces) > 1) {
            throw Model::refuse('', 'the site holds collections of several original Spaces (' . implode(', ', $spaces) . '); pass --space=<id>');
        }

        return null;
    }

    /**
     * How WordPress counts a taxonomy's terms (wp_update_term_count_now): its update_count_callback,
     * else post counting when every registered object type is a post type, else generic counting.
     * Returns 'post', 'generic', or null for a callback this command can't reproduce.
     */
    private static function countMode(string $taxonomy): ?string
    {
        $object = get_taxonomy($taxonomy);
        if ($object === false) {
            return 'post'; // zero_collection once the original runtime is gone: its terms were on posts.
        }
        $callback = $object->update_count_callback ?? '';
        if ($callback !== '') {
            return match ($callback) {
                '_update_post_term_count' => 'post',
                '_update_generic_term_count' => 'generic',
                default => null,
            };
        }
        $types = array_map(static fn (string $t) => str_starts_with($t, 'attachment:') ? 'attachment' : $t, (array) $object->object_type);

        return array_filter($types, 'post_type_exists') == $types ? 'post' : 'generic';
    }

    /**
     * Every term this run's posts carry is recounted inside the transaction, so a taxonomy with its
     * own counting callback refuses the run before anything is written (dry run included).
     *
     * @param list<int> $terms term_taxonomy ids
     */
    private function requireKnownTermCounts(array $terms): void
    {
        if ($terms === []) {
            return;
        }
        $w = $this->db->wpdb();
        $marks = implode(', ', array_fill(0, count($terms), '?'));
        foreach (array_column($this->db->run("SELECT DISTINCT taxonomy FROM `{$w->term_taxonomy}` WHERE term_taxonomy_id IN ($marks)", $terms), 'taxonomy') as $taxonomy) {
            if (self::countMode((string) $taxonomy) === null) {
                throw Model::refuse('', "taxonomy $taxonomy counts its terms with its own callback; the migration can't recount it in its transaction");
            }
        }
    }

    /**
     * Every table this run writes must roll back with the transaction. The app's activation checks
     * posts and postmeta; this command also writes taxonomy rows.
     */
    private function requireTransactionalTables(): void
    {
        $w = $this->db->wpdb();
        $tables = [$w->posts, $w->postmeta, $w->term_relationships, $w->term_taxonomy];
        $marks = implode(', ', array_fill(0, count($tables), '?'));
        $bad = array_column($this->db->run(
            "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks) AND ENGINE <> 'InnoDB'",
            $tables,
        ), 't');
        if ($bad !== []) {
            throw Model::refuse('', 'these tables are not InnoDB, so a failed migration could not roll back: ' . implode(', ', $bad));
        }
    }

    /** Posts carrying this collection's term (in one Space), in id order. @return list<array<string, string>> */
    private function postsOf(string $resource, ?string $space): array
    {
        $w = $this->db->wpdb();

        return $this->db->run(
            "SELECT p.ID, p.post_status, p.post_title, p.post_content, p.post_date_gmt FROM `{$w->posts}` p"
            . " JOIN `{$w->term_relationships}` tr ON tr.object_id = p.ID"
            . " JOIN `{$w->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = ?"
            . " JOIN `{$w->termmeta}` tm ON tm.term_id = tt.term_id AND tm.meta_key = ? AND tm.meta_value = ?"
            . ($space === null ? '' : " JOIN `{$w->termmeta}` sm ON sm.term_id = tt.term_id AND sm.meta_key = ? AND sm.meta_value = ?")
            . " WHERE p.post_type = 'post' ORDER BY p.ID",
            [Model::TAXONOMY, Model::RESOURCE_META, $resource, ...($space === null ? [] : [self::SPACE_META, $space])],
        );
    }

    /**
     * A record's new storage-form values, validated the way a Zero write would validate them.
     *
     * @param array<string, string> $meta legacy meta key → raw value
     * @return array<string, int|float|string|null> column → storage form (null = no value)
     */
    private function values(array $map, int $id, array $post, array $meta, string $base): array
    {
        $spec = $this->schema->collection($map['collection']);
        // The title is wp_posts' own, but it is still a column with Zero's codec (enums included).
        $row = [$spec['title'] => Schema::normalize($spec['columns'][$spec['title']], (string) $post['post_title'], $spec['title'], $base . HttpError::seg($spec['title']))];
        // The post's content is wp_posts' own too: it stays where it is (never in $out), but its
        // column's codec and rules apply, as they would on a write.
        if (($spec['content'] ?? null) !== null) {
            $content = $spec['content'];
            $row[$content] = Schema::normalize($spec['columns'][$content], (string) $post['post_content'], $content, $base . HttpError::seg($content));
        }
        $out = [];
        foreach ($map['fields'] as $name => $f) {
            $col = $spec['columns'][$f['column']];
            $at = $base . HttpError::seg((string) $name);
            $js = self::decode($f, $col, $meta[$f['key']] ?? null, $at);
            $norm = $js === null ? null : Schema::normalize($col, $js, (string) $name, $at);
            $row[$f['column']] = $norm;
            $out[$f['column']] = Schema::store($col, $norm);
        }
        foreach ($spec['columns'] as $column => $col) {
            $column = (string) $column;
            if (($col['system'] ?? false) || $column === $spec['primaryKey']) {
                continue;
            }
            $at = $base . HttpError::seg($this->sourceName($map, $column));
            if (!array_key_exists($column, $row)) {
                // A column the original app never had takes its declared default, normalized and
                // checked like any written value (Model::map refused defaults it can't write).
                if (isset($col['default'])) {
                    $row[$column] = Schema::normalize($col, Schema::generate($col['default'], $col), $column, $at);
                    $out[$column] = Schema::store($col, $row[$column]);
                } elseif ($col['notNull'] ?? false) {
                    throw HttpError::invalidInput("$column is required in the new app and has no original field", $at);
                }
                continue;
            }
            if ($row[$column] === null && ($col['notNull'] ?? false)) {
                throw HttpError::invalidInput("$column is required in the new app but record $id has no value", $at);
            }
        }
        // Validators see the whole row the new app will read, as on any write (Db::checkValues):
        // the id, and status/publishedAt as WpPostStore maps them.
        $full = $row + [$spec['primaryKey'] => $id];
        if ($spec['columns']['status']['system'] ?? false) {
            $full['status'] = match ($post['post_status']) { 'publish' => 'published', 'future' => 'scheduled', default => 'draft' };
        }
        if ($spec['columns']['publishedAt']['system'] ?? false) {
            $full['publishedAt'] = $post['post_date_gmt'] === '0000-00-00 00:00:00' ? null : str_replace(' ', 'T', (string) $post['post_date_gmt']) . '.000Z';
        }
        $full += array_fill_keys(array_keys($spec['columns']), null);
        foreach ($spec['columns'] as $column => $col) {
            $column = (string) $column;
            if (array_key_exists($column, $row) && $column !== $spec['primaryKey'] && !($col['system'] ?? false)) {
                Schema::checkRules($col, $row[$column], $column, $base . HttpError::seg($this->sourceName($map, $column)), ['data' => $full, 'user' => null], $spec['columns']);
            }
        }

        return $out;
    }

    /**
     * A revision's values: the fields it carries, decoded (not rule-checked: a revision records what
     * was). Original Zero stored none on revisions, and restoring one changed only the title and
     * content; the record's own values fill the rest, so a restore keeps them as it did.
     *
     * @param array<string, int|float|string|null> $current the record's migrated values
     * @return array<string, int|float|string|null>
     */
    private function revisionValues(array $map, int $rid, array $meta, string $base, array $current): array
    {
        $spec = $this->schema->collection($map['collection']);
        $out = $current;
        foreach ($map['fields'] as $name => $f) {
            if (!array_key_exists($f['key'], $meta)) {
                continue;
            }
            $col = $spec['columns'][$f['column']];
            $at = $base . HttpError::seg((string) $name);
            $js = self::decode($f, $col, $meta[$f['key']], $at);
            $out[$f['column']] = $js === null ? null : Schema::store($col, Schema::normalize($col, $js, (string) $name, $at));
        }

        return $out;
    }

    private function sourceName(array $map, string $column): string
    {
        foreach ($map['fields'] as $name => $f) {
            if ($f['column'] === $column) {
                return (string) $name;
            }
        }

        return $column;
    }

    /** @return array<string, string> legacy meta key → raw value (only `_zero_` keys) */
    private function legacyMeta(int $id): array
    {
        $w = $this->db->wpdb();
        $out = [];
        foreach ($this->db->run("SELECT meta_key, meta_value FROM `{$w->postmeta}` WHERE post_id = ? AND meta_key LIKE '\\_zero\\_%' ORDER BY meta_id", [$id]) as $r) {
            $out[(string) $r['meta_key']] = (string) $r['meta_value'];
        }

        return $out;
    }

    /** Raw legacy meta → Zero's JS form for the target column. Absent or empty reads as null. */
    private static function decode(array $f, array $col, ?string $raw, string $at): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $list = static function () use ($raw, $at): array {
            $v = is_serialized($raw) ? @unserialize($raw, ['allowed_classes' => false]) : json_decode($raw, true);
            if (!is_array($v)) {
                throw HttpError::invalidInput('stored list is not a serialized array', $at);
            }

            return array_values(array_filter($v, static fn ($x) => $x !== '' && $x !== null));
        };
        $id = static fn (mixed $x): int => is_int($x) || (is_string($x) && ctype_digit($x))
            ? (int) $x
            : throw HttpError::invalidInput('stored id is ' . Js::jsonStringify($x), $at);

        return match ($f['kind']) {
            'scalar' => match ($col['type']) {
                'real', 'integer' => is_numeric($raw) ? $raw + 0 : throw HttpError::invalidInput("stored number is '$raw'", $at),
                default => $raw,
            },
            'boolean' => match ($raw) {
                '1' => true,
                '0' => false,
                default => throw HttpError::invalidInput("stored boolean is '$raw'", $at),
            },
            'timestamp' => $raw,
            // A stored empty array stays [] (the original read it back as []); only absent meta is null.
            'list' => $list(),
            // SCF stores an unset post_object/file field as 0.
            'media', 'reference' => $id($raw) ?: null,
            'mediaList' => array_map($id, $list()),
            default => throw new \LogicException('unknown legacy kind ' . $f['kind']),
        };
    }

    /**
     * Every reference and media value about to be written (mapped from the original app or
     * filled from a default) must name a live record of its target or an attachment: Zero has no
     * dangling ids.
     */
    private function checkReferences(Model $model, array $plan): void
    {
        $w = $this->db->wpdb();
        $resources = array_flip(array_map(static fn (array $map): string => $map['collection'], $model->collections));
        foreach ($plan as $resource => $records) {
            $map = $model->collections[$resource];
            $spec = $this->schema->collection($map['collection']);
            foreach ($spec['columns'] as $column => $col) {
                $column = (string) $column;
                $target = $col['references']['collection'] ?? null;
                $media = in_array($col['type'], ['media', 'mediaList'], true);
                if ($target === null && !$media) {
                    continue;
                }
                foreach ($records as $id => $record) {
                    $v = $record['values'][$column] ?? null;
                    if ($v === null) {
                        continue;
                    }
                    $at = "/records/$id" . HttpError::seg($this->sourceName($map, $column));
                    if ($target !== null) {
                        if (!isset($plan[$resources[$target]][(int) $v]) && !$this->isRowOf($target, (int) $v)) {
                            throw new HttpError(409, 'reference_violation', "record $id references $v, which is not a $target record", $at);
                        }
                        continue;
                    }
                    $ids = $col['type'] === 'media' ? [(int) $v] : json_decode((string) $v, true);
                    foreach ($ids as $i => $mid) {
                        $ptr = $col['type'] === 'media' ? $at : "$at/$i";
                        $found = $this->db->run("SELECT post_mime_type AS mime FROM `{$w->posts}` WHERE ID = ? AND post_type = 'attachment'", [$mid]);
                        if ($found === []) {
                            throw HttpError::invalidInput("record $id names media $mid, which is not an attachment", $ptr);
                        }
                        // The same rule a Zero write applies (Db::checkValues).
                        if (($col['accept'] ?? 'any') === 'image' && !str_starts_with((string) $found[0]['mime'], 'image/')) {
                            throw HttpError::invalidInput("record $id names media $mid, which is not an image", $ptr);
                        }
                        // A Space-scoped original site kept upload bytes in its private storage root,
                        // not in wp-content/uploads; without the original runtime WordPress can't
                        // serve them. The file must be where WordPress now looks.
                        // Inside the uploads directory, not merely somewhere on disk: an absolute
                        // _wp_attached_file into the private root would exist but get no public URL.
                        $file = get_attached_file((int) $mid, true);
                        $uploads = realpath(wp_upload_dir()['basedir']);
                        $real = is_string($file) ? realpath($file) : false;
                        if ($real === false || $uploads === false || !str_starts_with($real, $uploads . DIRECTORY_SEPARATOR) || !is_file($real)) {
                            throw HttpError::invalidInput("media $mid's file isn't in this site's uploads (" . (string) get_post_meta((int) $mid, '_wp_attached_file', true) . '); copy the original Space\'s content-media files into wp-content/uploads first', $ptr);
                        }
                    }
                }
            }
        }
    }

    /**
     * unique() columns, the title included: Zero refuses a second row with the same value, among the
     * migrated records and against the collection's existing live rows. Values are compared by
     * MySQL, as WpPostStore's own unique check compares them.
     */
    private function checkUnique(Model $model, array $plan): void
    {
        $w = $this->db->wpdb();
        foreach ($plan as $resource => $records) {
            $map = $model->collections[$resource];
            $spec = $this->schema->collection($map['collection']);
            $live = "p.post_type = ? AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit')";
            foreach ($spec['columns'] as $column => $col) {
                $column = (string) $column;
                if (!($col['unique'] ?? false) || $column === $spec['primaryKey'] || $records === []) {
                    continue;
                }
                $title = $column === $spec['title'];
                $values = [];
                foreach ($records as $id => $record) {
                    $v = $title ? $record['title'] : ($record['values'][$column] ?? null);
                    if ($v !== null) {
                        $values[$id] = $title ? (string) $v : self::metaValue($col, $v);
                    }
                }
                if ($values === []) {
                    continue;
                }
                // One derived table of the planned values, joined against itself and the live rows.
                $union = implode(' UNION ALL ', array_fill(0, count($values), 'SELECT ? AS id, ? AS v'));
                $params = [];
                foreach ($values as $id => $v) {
                    array_push($params, $id, $v);
                }
                $existing = $title
                    ? "SELECT p.ID AS id, p.post_title AS v FROM `{$w->posts}` p WHERE $live"
                    : "SELECT m.post_id AS id, m.meta_value AS v FROM `{$w->postmeta}` m JOIN `{$w->posts}` p ON p.ID = m.post_id WHERE m.meta_key = ? AND $live";
                $existingParams = $title ? [$spec['wp']['postType']] : [$spec['wp']['metaPrefix'] . $column, $spec['wp']['postType']];
                // Planned records clash with an earlier planned one, or with any existing live row
                // that isn't itself being migrated (whatever its id).
                $planned = implode(', ', array_fill(0, count($values), '?'));
                $clash = $this->db->run(
                    "SELECT a.id AS id, b.id AS other FROM ($union) a JOIN ("
                    . "SELECT x.id, x.v, 1 AS planned FROM ($union) x UNION ALL SELECT e.id, e.v, 0 FROM ($existing) e WHERE e.id NOT IN ($planned)"
                    . ') b ON b.v = a.v AND (b.planned = 0 OR b.id < a.id) ORDER BY a.id LIMIT 1',
                    [...$params, ...$params, ...$existingParams, ...array_keys($values)],
                );
                if ($clash !== []) {
                    $id = (int) $clash[0]['id'];
                    throw new HttpError(409, 'unique_violation', "records {$clash[0]['other']} and $id share the unique $column value", "/records/$id" . HttpError::seg($title ? $column : $this->sourceName($map, $column)));
                }
            }
        }
    }

    private function isRowOf(string $collection, int $id): bool
    {
        $w = $this->db->wpdb();

        // The store's own live-row predicate: trash, auto-drafts and revisions aren't rows.
        return $this->db->run(
            "SELECT ID FROM `{$w->posts}` WHERE ID = ? AND post_type = ? AND post_status NOT IN ('trash', 'auto-draft', 'inherit')",
            [$id, $this->schema->collection($collection)['wp']['postType']],
        ) !== [];
    }

    /** @return list<int> */
    private function revisionIds(int $id): array
    {
        $w = $this->db->wpdb();

        return array_map('intval', array_column($this->db->run("SELECT ID FROM `{$w->posts}` WHERE post_parent = ? AND post_type = 'revision' ORDER BY ID", [$id]), 'ID'));
    }

    /** Move one record: post type, the meta rewrite on it and its revisions, and the term. */
    private function write(array $map, int $id, array $record): void
    {
        $spec = $this->schema->collection($map['collection']);
        $w = $this->db->wpdb();
        $this->db->run("UPDATE `{$w->posts}` SET post_type = ? WHERE ID = ?", [$spec['wp']['postType'], $id]);
        $this->replaceMeta($spec, $map, $id, $record['values']);
        foreach ($record['revisions'] as $rid => $values) {
            $this->replaceMeta($spec, $map, $rid, $values);
        }
        // Only the collection term goes. Other terms (categories, tags) stay with the post, so
        // nothing a site assigned is lost even though the new post type doesn't show them.
        $this->db->run(
            "DELETE tr FROM `{$w->term_relationships}` tr JOIN `{$w->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = ? AND tt.taxonomy = ?",
            [$id, Model::TAXONOMY],
        );
    }

    /** Delete exactly the legacy keys (and SCF's `_<key>` references), then write the new ones. */
    private function replaceMeta(array $spec, array $map, int $post, array $values): void
    {
        $w = $this->db->wpdb();
        $this->db->touchPost($post);
        $legacy = [];
        foreach ($map['fields'] as $f) {
            array_push($legacy, $f['key'], '_' . $f['key']);
        }
        if ($legacy !== []) {
            $marks = implode(', ', array_fill(0, count($legacy), '?'));
            $this->db->run("DELETE FROM `{$w->postmeta}` WHERE post_id = ? AND meta_key IN ($marks)", [$post, ...$legacy]);
        }
        foreach ($values as $column => $stored) {
            if ($stored === null) {
                continue;
            }
            $name = $spec['wp']['metaPrefix'] . $column;
            foreach ([$name => self::metaValue($spec['columns'][$column], $stored), '_' . $name => $spec['wp']['fieldKeyPrefix'] . $column] as $k => $v) {
                $this->db->run("DELETE FROM `{$w->postmeta}` WHERE post_id = ? AND meta_key = ?", [$post, $k]);
                $this->db->run("INSERT INTO `{$w->postmeta}` (post_id, meta_key, meta_value) VALUES (?, ?, ?)", [$post, $k, $v]);
            }
        }
        $this->db->afterCommit(static fn () => clean_post_cache($post));
    }

    /**
     * Storage form → the meta row value WpPostStore reads: SCF's own forms (Scf::metaScalar; dates
     * `Ymd`, timestamps `Y-m-d H:i:s`, booleans 1/0), PHP-serialized arrays for lists, and the JSON
     * text for json columns.
     */
    private static function metaValue(array $col, int|float|string $stored): string
    {
        return match ($col['type']) {
            'select' => serialize(array_values(array_map('strval', Schema::decode($col, $stored)))),
            'mediaList' => serialize(array_values(array_map('intval', Schema::decode($col, $stored)))),
            'json' => (string) $stored,
            default => Scf::metaScalar($col, $stored),
        };
    }

    /** @param list<int> $posts @return list<int> every term_taxonomy id they carry (collection terms, categories, tags) */
    private function collectionTerms(array $posts): array
    {
        if ($posts === []) {
            return [];
        }
        $w = $this->db->wpdb();
        $marks = implode(', ', array_fill(0, count($posts), '?'));

        return array_map('intval', array_column($this->db->run(
            "SELECT DISTINCT tr.term_taxonomy_id AS tt FROM `{$w->term_relationships}` tr WHERE tr.object_id IN ($marks)",
            $posts,
        ), 'tt'));
    }

    /**
     * Every term the migrated posts carried is recounted, because the posts stopped being native
     * `post`s: the collection terms (which stay, for the original app's rollback) and the
     * categories/tags still attached. Each count follows _update_post_term_count (published
     * posts of the post types registered for the taxonomy; for zero_collection, unregistered
     * once the original runtime is gone, native posts) but is written through the transaction's
     * own connection, so a failed write rolls the whole migration back instead of leaving a
     * stale count behind a committed run.
     *
     * @param list<int> $terms term_taxonomy ids
     */
    private function recount(array $terms): void
    {
        if ($terms === []) {
            return;
        }
        $w = $this->db->wpdb();
        $marks = implode(', ', array_fill(0, count($terms), '?'));
        $byTaxonomy = [];
        foreach ($this->db->run("SELECT term_taxonomy_id AS tt, term_id AS term, taxonomy FROM `{$w->term_taxonomy}` WHERE term_taxonomy_id IN ($marks)", $terms) as $r) {
            $byTaxonomy[(string) $r['taxonomy']][(int) $r['tt']] = (int) $r['term'];
        }
        foreach ($byTaxonomy as $taxonomy => $ids) {
            $object = get_taxonomy($taxonomy);
            if (self::countMode($taxonomy) === 'generic') {
                // Generic counting: every relationship counts, and the migration keeps them all.
                foreach (array_keys($ids) as $tt) {
                    $count = (int) $this->db->run("SELECT COUNT(*) AS n FROM `{$w->term_relationships}` WHERE term_taxonomy_id = ?", [$tt])[0]['n'];
                    $this->db->run("UPDATE `{$w->term_taxonomy}` SET count = ? WHERE term_taxonomy_id = ?", [$count, $tt]);
                }
                continue;
            }
            $types = $object === false
                ? ['post']
                : array_values(array_filter(array_unique(array_map(static fn (string $t) => explode(':', $t)[0], (array) $object->object_type)), 'post_type_exists'));
            $statuses = $object === false ? ['publish'] : (array) apply_filters('update_post_term_count_statuses', ['publish'], $object);
            $attachments = in_array('attachment', $types, true);
            $types = array_values(array_diff($types, ['attachment']));
            $marks = static fn (array $list) => implode(', ', array_fill(0, count($list), '?'));
            foreach (array_keys($ids) as $tt) {
                $count = 0;
                if ($attachments && $statuses !== []) {
                    // Attachments count while they or their parent have a counted status.
                    $count += (int) $this->db->run(
                        "SELECT COUNT(*) AS n FROM `{$w->term_relationships}` tr JOIN `{$w->posts}` p1 ON p1.ID = tr.object_id"
                        . " WHERE tr.term_taxonomy_id = ? AND p1.post_type = 'attachment' AND (p1.post_status IN ({$marks($statuses)})"
                        . " OR (p1.post_status = 'inherit' AND p1.post_parent > 0 AND (SELECT p2.post_status FROM `{$w->posts}` p2 WHERE p2.ID = p1.post_parent) IN ({$marks($statuses)})))",
                        [$tt, ...$statuses, ...$statuses],
                    )[0]['n'];
                }
                if ($types !== [] && $statuses !== []) {
                    $count += (int) $this->db->run(
                        "SELECT COUNT(*) AS n FROM `{$w->term_relationships}` tr JOIN `{$w->posts}` p ON p.ID = tr.object_id WHERE tr.term_taxonomy_id = ?"
                        . ' AND p.post_status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')'
                        . ' AND p.post_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')',
                        [$tt, ...$statuses, ...$types],
                    )[0]['n'];
                }
                $this->db->run("UPDATE `{$w->term_taxonomy}` SET count = ? WHERE term_taxonomy_id = ?", [$count, $tt]);
            }
        }
        $this->db->afterCommit(static function () use ($byTaxonomy): void {
            foreach ($byTaxonomy as $taxonomy => $ids) {
                clean_term_cache(array_values($ids), $taxonomy);
            }
        });
    }
}
