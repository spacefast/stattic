<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\ColumnRef;
use TypedPress\Dialect;
use TypedPress\HttpError;
use TypedPress\Js;
use TypedPress\Schema;
use TypedPress\Source;
use TypedPress\Store;

/**
 * Post-backed collections (§4.2, §3.5.1): the row is the `wp_posts` row plus its SCF postmeta.
 * Reads run the compiled SQL over wp_posts/wp_postmeta directly (one LEFT JOIN per used meta
 * column); serialized lists are normalised to JSON and group/repeater/blocks are filled from SCF
 * after the query. Writes go through wp_insert_post/wp_update_post + update_field inside the
 * request transaction, with editor hooks muted (Connection::core).
 */
final class WpPostStore implements Store
{
    /** The sync uid (§5.3): hidden meta, not an SCF field. */
    public const UID_META = '_tp_uid';

    /** @var array<string, Source> */
    private array $sources = [];
    /** Trashed posts are read too ({@see withTrash()}). */
    private bool $trashVisible = false;
    /** @var array<string, array> Request-local SQL visibility guards. */
    private array $visibility = [];
    private int $visibilityGeneration = -1;

    /** @var ?array{collection: string, viewer: \TypedPress\Viewer, password: string} */
    private ?array $readPassword = null;

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

    // ================================================================ reads

    public function source(string $collection): Source
    {
        $source = $this->sources[$collection] ??= $this->buildSource($collection);
        if (!$this->trashVisible) {
            return $source;
        }

        return new Source($source->table, str_replace("NOT IN ('trash', ", 'NOT IN (', $source->baseWhere), $source->columns);
    }

    /**
     * Run $fn with trashed posts readable: what wp-admin's own trash, untrash and permanent delete
     * need to know of the row (its status reads as `draft`). Every other read leaves the trash out.
     */
    public function withTrash(callable $fn): mixed
    {
        $previous = $this->trashVisible;
        $this->trashVisible = true;
        try {
            return $fn();
        } finally {
            $this->trashVisible = $previous;
        }
    }

    public function showsUnique(string $collection, string $column, \TypedPress\Viewer $viewer): bool
    {
        return false;
    }

    public function readGuards(string $collection, \TypedPress\Viewer $viewer): array
    {
        if ($this->visibilityGeneration !== CoreAccess::generation()) {
            $this->visibility = [];
            $this->visibilityGeneration = CoreAccess::generation();
        }
        $key = serialize([$collection, $viewer->user, $_COOKIE['wp-postpass_' . COOKIEHASH] ?? null, $this->readPassword]);
        if (array_key_exists($key, $this->visibility)) {
            return $this->visibility[$key];
        }
        $spec = $this->schema->collection($collection);
        // WordPress's own posts: an unpublished post is read only by whom core lets read it (its author,
        // editors), whatever the app's rule says; the rule can only narrow that.
        if ($spec['native'] ?? false) {
            $native = NativePosts::readGuard($this->db, $viewer);
            if ($native !== null) {
                return $this->visibility[$key] = [$native, ...$this->passwordGuards($collection, $viewer, $spec)];
            }
        }
        return $this->visibility[$key] = $this->passwordGuards($collection, $viewer, $spec);
    }

    /** @return list<array|\TypedPress\SqlPredicate> the password-protection guards of a post kind for $viewer */
    private function passwordGuards(string $collection, \TypedPress\Viewer $viewer, array $spec): array
    {
        if ($viewer->user === null) {
            $guard = $this->passwordGuard($collection, $viewer, $spec);
            // Empty guards also certify unrestricted visibility to unique-write checks
            // and the native REST controller. A predicate that cannot hide a row must
            // retain that meaning without materializing every readable primary key.
            $hidden = $this->db->run(
                'SELECT 1 FROM `' . $this->db->wpdb()->posts . '` p WHERE p.post_type = ? AND NOT ('
                . str_replace('{a}', 'p', $guard->sql) . ') LIMIT 1',
                [$spec['wp']['postType'], ...$guard->params],
            );
            return $hidden === [] ? [] : [$guard];
        }
        $hidden = [];
        $acceptedPasswords = [];
        $posts = $this->db->run(
            'SELECT ID FROM `' . $this->db->wpdb()->posts . '` WHERE post_type = ? AND HEX(post_password) NOT IN (?, ?)',
            [$spec['wp']['postType'], '', '30'],
        );
        _prime_post_caches(array_map('intval', array_column($posts, 'ID')), false, false);
        foreach ($posts as $row) {
            $id = (int) $row['ID'];
            $post = get_post($id);
            if (!$post instanceof \WP_Post) {
                throw new HttpError(503, 'storage_unavailable', 'Post storage is unavailable. Please retry.');
            }
            if (!($acceptedPasswords[$post->post_password] ??= $this->passwordAccepted($post, $collection, $viewer))
                && ($viewer->user === null || !user_can($viewer->user['id'], 'edit_post', $id))) {
                $hidden[] = $id;
            }
        }

        return $hidden === [] ? [] : [[$spec['primaryKey'] => ['notIn' => $hidden]]];
    }

    private function passwordGuard(string $collection, \TypedPress\Viewer $viewer, array $spec): \TypedPress\SqlPredicate
    {
        // HEX preserves byte equality, including case and trailing spaces, on both MySQL
        // and the SQLite translator. The site's text collation must not unlock a password.
        $accepted = ['', '30']; // WordPress treats exactly '' and '0' as public.
        $proof = $this->readPassword;
        if ($proof !== null && $proof['collection'] === $collection && $proof['viewer']->user === $viewer->user) {
            $accepted[] = strtoupper(bin2hex($proof['password']));
        }
        $cookie = $_COOKIE['wp-postpass_' . COOKIEHASH] ?? null;
        if (is_string($cookie) && str_starts_with($hash = wp_unslash($cookie), '$P$B')) {
            require_once ABSPATH . WPINC . '/class-phpass.php';
            $hasher = new \PasswordHash(8, true);
            $passwords = $this->db->run(
                'SELECT DISTINCT HEX(post_password) AS password FROM `' . $this->db->wpdb()->posts . '` WHERE post_type = ? AND HEX(post_password) NOT IN (?, ?)',
                [$spec['wp']['postType'], '', '30'],
            );
            foreach ($passwords as $row) {
                if ($hasher->CheckPassword(hex2bin($row['password']), $hash)) {
                    $accepted[] = $row['password'];
                }
            }
        }
        $accepted = array_values(array_unique($accepted));
        return new \TypedPress\SqlPredicate('HEX({a}.post_password) IN (' . implode(', ', array_fill(0, count($accepted), '?')) . ')', $accepted);
    }

    /** Native REST's password proof belongs only to its collection and explicit viewer, including nested dispatches. */
    public function withReadPassword(string $collection, \TypedPress\Viewer $viewer, ?string $password, callable $read): mixed
    {
        $previous = $this->readPassword;
        $this->readPassword = $password === null ? null : ['collection' => $collection, 'viewer' => $viewer, 'password' => $password];
        CoreAccess::flush();
        try {
            return $read();
        } finally {
            $this->readPassword = $previous;
            CoreAccess::flush();
        }
    }

    private function passwordAccepted(\WP_Post $post, string $collection, \TypedPress\Viewer $viewer): bool
    {
        $proof = $this->readPassword;
        if ($proof !== null && $proof['collection'] === $collection && $proof['viewer']->user === $viewer->user
            && hash_equals($post->post_password, $proof['password'])) {
            return true;
        }
        // Match WordPress's post-password cookie, without its REST filter borrowing the ambient user's edit authority.
        $cookie = $_COOKIE['wp-postpass_' . COOKIEHASH] ?? null;
        if (!is_string($cookie) || !str_starts_with($hash = wp_unslash($cookie), '$P$B')) {
            return false;
        }
        require_once ABSPATH . WPINC . '/class-phpass.php';

        return (new \PasswordHash(8, true))->CheckPassword($post->post_password, $hash);
    }

    private function buildSource(string $collection): Source
    {
        $spec = $this->schema->collection($collection);
        $postmeta = $this->db->wpdb()->postmeta;
        $prefix = $spec['wp']['metaPrefix'];
        $m = static fn (string $col) => "LEFT JOIN `$postmeta` {j} ON {j}.post_id = {a}.ID AND {j}.meta_key = '" . addslashes($prefix . $col) . "'";
        $v = "NULLIF({j}.meta_value, '')";
        $refs = [];
        foreach ($spec['columns'] as $col => $def) {
            $col = (string) $col;
            $type = $def['type'];
            $system = $def['system'] ?? false;
            $refs[$col] = match (true) {
                $system && in_array($col, ['locale', 'translations'], true) => Polylang::ref($col, $this->db->prefix(), $this->schema->locales()['all'] ?? []),
                $col === $spec['primaryKey'] => new ColumnRef('{a}.ID', '', '', 'scalar'),
                $col === ($spec['title'] ?? null) => new ColumnRef('{a}.post_title', '', '', 'scalar'),
                $col === ($spec['content'] ?? null) => new ColumnRef('{a}.post_content', '', '', 'scalar'),
                $col === ($spec['slug'] ?? null) => new ColumnRef('{a}.post_name', '', '', 'scalar'),
                $col === ($spec['excerpt'] ?? null) => new ColumnRef("NULLIF({a}.post_excerpt, '')", '', "NULLIF({a}.post_excerpt, '')", 'scalar'),
                $system && $col === 'parent' && ($spec['hierarchical'] ?? false) => new ColumnRef('NULLIF({a}.post_parent, 0)', '', 'NULLIF({a}.post_parent, 0)', 'scalar'),
                $system && $col === 'order' && ($spec['hierarchical'] ?? false) => new ColumnRef('{a}.menu_order', '', '{a}.menu_order', 'scalar'),
                $system && $col === 'status' => new ColumnRef("CASE {a}.post_status WHEN 'publish' THEN 'published' WHEN 'future' THEN 'scheduled' ELSE 'draft' END", '', '', 'scalar'),
                $system && $col === 'uid' => new ColumnRef($v, "LEFT JOIN `$postmeta` {j} ON {j}.post_id = {a}.ID AND {j}.meta_key = '" . self::UID_META . "'", $v, 'scalar'),
                $system && $col === 'publishedAt' => new ColumnRef(
                    "CASE WHEN {a}.post_date_gmt = '0000-00-00 00:00:00' THEN NULL ELSE " . $this->dialect->isoTimestamp('{a}.post_date_gmt') . ' END',
                    '',
                    "NULLIF({a}.post_date_gmt, '0000-00-00 00:00:00')",
                    'scalar',
                ),
                $type === 'integer', $type === 'media', $type === 'file' => new ColumnRef($v, $m($col), "CAST($v AS SIGNED)", 'scalar'),
                $type === 'real' => new ColumnRef($v, $m($col), "CAST($v AS DECIMAL(30,10))", 'scalar'),
                $type === 'timestamp' => new ColumnRef("CONCAT(REPLACE($v, ' ', 'T'), '.000Z')", $m($col), $v, 'scalar'),
                $type === 'date' => new ColumnRef($this->dialect->isoDate($v), $m($col), $v, 'scalar'),
                $type === 'json' => new ColumnRef($v, $m($col), '', 'json'),
                $type === 'select', $type === 'mediaList' => new ColumnRef($v, $m($col), '{j}.meta_value', 'serialized-list'),
                $type === 'refList' => new ColumnRef('NULL', '', '', 'serialized-list'), // read from the join table
                in_array($type, ['group', 'repeater', 'blocks'], true) => new ColumnRef('NULL', $m($col), "NULLIF(NULLIF({j}.meta_value, ''), '0')", 'json'),
                default => new ColumnRef($v, $m($col), $v, 'scalar'),
            };
        }

        if ($spec['localized'] ?? false) {
            $refs['translationGroup'] = Polylang::ref('translationGroup', $this->db->prefix(), []); // the group lives in Polylang
        }

        return new Source(
            '`' . $this->db->wpdb()->posts . '`',
            "{a}.post_type = '" . addslashes($spec['wp']['postType']) . "' AND {a}.post_status NOT IN ('trash', 'auto-draft', 'inherit')",
            $refs,
        );
    }

    public function select(string $collection, array $q): array
    {
        $rows = $this->db->run($q['sql'], $q['params']);
        \TypedPress\Extensions::lookup($collection, array_column($rows, $this->schema->collection($collection)['primaryKey']));

        return $this->finish($collection, $rows);
    }

    /**
     * Post-process raw SQL rows into the §3.4 storage form: serialized arrays → JSON lists;
     * group/repeater/blocks read through SCF (meta cache primed once for the page).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function finish(string $collection, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $spec = $this->schema->collection($collection);
        $pk = $spec['primaryKey'];
        $lists = $nested = [];
        foreach ($spec['columns'] as $col => $def) {
            // A read that selected some columns (`columns`) finishes only those.
            if ($this->isMeta($spec, (string) $col) && array_key_exists($col, $rows[0])) {
                if ($def['type'] === 'select' || $def['type'] === 'mediaList') {
                    $lists[] = (string) $col;
                } elseif (in_array($def['type'], ['group', 'repeater', 'blocks'], true)) {
                    $nested[] = (string) $col;
                }
            }
        }
        foreach ($rows as &$row) {
            foreach ($lists as $col) {
                $row[$col] = self::serializedToJson($row[$col] ?? null, $spec['columns'][$col]['type'] === 'mediaList');
            }
        }
        unset($row);
        if ($nested !== []) {
            update_meta_cache('post', array_column($rows, $pk));
            Scf::$io++;
            try {
                foreach ($rows as &$row) {
                    $id = (int) $row[$pk];
                    foreach ($nested as $col) {
                        $def = $spec['columns'][$col];
                        if (!metadata_exists('post', $id, $spec['wp']['metaPrefix'] . $col)) {
                            $row[$col] = null;
                            continue;
                        }
                        $fieldKey = $spec['wp']['fieldKeyPrefix'] . $col;
                        $row[$col] = Schema::store($def, Scf::fromScf($def, get_field($fieldKey, $id, false), $fieldKey) ?? ($def['type'] === 'group' ? null : []));
                    }
                }
                unset($row);
            } finally {
                Scf::$io--;
            }
        }

        return $rows;
    }

    public static function serializedToJson(mixed $v, bool $ints): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $list = is_string($v) && is_serialized($v) ? @unserialize($v, ['allowed_classes' => false]) : (is_string($v) ? json_decode($v, true) : $v);
        if (!is_array($list)) {
            $list = [$v];
        }
        $list = array_values(array_filter($list, static fn ($x) => $x !== '' && $x !== null));

        return Js::jsonStringify($ints ? array_map('intval', $list) : array_map('strval', $list));
    }

    /**
     * Rows for arbitrary posts (revisions) read through the same mapping as live rows, with
     * $where (aliases: r = the post) replacing the collection's baseWhere.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsWhere(string $collection, string $where, array $params): array
    {
        $src = $this->source($collection);
        $select = $joins = [];
        $i = 0;
        foreach ($src->columns as $name => $ref) {
            $sub = ['{a}' => 'r', '{j}' => 'r_m' . $i++];
            $select[] = strtr($ref->expr, $sub) . ' AS ' . $this->dialect->quote((string) $name);
            if (str_contains($ref->expr, '{j}')) {
                $joins[] = strtr($ref->join, $sub);
            }
        }
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $src->table . ' r ' . implode(' ', $joins) . " WHERE $where ORDER BY r.ID DESC";

        return $this->finish($collection, $this->db->run($sql, $params));
    }

    // ================================================================ writes

    public function insert(string $collection, array $values): int|string
    {
        $spec = $this->schema->collection($collection);
        $this->checkUnique($collection, $values, 0);
        $post = $this->corePost($spec, $values) + [
            'post_type' => $spec['wp']['postType'],
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ];
        $id = $this->db->core(fn () => $this->withStatus($post, fn (array $post) => $this->withSlug($post, static fn () => wp_insert_post(wp_slash($post), true))));
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('wp_insert_post failed: ' . $id->get_error_message());
        }
        $id = (int) $id;
        $this->db->touchPost($id);
        $this->writeMeta($spec, $id, $values);
        if (isset($values['locale']) && ($spec['localized'] ?? false)) {
            $group = json_decode((string) ($values['translations'] ?? '{}'), true) ?: [];
            $this->db->core(fn () => Polylang::assign($this->db, $id, (string) $values['locale'], $group));
        }
        clean_post_cache($id);

        return $id;
    }

    public function update(string $collection, int|string $pk, array $values): void
    {
        $spec = $this->schema->collection($collection);
        $id = (int) $pk;
        $this->checkUnique($collection, $values, $id);
        $post = $this->corePost($spec, $values);
        $this->db->touchPost($id);
        if ($post !== []) {
            $publishing = !in_array(get_post_status($id), ['publish', 'future', 'private'], true)
                && in_array($post['post_status'] ?? null, ['publish', 'future', 'private'], true);
            $preserveSlug = isset($spec['slug']) && !array_key_exists('post_name', $post) && !$publishing;
            if ($preserveSlug) {
                // An omitted/unchanged slug is not input. Native title edits must not repair it
                // against competitors that the caller may not be allowed to read.
                $post['post_name'] = (string) get_post_field('post_name', $id, 'raw');
            }
            $post['ID'] = $id;
            // TypedPress writes the revision itself after the meta is saved (Db::updateOne → revisionWrite);
            // core's own post-update revision would be a second, meta-less one.
            $r = $this->db->core(fn () => $this->withoutCoreRevision(fn () => $this->withStatus($post, fn (array $post) => $this->withSlug($post, static fn () => wp_update_post(wp_slash($post), true), $preserveSlug))));
            if ($r instanceof \WP_Error) {
                throw new \RuntimeException('wp_update_post failed: ' . $r->get_error_message());
            }
        }
        $this->writeMeta($spec, $id, $values);
        clean_post_cache($id);
    }

    public function delete(string $collection, int|string $pk): void
    {
        $this->db->touchPost((int) $pk);
        $this->db->core(static fn () => wp_delete_post((int) $pk, true));
    }

    /** Core wp_posts fields for the columns present in $values (§4.4). */
    private function corePost(array $spec, array $values): array
    {
        $post = [];
        $map = [];
        foreach (['title' => 'post_title', 'content' => 'post_content', 'slug' => 'post_name', 'excerpt' => 'post_excerpt'] as $designation => $field) {
            if (is_string($spec[$designation] ?? null) && $spec[$designation] !== '') {
                $map[$spec[$designation]] = $field;
            }
        }
        foreach ($map as $col => $field) {
            if (array_key_exists($col, $values)) {
                $post[$field] = (string) ($values[$col] ?? '');
            }
        }
        if ($spec['hierarchical'] ?? false) {
            if (array_key_exists('parent', $values)) {
                $post['post_parent'] = (int) ($values['parent'] ?? 0);
            }
            if (array_key_exists('order', $values)) {
                $post['menu_order'] = (int) ($values['order'] ?? 0);
            }
        }
        if (array_key_exists('status', $values) && ($spec['columns']['status']['system'] ?? false)) {
            $post['post_status'] = ['draft' => 'draft', 'published' => 'publish', 'scheduled' => 'future'][$values['status']] ?? 'draft';
        }
        if (array_key_exists('publishedAt', $values) && ($spec['columns']['publishedAt']['system'] ?? false)) {
            $at = $values['publishedAt'];
            if ($at === null) {
                $post['post_date_gmt'] = '0000-00-00 00:00:00';
            } else {
                $gmt = substr((string) $at, 0, 10) . ' ' . substr((string) $at, 11, 8);
                $post['post_date_gmt'] = $gmt;
                $post['post_date'] = get_date_from_gmt($gmt);
            }
            $post['edit_date'] = true;
        }

        return $post;
    }

    /** wp_save_post_revision bails when the post type keeps 0 revisions. */
    private function withoutCoreRevision(callable $fn): mixed
    {
        $none = static fn () => 0;
        add_filter('wp_revisions_to_keep', $none, PHP_INT_MAX);
        try {
            return $fn();
        } finally {
            remove_filter('wp_revisions_to_keep', $none, PHP_INT_MAX);
        }
    }

    /**
     * TypedPress's status is explicit (§6.1): core turns `publish` with a future date into `future` and
     * `future` within a minute into `publish`; keep the status TypedPress asked for (or the post already has). Only this write's
     * postarr (marked with `_tp_status`) is affected, not posts a save hook writes meanwhile.
     */
    private function withStatus(array $post, callable $fn): mixed
    {
        $status = $post['post_status'] ?? (isset($post['ID']) ? get_post_status((int) $post['ID']) : null);
        if (!in_array($status, ['publish', 'future'], true)) {
            return $fn($post);
        }
        $post['post_status'] = $status;
        $marker = $post['_tp_status'] = bin2hex(random_bytes(8));
        $filter = static function (array $data, array $postarr, array $unsanitized) use ($marker, $status): array {
            if (($unsanitized['_tp_status'] ?? null) === $marker) {
                $data['post_status'] = $status;
            }

            return $data;
        };
        add_filter('wp_insert_post_data', $filter, PHP_INT_MAX, 3);
        try {
            return $fn($post);
        } finally {
            remove_filter('wp_insert_post_data', $filter, PHP_INT_MAX);
        }
    }

    /** Pass the slug through verbatim (TypedPress already checked it is unique in this post type). */
    private function withSlug(array $post, callable $fn, bool $preserveEmpty = false): mixed
    {
        if (!isset($post['post_name']) || ($post['post_name'] === '' && !$preserveEmpty)) {
            return $fn();
        }
        // A `wp.posts` kind's slug is WordPress's: it has no unique check of TypedPress's, so core's
        // wp_unique_post_slug makes a taken one unique (`hello-2`), as in the block editor. A kept slug
        // (an update that doesn't change it) is passed through as before.
        $collection = CoreAccess::collection((string) ($post['post_type'] ?? get_post_type((int) ($post['ID'] ?? 0))));
        $spec = $collection === null ? null : $this->schema->collection($collection);
        if ($spec !== null && isset($spec['excerpt']) && !$preserveEmpty) {
            return $fn();
        }
        $slug = $post['post_name'];
        $id = (int) ($post['ID'] ?? 0);
        $filter = static fn ($override, $_slug, $postId) => $postId === $id ? $slug : $override;
        add_filter('pre_wp_unique_post_slug', $filter, 10, 3);
        try {
            return $fn();
        } finally {
            remove_filter('pre_wp_unique_post_slug', $filter);
        }
    }

    private function writeMeta(array $spec, int $id, array $values): void
    {
        if (isset($values['uid']) && ($spec['columns']['uid']['system'] ?? false)) {
            $this->db->core(static fn () => update_post_meta($id, self::UID_META, $values['uid']));
        }
        Scf::$io++;
        try {
            foreach ($values as $col => $stored) {
                $col = (string) $col;
                if (!$this->isMeta($spec, $col)) {
                    continue;
                }
                $def = $spec['columns'][$col];
                $fieldKey = $spec['wp']['fieldKeyPrefix'] . $col;
                $scf = Scf::toScf($def, $stored, $fieldKey);
                $this->db->core(static fn () => update_field($fieldKey, $scf === null ? null : wp_slash($scf), $id));
            }
        } finally {
            Scf::$io--;
        }
    }

    /** True when the column lives in postmeta (an SCF field), not in a wp_posts column. */
    public function isMeta(array $spec, string $col): bool
    {
        if ($col === $spec['primaryKey'] || $col === ($spec['title'] ?? null) || $col === ($spec['content'] ?? null) || $col === ($spec['slug'] ?? null) || $col === ($spec['excerpt'] ?? null)) {
            return false;
        }

        // A many-ref's links live in TypedPress's join table, not in postmeta.
        return !($spec['columns'][$col]['system'] ?? false) && ($spec['columns'][$col]['type'] ?? null) !== 'refList';
    }

    /**
     * unique() on a post-backed column (§3.5.1): no SQL UNIQUE exists on meta, so check inside the
     * request transaction, locking what matched, and raise the same unique_violation.
     */
    private function checkUnique(string $collection, array $values, int $self): void
    {
        $spec = $this->schema->collection($collection);
        $wpdb = $this->db->wpdb();
        $live = "p.post_type = ? AND p.post_status NOT IN ('trash', 'auto-draft', 'inherit') AND p.ID <> ?";
        foreach ($values as $col => $v) {
            $col = (string) $col;
            $def = $spec['columns'][$col] ?? [];
            if ($v === null || !($def['unique'] ?? false) || $col === $spec['primaryKey']) {
                continue;
            }
            $hit = match (true) {
                $col === ($spec['slug'] ?? null) => $this->db->run("SELECT p.ID FROM `{$wpdb->posts}` p WHERE p.post_name = ? AND $live LIMIT 1 FOR UPDATE", [(string) $v, $spec['wp']['postType'], $self]),
                $col === ($spec['title'] ?? null) => $this->db->run("SELECT p.ID FROM `{$wpdb->posts}` p WHERE p.post_title = ? AND $live LIMIT 1 FOR UPDATE", [(string) $v, $spec['wp']['postType'], $self]),
                // A trashed post keeps its uid and can be restored, so it still owns it.
                $col === 'uid' && ($def['system'] ?? false) => $this->db->run(
                    "SELECT m.post_id FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` p ON p.ID = m.post_id WHERE m.meta_key = ? AND m.meta_value = ? AND p.post_type = ? AND p.post_status NOT IN ('auto-draft', 'inherit') AND p.ID <> ? LIMIT 1 FOR UPDATE",
                    [self::UID_META, (string) $v, $spec['wp']['postType'], $self],
                ),
                default => $this->db->run(
                    "SELECT m.post_id FROM `{$wpdb->postmeta}` m JOIN `{$wpdb->posts}` p ON p.ID = m.post_id WHERE m.meta_key = ? AND m.meta_value = ? AND $live LIMIT 1 FOR UPDATE",
                    [$spec['wp']['metaPrefix'] . $col, Scf::metaScalar($def, $v), $spec['wp']['postType'], $self],
                ),
            };
            if ($hit !== []) {
                throw new HttpError(409, 'unique_violation', "A row with this $col already exists", HttpError::seg($col));
            }
        }
    }

    // ================================================================ globals: handled by WpTableStore (options)

    public function globalRead(string $name): ?array
    {
        throw new \LogicException('globals live in options; CompositeStore routes them to WpTableStore');
    }

    public function globalWrite(string $name, array $values): void
    {
        throw new \LogicException('globals live in options; CompositeStore routes them to WpTableStore');
    }

    // ================================================================ revisions (§4.7): WP revisions + SCF meta

    public function revisions(string $collection, int|string $pk): array
    {
        $rows = $this->rowsWhere($collection, "r.post_type = 'revision' AND r.post_parent = ? AND r.post_name NOT LIKE '%autosave%'", [(int) $pk]);

        return array_map(fn (array $row) => $this->revisionEntry($collection, (int) $pk, $row), $rows);
    }

    public function revision(string $collection, int|string $pk, int $revisionId): ?array
    {
        $rows = $this->rowsWhere($collection, "r.post_type = 'revision' AND r.post_parent = ? AND r.ID = ?", [(int) $pk, $revisionId]);

        return $rows === [] ? null : $this->revisionEntry($collection, (int) $pk, $rows[0]);
    }

    /**
     * A revision post can't hold TypedPress's slug (core names it `<id>-revision-v1`) or status (`inherit`),
     * so snapshotRevision keeps them in `_tp_system`; revisions saved before it existed fall back
     * to the parent's current values.
     */
    private function revisionEntry(string $collection, int $pk, array $row): array
    {
        $spec = $this->schema->collection($collection);
        $rid = (int) $row[$spec['primaryKey']];
        $post = get_post($rid);
        $system = json_decode((string) get_metadata('post', $rid, '_tp_system', true), true);
        $system = is_array($system) ? $system : [];
        $keys = $this->snapshotKeys($spec);
        if (array_diff_key($keys, $system) !== []) {
            $system += array_intersect_key($this->rowsWhere($collection, 'r.ID = ?', [$pk])[0] ?? [], $keys);
        }
        $row = array_replace($row, array_intersect_key($system, $keys));
        if ($spec['localized'] ?? false) { // a revision post has no language: its group is the parent's
            $row['translations'] = Js::jsonStringify(Polylang::group($pk, $this->schema->locales()['all'] ?? []) ?: new \stdClass());
        }
        $row[$spec['primaryKey']] = $pk;
        $at = $post instanceof \WP_Post && $post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt : $post?->post_date;

        return [
            'id' => $rid,
            'at' => str_replace(' ', 'T', (string) $at) . '.000Z',
            'userId' => $post instanceof \WP_Post && (int) $post->post_author > 0 ? (int) $post->post_author : null,
            'row' => $row,
        ];
    }

    /** True when the post's row differs from the revision's, ignoring `onUpdate` columns. */
    public function revisionDiffers(string $collection, int $pk, int $revisionId): bool
    {
        $spec = $this->schema->collection($collection);
        $live = $this->rowsWhere($collection, 'r.ID = ?', [$pk])[0] ?? null;
        $rev = $this->revision($collection, $pk, $revisionId)['row'] ?? null;
        if ($live === null || $rev === null) {
            return true;
        }
        foreach ($spec['columns'] as $col => $def) {
            if (!isset($def['onUpdate']) && (string) ($live[$col] ?? '') !== (string) ($rev[$col] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> the row columns a revision post doesn't carry itself */
    private function snapshotKeys(array $spec): array
    {
        $keys = [];
        foreach (['status', 'publishedAt'] as $col) {
            if ($spec['columns'][$col]['system'] ?? false) {
                $keys[$col] = true;
            }
        }
        if (($spec['slug'] ?? null) !== null) {
            $keys[$spec['slug']] = true;
        }
        if ($spec['localized'] ?? false) {
            $keys['locale'] = true; // immutable per row, so a revision's locale is its parent's
        }

        return $keys;
    }

    /** A WP revision of the post as it is now (snapshotRevision fills in its fields, keep-all, §4.7). */
    public function revisionWrite(string $collection, int|string $pk, array $encodedRow, ?int $userId): void
    {
        $id = (int) $pk;
        $rid = $this->db->core(static fn () => _wp_put_post_revision($id));
        if ($rid instanceof \WP_Error || !$rid) {
            throw new \RuntimeException('Could not save a revision of post ' . $id);
        }
        $rid = (int) $rid;
        $this->db->touchPost($rid);
        if ($userId !== null) {
            $this->db->run('UPDATE `' . $this->db->wpdb()->posts . '` SET post_author = ? WHERE ID = ?', [$userId, $rid]);
        }
        clean_post_cache($rid);
    }

    /**
     * `_wp_put_post_revision`, on every save path (TypedPress's, core REST, wp-admin): copy the parent's SCF
     * meta onto the new revision, plus the columns the revision post can't carry, so every revision
     * reads back as the full row it was.
     */
    public function snapshotRevision(string $collection, int $revisionId, int $postId): void
    {
        $this->db->touchPost($revisionId);
        acf_copy_metadata($postId, $revisionId);
        $parent = array_intersect_key($this->rowsWhere($collection, 'r.ID = ?', [$postId])[0] ?? [], $this->snapshotKeys($this->schema->collection($collection)));
        update_metadata('post', $revisionId, '_tp_system', wp_slash(Js::jsonStringify($parent)));
    }

    // ================================================================ staged versions ({@see Staged})

    /**
     * The status of an item's staged revision. Every revision core reads, it reads with status
     * `inherit`: revision lists, the autosaves route the block editor picks the user's autosave from,
     * wp-admin's revision screen and its restore, pruning, the "has the post changed" check, the
     * revision count in REST links and wp-admin's revision upgrade. So none of them sees a staged
     * version. Deleting the item still deletes it: core deletes a post's revisions by type alone.
     */
    public const STAGED_STATUS = 'typedpress-staged';

    /**
     * The post name of an item's one staged revision. It is in core's autosave family, so code that
     * reads revisions by name alone (`wp_is_post_autosave()`) passes over it too. It is not core's
     * own slot (`<id>-autosave-v1`), which the editors overwrite per user and delete when they find
     * it older than the post.
     */
    public static function stagedName(int $pk): string
    {
        return $pk . '-autosave-staged-v1';
    }

    /**
     * Staged revisions written before they had their own status are `inherit`, so core listed them as
     * autosaves. Give them the staged status; nothing else about them changes. A guarded runtime
     * upgrade step ({@see Runtime::boot()}): it runs once per site, and again finds nothing to do.
     */
    public static function upgradeStagedStatus(\TypedPress\Store $store): void
    {
        $db = $store instanceof CompositeStore ? $store->db : null;
        if ($db === null) {
            return;
        }
        $posts = $db->wpdb()->posts;
        foreach ($db->run("SELECT ID, post_parent, post_name FROM `$posts` WHERE post_type = 'revision' AND post_status = 'inherit' AND post_name LIKE ?", ['%-autosave-staged-v1']) as $row) {
            $name = self::stagedName((int) $row['post_parent']);
            if ($row['post_name'] === $name) {
                // The name again: a promote or discard that committed since the read renamed it, and
                // that retired revision must stay `inherit`, in the item's history.
                $db->run("UPDATE `$posts` SET post_status = ? WHERE ID = ? AND post_status = 'inherit' AND post_name = ?", [self::STAGED_STATUS, (int) $row['ID'], $name]);
                clean_post_cache((int) $row['ID']);
            }
        }
    }

    /** The id of the item's staged revision, or null when nothing is staged. */
    public function stagedId(int $pk): ?int
    {
        return $this->stagedIds([$pk])[$pk] ?? null;
    }

    /**
     * Staged revisions by item: of $pks, or of every item of $collection.
     *
     * @param ?list<int> $pks
     * @return array<int, int> item id => staged revision id
     */
    public function stagedIds(?array $pks, ?string $collection = null): array
    {
        $posts = $this->db->wpdb()->posts;
        if ($pks === null) {
            $rows = $this->db->run(
                "SELECT r.post_parent, r.ID, r.post_name FROM `$posts` r JOIN `$posts` p ON p.ID = r.post_parent"
                . " WHERE r.post_type = 'revision' AND r.post_name LIKE ? AND p.post_type = ? ORDER BY r.ID",
                ['%-autosave-staged-v1', $this->schema->collection((string) $collection)['wp']['postType']],
            );
        } elseif ($pks === []) {
            return [];
        } else {
            $rows = $this->db->run(
                "SELECT post_parent, ID, post_name FROM `$posts` WHERE post_type = 'revision' AND post_parent IN ("
                . implode(', ', array_fill(0, count($pks), '?')) . ') AND post_name IN (' . implode(', ', array_fill(0, count($pks), '?')) . ') ORDER BY ID',
                [...array_map('intval', $pks), ...array_map(self::stagedName(...), array_map('intval', $pks))],
            );
        }
        $out = [];
        foreach ($rows as $row) {
            if ($row['post_name'] === self::stagedName((int) $row['post_parent'])) {
                $out[(int) $row['post_parent']] = (int) $row['ID'];
            }
        }

        return $out;
    }

    /**
     * Take the item's row lock for the rest of the transaction and return its staged revision id as
     * committed now. Stagers, promoters and discarders of one item therefore run one at a time, and
     * an item never has two staged revisions. A request whose own snapshot is older than what the
     * lock shows (another request changed the item's post or its staged version first) is a 409 to
     * retry, never a write on top of what it has not read.
     */
    public function stagedLock(int $pk): ?int
    {
        $read = function (string $lock) use ($pk): array {
            $posts = $this->db->wpdb()->posts;
            $postmeta = $this->db->wpdb()->postmeta;
            $item = $this->db->run("SELECT * FROM `$posts` WHERE ID = ?$lock", [$pk]);
            // The item's TypedPress fields live in its meta: a write of only those leaves the post row as it was.
            $prefix = $this->db->wpdb()->esc_like($this->schema->collection((string) CoreAccess::collection((string) get_post_type($pk)))['wp']['metaPrefix'] ?? 'tp_');
            $itemMeta = $this->db->run(
                "SELECT meta_id, meta_key, meta_value FROM `$postmeta` WHERE post_id = ? AND (meta_key LIKE ? OR meta_key LIKE ?) ORDER BY meta_id$lock",
                [$pk, $prefix . '%', '\\_' . $prefix . '%'],
            );
            $staged = $this->db->run("SELECT * FROM `$posts` WHERE post_parent = ? AND post_type = 'revision' AND post_name = ? ORDER BY ID$lock", [$pk, self::stagedName($pk)]);
            $meta = array_map(fn (array $row): array => $this->db->run(
                "SELECT meta_id, meta_key, meta_value FROM `$postmeta` WHERE post_id = ? ORDER BY meta_id$lock",
                [(int) $row['ID']],
            ), $staged);

            return [$item, $itemMeta, $staged, $meta];
        };
        $seen = $read('');
        $now = $read(' FOR UPDATE');
        if (Js::jsonStringify($seen) !== Js::jsonStringify($now)) {
            throw new HttpError(409, 'conflict', 'This item changed while the request ran; read it again and retry');
        }
        $last = end($now[2]);

        return $last === false ? null : (int) $last['ID'];
    }

    /**
     * Write the item's staged revision ($rid, or a new one): the item's row as it is now with
     * $values, the storage form of every staged field, over it. So the revision always reads back as
     * the whole row the item would have once promoted, and wp-admin's revision screen compares it
     * with the live post field by field. Returns the revision id.
     *
     * @param array<string, int|float|string|null> $values
     */
    public function stagedWrite(string $collection, int $pk, ?int $rid, array $values, ?int $userId): int
    {
        $spec = $this->schema->collection($collection);
        $live = $this->rowsWhere($collection, 'r.ID = ?', [$pk])[0] ?? null;
        $parent = get_post($pk);
        if ($live === null || !$parent instanceof \WP_Post) {
            throw new \RuntimeException("Post $pk vanished while staging");
        }
        $row = array_replace($live, $values);
        $field = static fn (string $designation, string $native): string => is_string($spec[$designation] ?? null) && $spec[$designation] !== ''
            ? (string) ($row[$spec[$designation]] ?? '') : (string) $parent->$native;
        $post = [
            'post_type' => 'revision', 'post_status' => self::STAGED_STATUS, 'post_parent' => $pk, 'post_name' => self::stagedName($pk),
            'post_title' => $field('title', 'post_title'), 'post_content' => $field('content', 'post_content'), 'post_excerpt' => $field('excerpt', 'post_excerpt'),
            'post_author' => $userId ?? 0, 'post_date' => current_time('mysql'), 'post_date_gmt' => current_time('mysql', true),
        ] + ($rid === null ? [] : ['ID' => $rid]);
        $id = $this->db->core(static fn () => $rid === null ? wp_insert_post(wp_slash($post), true) : wp_update_post(wp_slash($post), true));
        if ($id instanceof \WP_Error || !$id) {
            throw new \RuntimeException('Could not save the staged version of post ' . $pk);
        }
        $id = (int) $id;
        $this->db->touchPost($id);
        $system = Js::jsonStringify(array_intersect_key($row, $this->snapshotKeys($spec)));
        $this->db->core(static function () use ($pk, $id, $system): void {
            acf_copy_metadata($pk, $id);
            update_metadata('post', $id, '_tp_system', wp_slash($system));
        });
        $this->writeMeta($spec, $id, array_diff_key($values, ['uid' => true]));
        clean_post_cache($id);

        return $id;
    }

    /**
     * A staged revision that was promoted or discarded becomes an ordinary revision of the item, with
     * core's name and status: it stays in the item's history and no longer answers as its staged version.
     */
    public function stagedRetire(int $pk, int $rid): void
    {
        $this->db->touchPost($rid);
        $this->db->run('UPDATE `' . $this->db->wpdb()->posts . "` SET post_name = ?, post_status = 'inherit' WHERE ID = ? AND post_parent = ?", [$pk . '-revision-v1', $rid, $pk]);
        clean_post_cache($rid);
    }

    /** WP cron publishes `future` posts (§6.3); reads see the new status through the CASE mapping. */
    public function promoteDue(string $collection, string $nowIso): array
    {
        return [];
    }

    public function mapError(\Throwable $e): \Throwable
    {
        return $e;
    }
}
