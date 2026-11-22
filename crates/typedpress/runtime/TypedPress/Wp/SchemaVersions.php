<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\Json;
use TypedPress\RuntimeSchema\Changes;
use TypedPress\RuntimeSchema\Loader;
use TypedPress\RuntimeSchema\Refused;

/**
 * The site's schema versions (spec §2.10): an append-only table of numbered changes, and the
 * autoloaded option that holds the state after the newest one for {@see Loader} to read.
 *
 * - A version row is written once and never updated or deleted: who, when, the change set in
 *   resolved form, the build it was validated on, its parent, and a hash chained to the parent's.
 * - {@see apply()} names the version the change was made against. Under one named lock it checks
 *   that this is still the newest, inserts the next and writes the option. A second change on the
 *   same parent is a 409. The primary key refuses a duplicate even without the lock.
 * - The option is a copy: replaying the rows gives it again ({@see Changes::fold()}), and apply
 *   does that when the two disagree.
 *
 * The table exists only on sites whose app set `runtimeSchema: true` and applied a change. A new
 * version takes effect from the next request: post types, meta and field groups register on `init`.
 */
final class SchemaVersions
{
    private const TABLE = 'tp__schema_versions';

    private function __construct(private readonly Plugin $plugin)
    {
    }

    /** Null unless the app set `runtimeSchema: true`: without the flag nothing here exists. */
    public static function of(Plugin $plugin): ?self
    {
        return $plugin->schema->runtimeSchema() === null ? null : new self($plugin);
    }

    /** `{version, hash}` of the version this request runs; version 0 before any change. */
    public function active(): array
    {
        $active = $this->plugin->schema->runtimeSchema()['active'] ?? [];

        return ['version' => (int) ($active['version'] ?? 0), 'hash' => (string) ($active['hash'] ?? '')];
    }

    /**
     * Validates a change set against the schema this request runs and returns what applying it
     * would do. Writes nothing.
     *
     * @return array{parent: int, changes: list<array>, effects: list<array>}
     * @throws Refused
     */
    public function propose(mixed $changeSet): array
    {
        $state = Loader::state($this->plugin->schema->name());
        $resolved = Changes::resolve($this->compiled(), $state, $changeSet, Loader::references(), Loader::declared($this->plugin->schema->name()));

        return ['parent' => (int) $state['version'], 'changes' => $resolved['changes'], 'effects' => $this->effects($resolved['changes'], $resolved['state'])];
    }

    /**
     * Stores a change set as version `$parent + 1` and makes it the active one.
     *
     * @param array{user: int, login: ?string, via: string, credential?: array{uuid: string, name: string}} $actor
     * @return array the stored version ({@see row()})
     * @throws Refused 409 `schema_conflict` when `$parent` is no longer the newest version
     */
    public function apply(int $parent, mixed $changeSet, array $actor): array
    {
        $db = $this->plugin->connection();
        if ($db->inTransaction()) {
            throw new \LogicException('A schema version is applied outside a TypedPress transaction');
        }
        $app = $this->plugin->schema->name();

        // The lock a deploy holds (Plugin::schemaLock): a change never lands half way through one.
        return Migrator::locked($db, $this->plugin->schemaLock(), function () use ($db, $app, $parent, $changeSet, $actor): array {
            if (!$this->plugin->buildIsCurrent()) {
                // A deploy replaced the build while this request waited: the change was checked against
                // the old one. The next request runs the new build; propose the change there.
                throw new Refused('schema_build_changed', 'A new build was deployed while this change waited for the schema lock. Read the schema again and propose the change on the new build', null, '/changes', 409);
            }
            $db->applySystemStatements(['CREATE TABLE IF NOT EXISTS `{prefix}' . self::TABLE . '` (`app` VARCHAR(191) NOT NULL, `version` BIGINT NOT NULL, `parent` BIGINT NOT NULL, `format` INT NOT NULL, `build` CHAR(64) NOT NULL, `actor` LONGTEXT NOT NULL, `changes` LONGTEXT NOT NULL, `hash` CHAR(64) NOT NULL, `createdAt` CHAR(24) NOT NULL, PRIMARY KEY (`app`, `version`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin']);
            $rows = $this->verified();
            $newest = $rows === [] ? ['version' => 0, 'hash' => ''] : end($rows);
            if ($parent !== $newest['version']) {
                throw new Refused('schema_conflict', "The schema is at version {$newest['version']}, not $parent: another change was applied first. Read the schema and propose the change again", null, '/parent', 409);
            }
            // This request loaded the option when it started; another may have written since.
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete(Loader::option($app), 'options');
            $state = Loader::state($app);
            if ((int) ($state['version'] ?? 0) !== $newest['version'] || ($state['format'] ?? null) !== Loader::FORMAT || ($state['seal'] ?? null) !== Loader::seal($state)) {
                $state = $this->replay($rows);
            }
            $resolved = Changes::resolve($this->compiled(), $state, $changeSet, Loader::references(), Loader::declared($this->plugin->schema->name()));
            $row = ['app' => $app, 'version' => $parent + 1, 'parent' => $parent, 'format' => Loader::FORMAT, 'build' => $this->plugin->buildHash(),
                'actor' => $actor, 'changes' => $resolved['changes'], 'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z')];
            $row['hash'] = self::hashOf($newest['hash'], $row);
            $state = ['format' => Loader::FORMAT, 'version' => $row['version'], 'hash' => $row['hash']] + $resolved['state'];
            $db->transaction(function () use ($db, $app, $row, $state): void {
                try {
                    $db->run('INSERT INTO `' . $db->prefix() . self::TABLE . '` (`app`, `version`, `parent`, `format`, `build`, `actor`, `changes`, `hash`, `createdAt`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                        $row['app'], $row['version'], $row['parent'], $row['format'], $row['build'], self::json($row['actor']), self::json($row['changes']), $row['hash'], $row['createdAt'],
                    ]);
                } catch (DbError $e) {
                    // The primary key refused it: a writer that didn't hold the lock got there first.
                    $taken = $db->run('SELECT 1 FROM `' . $db->prefix() . self::TABLE . '` WHERE `app` = ? AND `version` = ?', [$row['app'], $row['version']]) !== [];
                    throw $taken ? new Refused('schema_conflict', "Version {$row['version']} already exists: another change was applied first. Read the schema and propose the change again", null, '/parent', 409) : $e;
                }
                $db->touchOption(Loader::option($app));
                update_option(Loader::option($app), Loader::sealed($state), true);
            });

            return $row;
        });
    }

    /**
     * A version's hash: its parent's hash and every other stored value of the row. Changing or
     * removing an earlier version changes every hash after it.
     */
    public static function hashOf(string $parentHash, array $row): string
    {
        unset($row['hash']);

        return hash('sha256', $parentHash . "\n" . self::json($row));
    }

    /** The build this request runs, as compiled: what every change is checked against. */
    private function compiled(): array
    {
        return Loader::compiled() ?? throw new \LogicException('The app was loaded without its compiled schema');
    }

    /**
     * Every version, oldest first, after checking the chain; empty before the first change. Reading
     * the versions also puts the option back to the state they add up to, if something else wrote it.
     *
     * @throws Refused 409 `schema_chain_broken` when a stored version was changed outside TypedPress
     */
    public function list(): array
    {
        return $this->checked();
    }

    /**
     * Every version, checked: each row's parent is the one before it and its hash covers it and its
     * parent's hash. A row edited or removed in the database breaks the chain from there on, and so
     * does a missing newest row: the sealed option records the newest version it was written for.
     *
     * @throws Refused 409 `schema_chain_broken`, naming the first version that doesn't check out
     */
    private function verified(): array
    {
        // A table that is gone holds no versions, which the sealed option then says is wrong.
        $rows = $this->exists() ? $this->rows() : [];
        $parentHash = '';
        foreach ($rows as $i => $row) {
            if ($row['version'] !== $i + 1 || $row['parent'] !== $i || self::hashOf($parentHash, $row) !== $row['hash']) {
                throw self::broken($row['version']);
            }
            $parentHash = $row['hash'];
        }
        // The option apply sealed names the newest version and its hash; rows can't end before it.
        $state = $this->sealedState();
        $claimed = (int) ($state['version'] ?? 0);
        if ($claimed > count($rows)) {
            throw self::broken(count($rows) + 1, 'is missing');
        }
        if ($claimed > 0 && ($state['hash'] ?? null) !== $rows[$claimed - 1]['hash']) {
            throw self::broken($claimed);
        }

        return $rows;
    }

    private static function broken(int $version, string $how = 'does not match its hash'): Refused
    {
        return new Refused('schema_chain_broken', "schema version $version $how: a stored version was changed outside TypedPress. Restore it from a backup; nothing is applied on top of a broken history", null, '/changes', 409);
    }

    /** The option as apply last sealed it, fresh from the database; null when it isn't sealed. */
    private function sealedState(): ?array
    {
        $app = $this->plugin->schema->name();
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete(Loader::option($app), 'options');
        $state = Loader::state($app);

        return Loader::sealedBy($state) ? $state : null;
    }

    /**
     * The stored versions, checked, after which the option is the state they add up to. Rewriting
     * it takes the schema lock, so it never lands on top of a newer apply. A broken chain keeps the
     * option as it is when apply sealed it (the last state that checked out), with the reason added;
     * an option no apply sealed is never sealed here.
     */
    private function checked(): array
    {
        $app = $this->plugin->schema->name();
        try {
            $rows = $this->verified();
        } catch (Refused $broken) {
            $state = $this->sealedState();
            if ($state !== null && ($state['problem'] ?? null) !== self::chainProblem($broken)) {
                Migrator::locked($this->plugin->connection(), $this->plugin->schemaLock(), static function () use ($app, $state, $broken): void {
                    update_option(Loader::option($app), Loader::sealed(['problem' => self::chainProblem($broken)] + $state), true);
                });
            }
            throw $broken;
        }
        if ($rows === []) {
            return $rows;
        }
        $state = Loader::sealed($this->replay($rows));
        if ($this->sealedState() !== $state) {
            Migrator::locked($this->plugin->connection(), $this->plugin->schemaLock(), function () use ($app, $state): void {
                // Another apply may have landed since the rows were read: only an option that is
                // still behind those rows (or isn't sealed) is rewritten.
                $now = $this->sealedState();
                if ($now === null || (int) ($now['version'] ?? 0) <= $state['version']) {
                    update_option(Loader::option($app), $state, true);
                }
            });
        }

        return $rows;
    }

    /**
     * Why the stored versions don't check out, or null when they do: what the schema description
     * (`GET /wp-json/typedpress/v1`, `typedpress doctor`) reports without waiting for something to list them.
     */
    public function problem(): ?string
    {
        try {
            $this->verified();

            return null;
        } catch (Refused $broken) {
            return self::chainProblem($broken);
        }
    }

    private static function chainProblem(Refused $broken): string
    {
        return explode(':', $broken->getMessage(), 2)[0];
    }

    private function rows(): array
    {
        $db = $this->plugin->connection();
        $out = [];
        foreach ($db->run('SELECT `app`, `version`, `parent`, `format`, `build`, `actor`, `changes`, `hash`, `createdAt` FROM `' . $db->prefix() . self::TABLE . '` WHERE `app` = ? ORDER BY `version`', [$this->plugin->schema->name()]) as $row) {
            $out[] = ['app' => (string) $row['app'], 'version' => (int) $row['version'], 'parent' => (int) $row['parent'], 'format' => (int) $row['format'], 'build' => (string) $row['build'],
                'actor' => json_decode((string) $row['actor'], true, 16, JSON_THROW_ON_ERROR), 'changes' => json_decode((string) $row['changes'], true, 64, JSON_THROW_ON_ERROR),
                'createdAt' => (string) $row['createdAt'], 'hash' => (string) $row['hash']];
        }

        return $out;
    }

    /** The state the stored versions add up to. A version in a format this runtime can't read stops the replay. */
    private function replay(array $rows): array
    {
        $state = Loader::EMPTY;
        foreach ($rows as $row) {
            if ($row['format'] !== Loader::FORMAT) {
                throw new HttpError(409, 'schema_format', "Schema version {$row['version']} is stored in format {$row['format']}; this runtime reads format " . Loader::FORMAT . '. Update the TypedPress runtime before changing the schema');
            }
            $state = ['format' => Loader::FORMAT, 'version' => $row['version'], 'hash' => $row['hash']] + Changes::fold($state, $row['changes']);
        }

        return $state;
    }

    private function exists(): bool
    {
        $db = $this->plugin->connection();
        try {
            $db->run('SELECT 1 FROM `' . $db->prefix() . self::TABLE . '` LIMIT 1');

            return true;
        } catch (DbError) {
            return false;
        }
    }

    /**
     * The site's active version as the project keeps it: `tp/_generated/runtime-schema.json`
     * (`typedpress pull` writes exactly this). `typedpress build` declares what it lists, so a build made from
     * it contains the site's changes.
     */
    public function lock(): array
    {
        $rows = $this->list();
        $state = $rows === [] ? Loader::EMPTY : $this->replay($rows);

        return Changes::lock($state, $this->compiled());
    }

    /**
     * What a build that is about to run on this site would do to the site's runtime changes
     * ({@see \TypedPress\RuntimeSchema\Deploy::check()}). Nothing is ever dropped: changes the build
     * doesn't contain stay active and are listed in `kept`; fields both declare with the same stored
     * shape but other rules or presentation are `changed` (the build's declaration is used); names
     * the site removed that the build's code uses are active again and listed in `restored`.
     * `refusals` are the cases where the build would read or write the site's stored values another way.
     *
     * @return array{site: array{version: int, hash: string}, build: array{version: int, hash: string}, kept: list<string>, changed: list<string>, restored: list<string>, refusals: list<array{code: string, name: string, message: string, fix: string}>}
     */
    public function deployCheck(): array
    {
        return $this->compare($this->list());
    }

    /**
     * {@see deployCheck()} without its side effect: reading the versions never puts the option back
     * or records a broken chain on it. What a deployment's preflight uses, so inspecting a candidate
     * that is then refused leaves the site exactly as it was.
     *
     * @throws Refused 409 `schema_chain_broken`, as {@see list()} does, with nothing written
     */
    public function verify(): array
    {
        return $this->compare($this->exists() ? $this->verified() : []);
    }

    /** The comparison both checks make, on versions already read and verified. */
    private function compare(array $rows): array
    {
        $state = $rows === [] ? Loader::EMPTY : $this->replay($rows);
        $compiled = $this->compiled();
        $build = ['version' => (int) ($compiled['runtimeSchema']['version'] ?? 0), 'hash' => (string) ($compiled['runtimeSchema']['hash'] ?? '')];
        ['kept' => $kept, 'changed' => $changed, 'refusals' => $refusals] = \TypedPress\RuntimeSchema\Deploy::check($compiled, $state);
        // What the site removed and this build declares in a way that can't be hidden: active again.
        $restored = [];
        $references = Loader::references();
        foreach (['collections' => 'columns', 'globals' => 'fields'] as $section => $inner) {
            foreach ($state['removed'][$section] ?? [] as $kind => $removed) {
                if (($removed['kind'] ?? false) && isset($compiled[$section][$kind]) && !Loader::hidableKind($compiled, $references, $section, $kind)) {
                    $restored[] = $kind;
                }
                foreach (($removed['kind'] ?? false) ? [] : $removed['fields'] ?? [] as $field => $_) {
                    if (isset($compiled[$section][$kind][$inner][$field]) && !Loader::hidableField($compiled, $references, $section, $kind, (string) $field)) {
                        $restored[] = "$kind.$field";
                    }
                }
            }
        }
        $newest = $rows === [] ? ['version' => 0, 'hash' => ''] : end($rows);

        return ['site' => ['version' => $newest['version'], 'hash' => $newest['hash']], 'build' => $build, 'kept' => $kept, 'changed' => $changed, 'restored' => $restored, 'refusals' => $refusals];
    }

    /**
     * The change set that undoes version `$version`: what a rollback applies as a new version. The
     * versions since must not build on it (a field added to a kind it added, say); the rules that
     * refuse such a change set say which.
     *
     * @return array a change set ({@see propose()} and {@see apply()} take it)
     */
    public function reversal(int $version): array
    {
        $rows = $this->list();
        $before = Loader::EMPTY;
        foreach ($rows as $row) {
            if ($row['version'] === $version) {
                return ['format' => Loader::FORMAT, 'changes' => Changes::reverse($before, $row['changes'])];
            }
            $before = Changes::fold($before, $row['changes']);
        }

        throw new HttpError(404, 'not_found', "No schema version $version");
    }

    /** What each change does to stored data, for a person or an agent to read before applying. */
    private function effects(array $changes, array $after): array
    {
        $schema = Loader::apply($this->compiled(), $after, Loader::references());
        // Counted by type name, so a collection that isn't registered (not added yet, or removed) is counted too.
        $items = static fn (string $postType): int => (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare(
            "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft', 'inherit')", $postType));
        $effects = [];
        foreach ($changes as $change) {
            $global = isset($change['global']);
            $kind = $change[$global ? 'global' : 'kind'];
            $effect = array_intersect_key($change, ['op' => 1, 'kind' => 1, 'global' => 1, 'field' => 1]);
            $postType = "tp_$kind";
            $effects[] = $effect + match ($change['op']) {
                'addField' => ['storage' => 'post meta ' . $schema['collections'][$kind]['wp']['metaPrefix'] . $change['field'] . ' on ' . $schema['collections'][$kind]['wp']['postType'],
                    'existingItems' => $items($schema['collections'][$kind]['wp']['postType']),
                    'note' => isset($change['definition']['default']) ? 'existing items get the default when they are next saved' : 'existing items have no value until they are edited'],
                'addGlobalField' => ['storage' => 'option ' . $schema['globals'][$kind]['wp']['optionPrefix'] . $change['field'], 'note' => 'reads the default, or null, until it is set'],
                'addCollection' => ['storage' => "post type $postType", 'existingItems' => $items($postType), 'note' => 'no SQL table is created; items are posts'],
                'addGlobal' => ['storage' => "options tp_global_{$kind}_*", 'note' => 'no SQL table is created; values are options'],
                'setLabel', 'setFieldUi' => ['note' => 'presentation only; no stored data changes'],
                'removeField' => ['note' => 'hidden from the schema; stored values are kept and come back if the field is added again as ' . $change['was']],
                'removeCollection' => ['existingItems' => $items($postType), 'note' => "hidden from the schema; its items stay in post type $postType and come back if the collection is added again"],
                'removeGlobal' => ['note' => 'hidden from the schema; its stored values are kept'],
            };
        }

        return $effects;
    }

    private static function json(array $value): string
    {
        return (string) Json::encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
