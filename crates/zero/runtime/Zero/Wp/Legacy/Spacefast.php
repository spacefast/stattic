<?php

declare(strict_types=1);
namespace Zero\Wp\Legacy;

use Zero\HttpError;
use Zero\Schema;
use Zero\Wp\Connection;

/** Cross-prefix copies under the host's publication fence; source rows are never changed. */
final class Spacefast
{
    /** The member column migrated apps read `ctx.auth.userId` from (`zero migrate app --users`). */
    public const SUBJECT_COLUMN = 'spacefastSubject';
    private const SUBJECT_META = \Zero\Wp\WpUserStore::META . self::SUBJECT_COLUMN;
    /** Receipt prefix for content fingerprints; bare 64-hex receipts predate it and use counters(). */
    private const CONTENT = 'v2:';
    /** Source usermeta the copier leaves behind. */
    private const UNCOPIED_META = ['_spacefast_app_user', Users::MIGRATED_META, self::SUBJECT_META];

    public function __construct(private readonly Connection $db, private readonly Schema $schema) {}

    public function run(string $space, string $source, string $target, bool $dry, bool $verify = false): array
    {
        if ($space === '' || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/', $source)
            || $target !== 'sfz_' . substr(hash('sha256', $space), 0, 24) . '_' || $source === $target
            || $this->db->prefix() !== $target) {
            throw new HttpError(400, 'invalid_command', 'Bind WordPress to the Space target prefix before migrate-spacefast; source and target must be distinct and target must match the Space.', '/target-prefix');
        }
        if ($this->db->inTransaction()) {
            throw new \LogicException('Spacefast migration requires its own per-group transactions');
        }
        $lock = 'zero-spacefast-' . substr(hash('sha256', $target), 0, 40);
        if ((int) ($this->db->run('SELECT GET_LOCK(?, 10) AS acquired', [$lock])[0]['acquired'] ?? 0) !== 1) {
            throw new HttpError(409, 'migration_busy', 'Another Space data migration is running.');
        }
        try {
            return $this->copy($space, $source, $target, $dry, $verify);
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function copy(string $space, string $source, string $target, bool $dry, bool $verify): array
    {
        $receipts = $target . 'zero_spacefast_migrations';
        $legacy = $target . 'zero_identity_legacy_sessions';
        [$posts, $ids, $users] = $this->scope($space, $source);
        if ($ids !== [] && $this->db->run("SELECT comment_ID FROM `{$source}comments` WHERE comment_post_ID IN ({$this->marks($ids)}) LIMIT 1", $ids) !== []) {
            throw new HttpError(409, 'zero_spacefast_unsupported_comments', 'This Space has comments; migrate-spacefast does not copy comments.', '/comments');
        }
        if (!$dry && !$verify) {
            $this->db->run("CREATE TABLE IF NOT EXISTS `$receipts` (space_id varchar(191) NOT NULL, table_group varchar(20) NOT NULL, completed_at bigint NOT NULL, fingerprint varchar(128) NOT NULL, PRIMARY KEY(space_id,table_group)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->db->run("CREATE TABLE IF NOT EXISTS `$legacy` (session_id varbinary(32) NOT NULL, pending tinyint NOT NULL DEFAULT 1, PRIMARY KEY(session_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        foreach ([$receipts, $legacy] as $table) {
            if ($this->exists($table)) { $this->transactional($table); }
        }
        $groups = [
            'posts' => ['posts', 'postmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships'],
            'users' => ['users', 'usermeta'],
        ];
        foreach ($groups as $tables) {
            foreach ($tables as $table) { $this->transactional($source . $table); $this->transactional($target . $table); }
        }
        foreach (['accounts', 'emails', 'providers', 'sessions'] as $table) {
            if ($users !== [] && $this->exists($source . 'sfi_' . $table)) {
                $this->transactional($source . 'sfi_' . $table);
                $this->transactional($target . 'zero_identity_' . $table);
            }
        }
        $report = ['space' => $space, 'sourcePrefix' => $source, 'targetPrefix' => $target, 'dryRun' => $dry, 'groups' => []];
        foreach (['posts', 'users', 'sessions'] as $group) {
            try {
            $report['groups'][$group] = $this->db->transaction(function () use ($space, $source, $target, $dry, $verify, $group, $receipts, $legacy) {
                $stored = $this->exists($receipts) ? $this->db->run("SELECT fingerprint FROM `$receipts` WHERE space_id=? AND table_group=?" . ($verify ? '' : ' FOR UPDATE'), [$space, $group]) : [];
                [$posts, $ids, $users] = $this->scope($space, $source);
                // A users receipt written before the candidate carried subjects would otherwise hide
                // that no subject was copied, and every ctx.auth.userId would silently read wp:<id>.
                // Checked before the fingerprint, so a stale receipt reports what it is missing.
                $missing = $group === 'users' && $stored !== [] ? $this->uncopiedSubjects($space, $source, $target, $users) : [];
                if ($verify) {
                    $fingerprint = $stored[0]['fingerprint'] ?? null;
                    // A receipt is compared in the format it was written in, so receipts from before
                    // content fingerprints keep verifying while their source is unchanged.
                    $current = $fingerprint !== null && !str_starts_with($fingerprint, self::CONTENT)
                        ? $this->counters($group, $source, $ids, $users)
                        : $this->fingerprint($group, $space, $source, $posts, $users);
                    $status = match (true) {
                        $fingerprint === null => 'missing',
                        $missing !== [] => 'incomplete',
                        hash_equals($fingerprint, $current) => 'verified',
                        default => 'drifted',
                    };
                    return ['status' => $status, 'stored' => $fingerprint, 'current' => $current];
                }
                if ($missing !== []) {
                    throw new HttpError(409, 'zero_spacefast_subjects_missing', "Users were copied before this candidate carried Space Users subjects. Restore the target's users and usermeta from the backup taken before the copy, delete the `$receipts` row for space_id '$space' and table_group 'users', and rerun.", '/users/' . $missing[0]);
                }
                if ($stored !== []) {
                    return ['copied' => 0, 'status' => 'already-migrated'];
                }
                $current = $this->fingerprint($group, $space, $source, $posts, $users);
                $count = match ($group) {
                    'posts' => $this->posts($source, $target, $posts, $ids),
                    'users' => $this->users($space, $source, $target, $users),
                    'sessions' => $this->sessions($source, $target, $legacy, $users, $dry),
                };
                if ($dry) { throw new SpacefastDryRun(['copied' => $count, 'status' => 'dry-run']); }
                $this->db->run("INSERT INTO `$receipts` (space_id,table_group,completed_at,fingerprint) VALUES (?,?,?,?)", [$space, $group, time(), $current]);
                return ['copied' => $count, 'status' => 'copied'];
            });
            } catch (SpacefastDryRun $rollback) { $report['groups'][$group] = $rollback->report; }
        }
        if ($verify && array_filter($report['groups'], static fn ($g) => $g['status'] !== 'verified') !== []) {
            throw new SpacefastDrift($report['groups']);
        }
        return $verify ? ['groups' => $report['groups']] : $report;
    }

    private function scope(string $space, string $source): array
    {
        $seed = array_map('intval', array_column($this->db->run("SELECT DISTINCT post_id FROM `{$source}postmeta` WHERE meta_key=? AND meta_value=?", ['_spacefast_space_id', $space]), 'post_id'));
        $posts = $seed === [] ? [] : $this->db->run("SELECT * FROM `{$source}posts` WHERE ID IN ({$this->marks($seed)}) OR (post_parent IN ({$this->marks($seed)}) AND post_type IN ('revision','attachment')) ORDER BY ID", [...$seed, ...$seed]);
        $ids = array_column($posts, 'ID');
        $users = array_map('intval', array_column($this->db->run("SELECT DISTINCT user_id FROM `{$source}usermeta` WHERE meta_key=? AND meta_value=?", ['_spacefast_app_user', $space]), 'user_id'));
        $users = array_values(array_unique([...$users, ...array_filter(array_map('intval', array_column($posts, 'post_author')))]));
        return [$posts, $ids, $users];
    }

    /**
     * Digest of every source row a group copies, so --verify sees any change to copied data.
     * Rows are hashed as an order-independent multiset per table, with values as stored bytes.
     */
    private function fingerprint(string $group, string $space, string $source, array $posts, array $users): string
    {
        $tables = match ($group) {
            'posts' => $this->postRows($source, $posts),
            'users' => $this->userRows($source, $users) + $this->subjectRows($source, $space, $users),
            'sessions' => ['sfi_sessions' => $this->identityRows($source, 'sessions', $users)],
        };
        $digest = hash_init('sha256');
        foreach ($tables as $table => $rows) {
            // serialize() keeps binary columns (session IDs, hashes) byte-exact; drivers differ on int/string.
            $lines = array_map(static fn (array $row) => serialize(array_map(static fn ($v) => $v === null ? null : (string) $v, $row)), $rows);
            sort($lines, SORT_STRING);
            hash_update($digest, $table . "\n" . count($lines) . "\n" . implode("\n", $lines) . "\n");
        }
        return self::CONTENT . hash_final($digest);
    }

    /** Receipts written before content fingerprints: aggregate counters only. Keep this formula unchanged. */
    private function counters(string $group, string $source, array $posts, array $users): string
    {
        $ids = $group === 'posts' ? $posts : $users;
        $values = [];
        if ($ids !== []) {
            $where = $this->marks($ids);
            $values = match ($group) {
                'posts' => [
                    $this->db->run("SELECT COUNT(*) AS count, MAX(post_modified_gmt) AS modified FROM `{$source}posts` WHERE ID IN ($where)", $ids)[0],
                    $this->db->run("SELECT MAX(meta_id) AS meta FROM `{$source}postmeta` WHERE post_id IN ($where)", $ids)[0],
                    $this->db->run("SELECT COUNT(*) AS relationships FROM `{$source}term_relationships` WHERE object_id IN ($where)", $ids)[0],
                ],
                'users' => [
                    $this->db->run("SELECT COUNT(*) AS count, MAX(ID) AS id FROM `{$source}users` WHERE ID IN ($where)", $ids)[0],
                    $this->db->run("SELECT MAX(umeta_id) AS meta FROM `{$source}usermeta` WHERE user_id IN ($where)", $ids)[0],
                ],
                'sessions' => $this->exists($source . 'sfi_sessions') ? $this->db->run("SELECT COUNT(*) AS count, MAX(id) AS id, MAX(revoked_at) AS revoked FROM `{$source}sfi_sessions` WHERE wp_user_id IN ($where)", $ids) : [],
            };
        }
        return hash('sha256', \Zero\Json::encode($values, JSON_THROW_ON_ERROR));
    }

    /** The source rows the posts group copies, keyed by table. */
    private function postRows(string $source, array $posts): array
    {
        $ids = array_column($posts, 'ID');
        $relationships = $this->rows($source . 'term_relationships', 'object_id', $ids);
        $taxonomies = $this->rows($source . 'term_taxonomy', 'term_taxonomy_id', array_column($relationships, 'term_taxonomy_id'));
        do {
            $parents = array_values(array_diff(array_filter(array_column($taxonomies, 'parent')), array_column($taxonomies, 'term_id')));
            $ancestors = $this->rows($source . 'term_taxonomy', 'term_id', $parents);
            $taxonomies = [...$taxonomies, ...$ancestors];
        } while ($ancestors !== []);
        $terms = $this->rows($source . 'terms', 'term_id', array_column($taxonomies, 'term_id'));
        return [
            'posts' => $posts,
            'postmeta' => $this->rows($source . 'postmeta', 'post_id', $ids),
            'term_relationships' => $relationships,
            'term_taxonomy' => $taxonomies,
            'terms' => $terms,
            'termmeta' => $this->rows($source . 'termmeta', 'term_id', array_column($terms, 'term_id')),
        ];
    }

    /** The source rows the users group copies (or reads, for verified emails), keyed by table. */
    private function userRows(string $source, array $ids): array
    {
        $tables = [
            'users' => $this->rows($source . 'users', 'ID', $ids),
            'usermeta' => array_values(array_filter($this->rows($source . 'usermeta', 'user_id', $ids), static fn ($row) => !in_array($row['meta_key'], self::UNCOPIED_META, true))),
        ];
        foreach (['accounts', 'emails', 'providers'] as $table) { $tables['sfi_' . $table] = $this->identityRows($source, $table, $ids); }
        return $tables;
    }

    /**
     * The Space Users subjects the users group copies, as a table of their own. Absent when there
     * are none, so a Space without subjects hashes exactly as it did before subjects were copied.
     */
    private function subjectRows(string $source, string $space, array $ids): array
    {
        $rows = [];
        foreach ($this->subjects($source, $space, $ids) as $id => $subject) { $rows[] = ['user_id' => $id, 'subject' => $subject]; }
        return $rows === [] ? [] : ['subjects' => $rows];
    }

    private function identityRows(string $source, string $table, array $ids): array
    {
        return $this->exists($source . 'sfi_' . $table) ? $this->rows($source . 'sfi_' . $table, 'wp_user_id', $ids) : [];
    }

    private function posts(string $source, string $target, array $posts, array $ids): int
    {
        if ($ids === []) { return 0; }
        ['postmeta' => $postmeta, 'term_relationships' => $relationships, 'term_taxonomy' => $taxonomies, 'terms' => $terms, 'termmeta' => $termmeta] = $this->postRows($source, $posts);
        $resources = [];
        foreach ($relationships as $relationship) {
            foreach ($taxonomies as $taxonomy) {
                if ($taxonomy['term_taxonomy_id'] != $relationship['term_taxonomy_id'] || $taxonomy['taxonomy'] !== Model::TAXONOMY) { continue; }
                foreach ($termmeta as $meta) {
                    if ($meta['term_id'] == $taxonomy['term_id'] && $meta['meta_key'] === Model::RESOURCE_META) { $resources[$relationship['object_id']] = $meta['meta_value']; }
                }
            }
        }
        $specs = [];
        foreach ($this->schema->collections() as $storage => $spec) { $specs[$spec['key'] ?? $storage] = $spec; }
        foreach ($posts as &$post) {
            if ($post['post_type'] === 'attachment') { continue; }
            if (!in_array($post['post_type'], ['post', 'revision'], true)) {
                throw Model::refuse('/records/' . $post['ID'], 'only posts, revisions and attachments can be copied; port pages separately');
            }
            $original = $post['post_type'] === 'revision' ? $post['post_parent'] : $post['ID'];
            $resource = $resources[$original] ?? 'posts';
            $spec = $specs[$resource] ?? null;
            if (!isset($spec['wp']['postType'])) { throw Model::refuse('/records/' . $post['ID'], "post collection $resource has no candidate mapping"); }
            if ($post['post_type'] === 'post') { $post['post_type'] = $spec['wp']['postType']; }
            $values = ['id' => (int) $post['ID']];
            foreach (['title' => 'post_title', 'content' => 'post_content', 'slug' => 'post_name'] as $designation => $native) {
                $column = $spec[$designation] ?? null;
                if ($column === null) { continue; }
                $definition = $spec['columns'][$column];
                $value = Schema::normalize($definition, $post[$native], $column, '/records/' . $post['ID'] . '/' . $column);
                $values[$column] = $value;
            }
            foreach ($postmeta as &$field) {
                $prefix = '_zero_' . $resource . '_';
                if ($field['post_id'] != $post['ID'] || !str_starts_with($field['meta_key'], $prefix)) { continue; }
                $column = substr($field['meta_key'], strlen($prefix));
                if (!isset($spec['columns'][$column]) && isset($spec['columns'][$column . 'Id']['references'])) { $column .= 'Id'; }
                if (!isset($spec['columns'][$column])) { throw Model::refuse('/records/' . $post['ID'] . '/' . $column, 'legacy field has no candidate column'); }
                if (in_array($column, [$spec['title'] ?? null, $spec['content'] ?? null, $spec['slug'] ?? null], true)) { continue; }
                $def = $spec['columns'][$column];
                $value = maybe_unserialize($field['meta_value']);
                if (($def['type'] ?? '') === 'boolean') {
                    if (!in_array($value, ['1', 'true', 1, true, '0', 'false', 0, false, ''], true)) { throw Model::refuse('/records/' . $post['ID'] . '/' . $column, 'invalid legacy boolean'); }
                    $value = in_array($value, ['1', 'true', 1, true], true);
                }
                if (in_array($def['type'] ?? '', ['integer','real'], true) && is_numeric($value)) { $value = $def['type'] === 'integer' ? (int) $value : (float) $value; }
                $value = Schema::normalize($def, $value, $column, '/records/' . $post['ID'] . '/' . $column);
                $values[$column] = $value;
                if ($value !== null && isset($def['references']) && !in_array((string) $value, array_map('strval', $ids), true)) {
                    throw Model::refuse('/records/' . $post['ID'] . '/' . $column, 'reference leaves the migrated Space');
                }
                $field['meta_key'] = 'zero_' . $column;
                $field['meta_value'] = Schema::store($def, $value);
            }
            unset($field);
            $data = $values + array_fill_keys(array_keys($spec['columns']), null);
            foreach ($values as $column => $value) {
                Schema::checkRules($spec['columns'][$column], $value, $column, '/records/' . $post['ID'] . '/' . $column, ['data' => $data, 'user' => null], $spec['columns']);
            }
        }
        unset($post);
        $count = 0;
        foreach (['terms' => $terms, 'term_taxonomy' => $taxonomies, 'termmeta' => $termmeta, 'posts' => $posts, 'postmeta' => $postmeta, 'term_relationships' => $relationships] as $table => $rows) {
            foreach ($rows as $row) { $count += $this->insert($target . $table, $row); }
        }
        return $count;
    }

    private function users(string $space, string $source, string $target, array $ids): int
    {
        $count = 0;
        foreach ($this->rows($source . 'users', 'ID', $ids) as $row) {
            if ($this->exists($source . 'sfi_accounts')) {
                $email = $this->db->run("SELECT e.normalized,e.verified_at FROM `{$source}sfi_accounts` a JOIN `{$source}sfi_emails` e ON e.id=a.primary_email_id AND e.wp_user_id=a.wp_user_id WHERE a.wp_user_id=?", [$row['ID']]);
                if ($email !== [] && (int) $email[0]['verified_at'] > 0) { $row['user_email'] = $email[0]['normalized']; }
            }
            $count += $this->insert($target . 'users', $row);
            // Identity versions with fixed-cost verification must know imported hash profiles
            // before the first sign-in, including a sign-in for a missing account.
            if (is_callable([\Zero\Identity\Accounts::class, 'rememberPasswordHash'])) {
                $this->db->touchOption('zero_identity_password_profiles');
                \Zero\Identity\Accounts::rememberPasswordHash($row['user_pass']);
            }
        }
        foreach ($this->rows($source . 'usermeta', 'user_id', $ids) as $row) {
            if (str_starts_with($row['meta_key'], $source)) { $row['meta_key'] = $target . substr($row['meta_key'], strlen($source)); }
            if (in_array($row['meta_key'], self::UNCOPIED_META, true)) { continue; }
            $count += $this->insert($target . 'usermeta', $row);
        }
        // 0.8 handlers saw a Space User as its random `usr_` subject. A migrated app reads it back from
        // the member's hidden `spacefastSubject` column, so rows owned by that subject keep their owner.
        if ($this->carriesSubjects()) {
            foreach ($this->subjects($source, $space, $ids) as $id => $subject) {
                $existing = $this->db->run("SELECT meta_value FROM `{$target}usermeta` WHERE user_id=? AND meta_key=? FOR UPDATE", [$id, self::SUBJECT_META]);
                $stored = $existing === [] ? null : (string) $existing[0]['meta_value'];
                if ($stored !== null && $stored !== $subject) { throw new HttpError(409, 'zero_spacefast_conflict', "Target user $id already has a different Space subject; nothing in this group was committed.", '/users/' . $id); }
                if ($stored === null) {
                    $this->db->run("INSERT INTO `{$target}usermeta` (user_id, meta_key, meta_value) VALUES (?, ?, ?)", [$id, self::SUBJECT_META, $subject]);
                    $count++;
                }
            }
        }
        foreach (['accounts','emails','providers'] as $table) {
            foreach ($this->identityRows($source, $table, $ids) as $row) { $count += $this->insert($target . 'zero_identity_' . $table, $row); }
        }
        return $count;
    }

    private function sessions(string $source, string $target, string $legacy, array $ids, bool $dry): int
    {
        if (!$this->exists($source . 'sfi_sessions')) { return 0; }
        $count = 0;
        foreach ($this->rows($source . 'sfi_sessions', 'wp_user_id', $ids) as $row) {
            $inserted = $this->insert($target . 'zero_identity_sessions', $row);
            if ($inserted === 0) { throw new HttpError(409, 'zero_spacefast_conflict', 'Session ID already exists outside this migration.', '/sessions/' . $row['id']); }
            $count += $inserted;
            if (!$dry) { $this->insert($legacy, ['session_id' => $row['id'], 'pending' => 1]); }
        }
        return $count;
    }

    private function insert(string $table, array $row): int
    {
        $columns = $this->db->run("SHOW COLUMNS FROM `$table`");
        $keys = array_column(array_filter($columns, static fn ($c) => $c['Key'] === 'PRI'), 'Field');
        $names = array_keys($row);
        if (array_diff($names, array_column($columns, 'Field')) !== [] || $keys === []) { throw Model::refuse('/tables/' . $table, 'source and target columns do not match'); }
        $where = implode(' AND ', array_map(static fn ($key) => "`$key` <=> ?", $keys));
        $existing = $this->db->run("SELECT * FROM `$table` WHERE $where FOR UPDATE", array_map(static fn ($key) => $row[$key], $keys));
        if ($existing !== []) {
            foreach ($row as $key => $value) {
                if (($existing[0][$key] === null) !== ($value === null) || (string) $existing[0][$key] !== (string) $value) { throw new HttpError(409, 'zero_spacefast_conflict', "Target row conflicts in $table; nothing in this group was committed.", '/tables/' . $table); }
            }
            return 0;
        }
        $this->db->run("INSERT INTO `$table` (`" . implode('`,`', $names) . "`) VALUES ({$this->marks($names)})", array_values($row));
        return 1;
    }

    /** Space Users subjects, keyed by user ID, from the source site's options. */
    private function subjects(string $source, string $space, array $ids): array
    {
        if ($ids === []) { return []; }
        $names = array_map(static fn ($id) => 'spacefast_app_subject_' . (int) $id . '_' . $space, $ids);
        $out = [];
        foreach ($this->db->run("SELECT option_name, option_value FROM `{$source}options` WHERE option_name IN ({$this->marks($names)})", $names) as $row) {
            if (preg_match('/\Ausr_[a-f0-9]{64}\z/', (string) $row['option_value']) !== 1) {
                throw Model::refuse('/options/' . $row['option_name'], 'Space Users subject is not usr_<64 hex>');
            }
            $out[(int) substr($row['option_name'], strlen('spacefast_app_subject_'))] = (string) $row['option_value'];
        }
        ksort($out);
        return $out;
    }

    /** User IDs whose source subject is absent from the target, when the candidate carries subjects. */
    private function uncopiedSubjects(string $space, string $source, string $target, array $ids): array
    {
        if (!$this->carriesSubjects()) { return []; }
        $missing = [];
        foreach ($this->subjects($source, $space, $ids) as $id => $subject) {
            $stored = $this->db->run("SELECT meta_value FROM `{$target}usermeta` WHERE user_id=? AND meta_key=?", [$id, self::SUBJECT_META]);
            if ($stored === [] || (string) $stored[0]['meta_value'] !== $subject) { $missing[] = $id; }
        }
        return $missing;
    }

    /** True when the candidate's member projection declares the migrated subject column. */
    private function carriesSubjects(): bool
    {
        $collection = $this->schema->auth()['collection'] ?? null;
        return $collection !== null && isset($this->schema->collection($collection)['columns'][self::SUBJECT_COLUMN]);
    }

    private function marks(array $values): string { return implode(',', array_fill(0, count($values), '?')); }
    private function rows(string $table, string $key, array $ids): array { return $ids === [] ? [] : $this->db->run("SELECT * FROM `$table` WHERE `$key` IN ({$this->marks($ids)})", $ids); }
    private function exists(string $table): bool { return $this->db->run('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]) !== []; }
    private function transactional(string $table): void {
        $row = $this->db->run('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if (($row[0]['ENGINE'] ?? '') !== 'InnoDB') { throw Model::refuse('/tables/' . $table, 'migration requires existing InnoDB source and target tables'); }
    }
}

final class SpacefastDryRun extends \RuntimeException
{
    public function __construct(public readonly array $report) { parent::__construct('rollback dry run'); }
}

final class SpacefastDrift extends HttpError
{
    public function __construct(public readonly array $groups)
    {
        parent::__construct(409, 'zero_spacefast_drift', 'Source data differs from the migration receipts.', '/groups');
    }
}
