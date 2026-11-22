<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\ColumnRef;
use TypedPress\Dialect;
use TypedPress\HttpError;
use TypedPress\Links;
use TypedPress\Schema;
use TypedPress\Source;
use TypedPress\Store;

/**
 * `taxonomy()` kinds (docs/decisions/2026-11-11-authoring-surface.md, rulings 25-34): a term row is
 * core's term (`wp_terms` + `wp_term_taxonomy`) plus its term meta, read with the same compiled SQL as
 * every other kind and written through core's term API (`wp_insert_term`, `wp_update_term`,
 * `wp_delete_term`) with the editor hooks muted, inside the request transaction.
 *
 * - `id` is the `term_id`; `name`, `slug`, `description`, `parent` and `count` are core's columns;
 *   `url` is the term's link (KindUrls builds it from the taxonomy's `url`), null without one.
 * - Authored fields are term meta `tp_<field>` in SCF's storage forms, written with `update_field`
 *   on `term_<id>`, so the term screen's SCF fields and TypedPress read the same values.
 * - The item side (Links, keys `term:<taxonomy>`): an item's terms are its `wp_term_relationships`
 *   rows for the taxonomy, in `term_order` (the taxonomy registers with `sort`), written with
 *   `wp_set_object_terms`, which also keeps core's counts.
 *
 * Nothing here stores a serialized core object: terms, term meta and term relationships are core's
 * own rows, and a select list in term meta is SCF's serialized array of option strings, as in post meta.
 */
final class WpTermStore implements Store, Links
{
    /** @var array<string, Source> */
    private array $sources = [];

    public function __construct(
        private readonly Connection $db,
        private readonly Schema $schema,
        private readonly Dialect $dialect,
    ) {
    }

    public function afterCommit(callable $fn): void
    {
        $this->db->afterCommit($fn);
    }

    public function applySystemStatements(array $statements): void
    {
        $this->db->applySystemStatements($statements);
    }

    public function transaction(callable $fn): mixed
    {
        return $this->db->transaction($fn);
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    /** The taxonomy's WordPress name (`tp_<name>`). */
    public function taxonomy(string $collection): string
    {
        return (string) $this->schema->collection($collection)['wp']['taxonomy'];
    }

    // ================================================================ reads

    public function source(string $collection): Source
    {
        return $this->sources[$collection] ??= $this->buildSource($collection);
    }

    private function buildSource(string $collection): Source
    {
        $spec = $this->schema->collection($collection);
        $wpdb = $this->db->wpdb();
        $terms = "LEFT JOIN `{$wpdb->terms}` {j} ON {j}.term_id = {a}.term_id";
        $meta = static fn (string $col) => "LEFT JOIN `{$wpdb->termmeta}` {j} ON {j}.term_id = {a}.term_id AND {j}.meta_key = '" . addslashes($spec['wp']['metaPrefix'] . $col) . "'";
        $v = "NULLIF({j}.meta_value, '')";
        $refs = [];
        foreach ($spec['columns'] as $col => $def) {
            $col = (string) $col;
            $type = $def['type'];
            $refs[$col] = match (true) {
                $col === $spec['primaryKey'] => new ColumnRef('{a}.term_id', '', '', 'scalar'),
                $col === 'uid' && ($def['system'] ?? false) => new ColumnRef($v, "LEFT JOIN `{$wpdb->termmeta}` {j} ON {j}.term_id = {a}.term_id AND {j}.meta_key = '_tp_uid'", $v, 'scalar'),
                $col === 'name' => new ColumnRef('{j}.name', $terms, '', 'scalar'),
                $col === 'slug' => new ColumnRef('{j}.slug', $terms, '', 'scalar'),
                $col === 'description' => new ColumnRef('{a}.description', '', '', 'scalar'),
                $col === 'parent' => new ColumnRef('NULLIF({a}.parent, 0)', '', 'NULLIF({a}.parent, 0)', 'scalar'),
                $col === 'count' => new ColumnRef('{a}.count', '', '', 'scalar'),
                $col === 'url' => new ColumnRef('NULL', '', '', 'scalar'), // filled after the query
                $type === 'integer', $type === 'media' => new ColumnRef($v, $meta($col), "CAST($v AS SIGNED)", 'scalar'),
                $type === 'real' => new ColumnRef($v, $meta($col), "CAST($v AS DECIMAL(30,10))", 'scalar'),
                $type === 'timestamp' => new ColumnRef("CONCAT(REPLACE($v, ' ', 'T'), '.000Z')", $meta($col), $v, 'scalar'),
                $type === 'date' => new ColumnRef($this->dialect->isoDate($v), $meta($col), $v, 'scalar'),
                $type === 'json' => new ColumnRef($v, $meta($col), '', 'json'),
                $type === 'select' => new ColumnRef($v, $meta($col), '{j}.meta_value', 'serialized-list'),
                default => new ColumnRef($v, $meta($col), $v, 'scalar'),
            };
        }

        return new Source(
            '`' . $wpdb->term_taxonomy . '`',
            "{a}.taxonomy = '" . addslashes($spec['wp']['taxonomy']) . "'",
            $refs,
        );
    }

    public function select(string $collection, array $q): array
    {
        $rows = $this->db->run($q['sql'], $q['params']);
        if ($rows === []) {
            return [];
        }
        $spec = $this->schema->collection($collection);
        $pk = $spec['primaryKey'];
        \TypedPress\Extensions::lookup($collection, array_column($rows, $pk));
        // A read that selected some columns (`columns`) finishes only those.
        $lists = array_keys(array_filter($spec['columns'], static fn (array $d, string|int $c) => $d['type'] === 'select' && array_key_exists($c, $rows[0]), ARRAY_FILTER_USE_BOTH));
        $linked = is_string($spec['url'] ?? null);
        if ($linked && array_key_exists('url', $rows[0]) && count($rows) > 1) {
            $this->primeLinkTerms(array_column($rows, $pk));
        }
        foreach ($rows as &$row) {
            foreach ($lists as $col) {
                $row[$col] = WpPostStore::serializedToJson($row[$col] ?? null, false);
            }
            if (array_key_exists('url', $row)) {
                $link = $linked && $row[$pk] !== null ? get_term_link((int) $row[$pk], $spec['wp']['taxonomy']) : null;
                $row['url'] = is_string($link) ? $link : null;
            }
        }
        unset($row);

        return $rows;
    }

    /** get_term_link() reads each term by ID. Shared IDs must stay uncached, as in WP_Term::get_instance(). */
    private function primeLinkTerms(array $ids): void
    {
        $missing = _get_non_cached_ids($ids, 'terms');
        if ($missing === []) {
            return;
        }
        $wpdb = $this->db->wpdb();
        $idsSql = implode(',', array_map('intval', $missing));
        $terms = $wpdb->get_results("SELECT t.*, tt.* FROM $wpdb->terms t
            INNER JOIN $wpdb->term_taxonomy tt ON t.term_id = tt.term_id
            WHERE t.term_id IN ($idsSql) AND NOT EXISTS (
                SELECT 1 FROM $wpdb->term_taxonomy other
                WHERE other.term_id = t.term_id AND other.taxonomy <> tt.taxonomy
            )");
        update_term_cache($terms);
    }

    public function showsUnique(string $collection, string $column, \TypedPress\Viewer $viewer): bool
    {
        return false;
    }

    public function readGuards(string $collection, \TypedPress\Viewer $viewer, bool $forSelect = false): array
    {
        return [];
    }

    // ================================================================ writes

    /** Core's term columns among $values: name, slug, description, parent. */
    private static function core(array $values): array
    {
        $args = [];
        foreach (['slug', 'description'] as $col) {
            if (array_key_exists($col, $values)) {
                $args[$col] = (string) ($values[$col] ?? '');
            }
        }
        if (array_key_exists('parent', $values)) {
            $args['parent'] = (int) ($values['parent'] ?? 0);
        }

        return $args;
    }

    /** A parent is a term of the same taxonomy (core checks only that some term has the id). */
    private static function checkParent(string $taxonomy, array $values): void
    {
        $parent = (int) ($values['parent'] ?? 0);
        if ($parent > 0 && !get_term($parent, $taxonomy) instanceof \WP_Term) {
            throw new HttpError(409, 'reference_violation', 'No parent term with this id in this taxonomy', '/parent');
        }
    }

    public function insert(string $collection, array $values): int|string
    {
        $spec = $this->schema->collection($collection);
        self::checkParent($spec['wp']['taxonomy'], $values);
        if (array_key_exists($spec['primaryKey'], $values)) {
            throw HttpError::invalidInput('A term\'s id is given by WordPress', HttpError::seg($spec['primaryKey']));
        }
        $this->checkUid($spec, $values, 0);
        $taxonomy = $spec['wp']['taxonomy'];
        $name = (string) ($values['name'] ?? '');
        $done = $this->db->core(static fn () => wp_insert_term(wp_slash($name), $taxonomy, wp_slash(self::core($values))));
        if ($done instanceof \WP_Error) {
            throw self::error($done);
        }
        $id = (int) $done['term_id'];
        $this->writeMeta($spec, $id, $values);
        clean_term_cache([$id], $taxonomy);

        return $id;
    }

    public function update(string $collection, int|string $pk, array $values): void
    {
        $spec = $this->schema->collection($collection);
        $taxonomy = $spec['wp']['taxonomy'];
        $this->checkUid($spec, $values, (int) $pk);
        self::checkParent($taxonomy, $values);
        if ((int) ($values['parent'] ?? 0) === (int) $pk) {
            throw new HttpError(409, 'reference_violation', 'A term can\'t be its own parent', '/parent');
        }
        $args = self::core($values) + (array_key_exists('name', $values) ? ['name' => (string) $values['name']] : []);
        if ($args !== []) {
            $done = $this->db->core(static fn () => wp_update_term((int) $pk, $taxonomy, wp_slash($args)));
            if ($done instanceof \WP_Error) {
                throw self::error($done);
            }
        }
        $this->writeMeta($spec, (int) $pk, $values);
        clean_term_cache([(int) $pk], $taxonomy);
    }

    /** Core deletes the term, its meta and its links, and moves its children to its parent. */
    public function delete(string $collection, int|string $pk): void
    {
        $taxonomy = $this->taxonomy($collection);
        $done = $this->db->core(static fn () => wp_delete_term((int) $pk, $taxonomy));
        if ($done instanceof \WP_Error) {
            throw self::error($done);
        }
    }

    /** Managed term UIDs have the same transaction-scoped ownership check as post-backed UIDs. */
    private function checkUid(array $spec, array $values, int $self): void
    {
        if (!isset($values['uid']) || !($spec['columns']['uid']['system'] ?? false)) {
            return;
        }
        $wpdb = $this->db->wpdb();
        $hit = $this->db->run(
            "SELECT m.term_id FROM `{$wpdb->termmeta}` m JOIN `{$wpdb->term_taxonomy}` t ON t.term_id = m.term_id WHERE m.meta_key = '_tp_uid' AND m.meta_value = ? AND t.taxonomy = ? AND m.term_id <> ? LIMIT 1 FOR UPDATE",
            [(string) $values['uid'], $spec['wp']['taxonomy'], $self],
        );
        if ($hit !== []) {
            throw new HttpError(409, 'unique_violation', 'A row with this uid already exists', '/uid');
        }
    }

    private function writeMeta(array $spec, int $id, array $values): void
    {
        Scf::$io++;
        try {
            foreach ($values as $col => $stored) {
                $col = (string) $col;
                if ($col === 'uid' && ($spec['columns'][$col]['system'] ?? false)) {
                    $this->db->core(static fn () => update_term_meta($id, '_tp_uid', $stored));
                    if (get_term_meta($id, '_tp_uid', true) !== $stored) {
                        throw new \RuntimeException('Could not persist the term UID');
                    }
                    continue;
                }
                if (!self::isMeta($spec, $col)) {
                    continue;
                }
                $def = $spec['columns'][$col];
                $fieldKey = $spec['wp']['fieldKeyPrefix'] . $col;
                $scf = Scf::toScf($def, $stored, $fieldKey);
                $this->db->core(static fn () => update_field($fieldKey, $scf === null ? null : wp_slash($scf), 'term_' . $id));
            }
        } finally {
            Scf::$io--;
        }
    }

    /** True when the column is an authored field (term meta), not one of the term's own. */
    public static function isMeta(array $spec, string $col): bool
    {
        return isset($spec['columns'][$col]) && !in_array($col, [$spec['primaryKey'], 'name', 'slug', 'description', 'parent', 'count', 'url'], true);
    }

    /** Core's refusals as TypedPress's: a taken name or slug is a 409 at its field, a missing parent a 409 at `parent`. */
    private static function error(\WP_Error $e): HttpError
    {
        return match ($e->get_error_code()) {
            'term_exists' => new HttpError(409, 'unique_violation', $e->get_error_message(), '/name'),
            'duplicate_term_slug' => new HttpError(409, 'unique_violation', $e->get_error_message(), '/slug'),
            'missing_parent', 'invalid_term_id' => new HttpError(409, 'reference_violation', 'No parent term with this id in this taxonomy', '/parent'),
            'empty_term_name', 'invalid_term_name' => HttpError::invalidInput($e->get_error_message(), '/name'),
            default => new HttpError(400, (string) $e->get_error_code(), $e->get_error_message()),
        };
    }

    // ================================================================ an item's terms (Links, `term:<taxonomy>`)

    /** The taxonomy a link key names: `term:topics` → `tp_topics`. */
    private function linkTaxonomy(string $join): string
    {
        return $this->taxonomy(substr($join, strlen('term:')));
    }

    /** The term relationships of one taxonomy as (owner_id, target_id, position), for SQL that reads links. */
    public function linkTable(string $join): string
    {
        $wpdb = $this->db->wpdb();
        $taxonomy = addslashes($this->linkTaxonomy($join));

        return "(SELECT tr.object_id AS `owner_id`, tt.term_id AS `target_id`, tr.term_order AS `position` FROM `{$wpdb->term_relationships}` tr"
            . " JOIN `{$wpdb->term_taxonomy}` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = '$taxonomy')";
    }

    public function links(string $join, string $by, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $column = $by === 'target_id' ? 'l.`target_id`' : 'l.`owner_id`';
        $rows = $this->db->run(
            'SELECT l.`owner_id`, l.`target_id`, l.`position` FROM ' . $this->linkTable($join) . " l WHERE $column IN ("
            . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY l.`owner_id`, l.`position`, l.`target_id`',
            array_values(array_map('intval', $ids)),
        );

        return array_map(static fn (array $r) => ['owner_id' => (int) $r['owner_id'], 'target_id' => (int) $r['target_id'], 'position' => (int) $r['position']], $rows);
    }

    /** An item's whole term set, in this order (core keeps it as `term_order`) and its counts. */
    public function setLinks(string $join, int|string $owner, array $targets): void
    {
        $taxonomy = $this->linkTaxonomy($join);
        $targets = array_values(array_map('intval', $targets));
        $this->db->touchPost((int) $owner);
        if (in_array($taxonomy, ['product_cat', 'product_tag'], true) && get_post_type((int) $owner) === 'product') {
            $product = wc_get_product((int) $owner);
            $product->{$taxonomy === 'product_cat' ? 'set_category_ids' : 'set_tag_ids'}($targets);
            $this->db->core(static fn () => $product->save());
            // Woo owns membership; keep list positions on this product without changing taxonomy-wide sorting.
            $wpdb = $this->db->wpdb();
            foreach ($targets as $position => $target) {
                $this->db->run(
                    "UPDATE `{$wpdb->term_relationships}` SET term_order = ? WHERE object_id = ? AND term_taxonomy_id IN (SELECT term_taxonomy_id FROM `{$wpdb->term_taxonomy}` WHERE taxonomy = ? AND term_id = ?)",
                    [$position, (int) $owner, $taxonomy, $target],
                );
            }
            clean_object_term_cache((int) $owner, 'product');
            return;
        }
        $done = $this->db->core(static fn () => wp_set_object_terms((int) $owner, $targets, $taxonomy, false));
        if ($done instanceof \WP_Error) {
            throw new HttpError(400, (string) $done->get_error_code(), $done->get_error_message());
        }
    }

    public function unlink(string $join, string $by, int|string $id): void
    {
        $taxonomy = $this->linkTaxonomy($join);
        if ($by === 'owner_id') {
            $this->db->core(static fn () => wp_delete_object_term_relationships((int) $id, $taxonomy));

            return;
        }
        // A term going away: core's own delete removes its links; this is for a term removed otherwise.
        foreach (array_unique(array_column($this->links($join, 'target_id', [$id]), 'owner_id')) as $object) {
            $this->db->touchPost((int) $object);
            $this->db->core(static fn () => wp_remove_object_terms((int) $object, [(int) $id], $taxonomy));
        }
    }

    // ================================================================ not kept for terms

    public function globalRead(string $name): ?array
    {
        throw new \LogicException('globals live in options; CompositeStore routes them to WpTableStore');
    }

    public function globalWrite(string $name, array $values): void
    {
        throw new \LogicException('globals live in options; CompositeStore routes them to WpTableStore');
    }

    public function revisions(string $collection, int|string $pk): array
    {
        return []; // taxonomies keep no revisions
    }

    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void
    {
    }

    public function revision(string $collection, int|string $pk, int $revisionId): ?array
    {
        return null;
    }

    public function promoteDue(string $collection, string $nowIso): array
    {
        return []; // terms have no drafts
    }

    public function mapError(\Throwable $e): \Throwable
    {
        return $e;
    }
}
