<?php

declare(strict_types=1);

namespace Zero\Locale;

use Zero\HttpError;
use Zero\Js;
use Zero\Schema;
use Zero\Viewer;

use function Zero\db;

/**
 * Translation groups on write (§7.3). Create: `locale` defaults to locales.default; `translationOf`
 * joins that row's group (at most one row per locale) and pre-fills every synced column, refusing a
 * payload value that differs. Update: `locale` is immutable; a write that changes synced columns
 * applies them to every other row of the group through the update pipeline (hooks and revisions per
 * row, trusted: the group shares those values by definition). A row that joins a group outside the
 * API (Polylang's "+" in wp-admin) adopts the values of the group's oldest row. Delete removes only
 * that row, so the group shrinks.
 */
final class GroupSync
{
    /** @var array<string, true> translation groups whose synced values are being propagated right now */
    private static array $active = [];
    /** @var list<array{string, string, int|string, list<string>}> [collection, locale, source pk, supplied synced columns] of API translation creates awaiting persistence */
    private static array $joining = [];

    public static function normalize(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        if (!Module::localized($collection)) {
            return; // `translationOf` stays an unknown field there
        }
        foreach (['translations', 'translationGroup'] as $system) {
            if (array_key_exists($system, $data)) {
                throw HttpError::invalidInput("$system is managed by Zero", HttpError::seg($system));
            }
        }
        if ($op === 'update' && array_key_exists('translationOf', $data)) {
            throw HttpError::invalidInput('translationOf only applies on create', '/translationOf');
        }
        if ($op === 'update' && array_key_exists('locale', $data) && $data['locale'] !== $existing['locale']) {
            throw HttpError::invalidInput('locale cannot be changed; create a translation instead', '/locale');
        }
        unset($data['translationOf']);
    }

    /**
     * Prepares a create's group membership without persisting it (operations may replay before
     * persistence): one row per locale is enforced where the row is stored, by the
     * (translationGroup, locale) index on tables and under a group lock in Wp\Polylang::assign.
     */
    public static function write(string $op, string $collection, array &$data, ?array $existing, ?Viewer $viewer, array $provided): void
    {
        if (!Module::localized($collection)) {
            return;
        }
        if ($op === 'update') {
            // Group sync compares this write with the row as this request read it, so that read must
            // be current: a write based on a stale row (another request changed the group) is a 409.
            Lock::unchanged($collection, $existing[db()->schema()->collection($collection)['primaryKey']]);

            return;
        }
        if ($op !== 'create') {
            return;
        }
        $spec = db()->schema()->collection($collection);
        $pk = $spec['primaryKey'];
        $data['locale'] = Schema::normalize($spec['columns']['locale'], $data['locale'] ?? null, 'locale', '/locale');
        $of = $provided['translationOf'] ?? null;
        if ($of === null) {
            if (!Module::polylang($collection)) {
                $data['translationGroup'] = self::uuid();
            }

            return;
        }
        if (!Js::isInteger($of) && !is_string($of)) {
            throw HttpError::invalidInput('translationOf must be the id of an existing row', '/translationOf');
        }
        $find = static fn (?Viewer $v) => db()->findFirst($collection, ['where' => [$pk => $of], 'locale' => '*'], $v);
        // The caller must be able to read the source. Values are copied from storage, not from the
        // caller's (possibly field-filtered) view; but a supplied synced value is compared only with
        // what the caller can see, so the check never reveals a hidden value.
        $visible = $viewer === null ? null : $find($viewer);
        if (($viewer !== null && $visible === null) || ($source = $find(null)) === null) {
            throw HttpError::invalidInput("No $collection row with $pk " . Js::str($of), '/translationOf');
        }
        $visible ??= $source;
        // Copy every synced column the caller left out. A supplied one is kept and compared with the
        // group once persisted (the persistence continuation, after any idempotent replay), except a
        // column the caller can't read: that is refused here, whatever its value, so nothing leaks.
        $supplied = [];
        foreach (Module::synced($spec) as $col) {
            if (!array_key_exists($col, $provided)) {
                $data[$col] = $source[$col];
            } elseif (!array_key_exists($col, $visible) || !Module::same($visible[$col], $source[$col])) {
                // Not fully readable by the caller (the column, or a sub-field of it, is hidden).
                throw self::synced($col);
            } else {
                $supplied[] = $col;
            }
        }
        if (self::$joining === []) {
            // Entries live for one transaction: a create that replays a receipt never persists, so
            // its entry must not survive to match a later create.
            db()->store()->afterCommit(static function (): void {
                self::$joining = [];
            });
            $store = db()->store();
            $rollback = $store instanceof \Zero\Wp\CompositeStore ? [$store->db, 'onRollback'] : (method_exists($store, 'onRollback') ? [$store, 'onRollback'] : null);
            if ($rollback !== null) {
                $rollback(static function (): void {
                    self::$joining = [];
                });
            }
        }
        self::$joining[] = [$collection, $data['locale'], $source[$pk], $supplied];
        if (Module::polylang($collection)) {
            $data['translations'] = Module::members($source, $pk); // the group WpPostStore::insert joins
        } else {
            if ($source['translationGroup'] === null) { // a row from before the collection was localized
                $source['translationGroup'] = self::uuid();
                db()->store()->update($collection, $source[$pk], ['translationGroup' => $source['translationGroup']]);
            }
            $data['translationGroup'] = $source['translationGroup'];
        }
    }

    public static function written(string $op, string $collection, array $row, ?array $previous): void
    {
        if ($op === 'delete' || !Module::localized($collection)) {
            return;
        }
        $spec = db()->schema()->collection($collection);
        $pk = $spec['primaryKey'];
        if ($previous !== null && $row['locale'] !== $previous['locale']) { // also after hooks, also inside group writes
            throw HttpError::invalidInput('locale cannot be changed; create a translation instead', '/locale');
        }
        if (isset(self::$active[self::key($collection, $row, $pk)])) {
            return; // this row is being given its group's values; the outer write checks the group
        }
        $changed = [];
        foreach ($previous === null ? [] : Module::synced($spec) as $col) {
            if (!Module::same($row[$col], $previous[$col])) {
                $changed[$col] = $row[$col];
            }
        }
        if ($previous === null) {
            $members = Module::members($row, $pk);
            $join = null;
            foreach (array_reverse(self::$joining, true) as $i => $j) {
                if ($j[0] === $collection && $j[1] === $row['locale'] && in_array($j[2], $members, true)) {
                    $join = $j;
                    array_splice(self::$joining, $i, 1);
                    break;
                }
            }
            $join === null
                ? self::adopt($collection, $row) // a row that joined a group outside the API (wp-admin "+")
                : self::joined($collection, $row, $join[2], $join[3]);
        } elseif ($changed !== []) {
            // Every member as currently committed, locked: a translation created concurrently can't be missed.
            $members = Lock::members($collection, $row, Module::members($row, $pk));
            $siblings = array_values(array_filter($members, static fn ($id) => $id !== $row[$pk]));
            self::quietly(self::key($collection, $row, $pk), static function () use ($collection, $pk, $row, $siblings, $changed): void {
                foreach ($siblings === [] ? [] : db()->findMany($collection, ['where' => [$pk => ['in' => $siblings]], 'locale' => '*']) as $sibling) {
                    $patch = array_filter($changed, static fn ($v, $col) => !Module::same($sibling[$col], $v), ARRAY_FILTER_USE_BOTH);
                    // A sibling the same bulk update covers is written now, once, with that update's
                    // own patch plus the group's values (its hooks then don't run a second time).
                    if ($patch !== [] && !db()->updateWithin($collection, $row[$pk], $sibling[$pk], $patch)) {
                        db()->update($collection, $patch, [$pk => $sibling[$pk]]);
                    }
                }
            });
        }
        self::agrees($collection, $row[$pk]);
    }

    /**
     * An API translation, now stored: the group is locked (joins serialize), the source must still
     * be the committed version, and every synced value must equal the source's (422 for a value the
     * caller supplied, 409 when the copied one went stale).
     *
     * @param list<string> $supplied
     */
    private static function joined(string $collection, array $row, int|string $source, array $supplied): void
    {
        $pk = db()->schema()->collection($collection)['primaryKey'];
        Lock::members($collection, $row, Module::members($row, $pk));
        Lock::unchanged($collection, $source);
        $current = db()->findFirst($collection, ['where' => [$pk => $source], 'locale' => '*']);
        foreach (Module::synced(db()->schema()->collection($collection)) as $col) {
            if ($current !== null && !Module::same($row[$col], $current[$col])) {
                throw in_array($col, $supplied, true)
                    ? self::synced($col)
                    : new HttpError(409, 'conflict', 'The translation group changed while this request ran; retry it');
            }
        }
    }

    private static function synced(string $col): HttpError
    {
        return HttpError::invalidInput("$col is synced across translations; change it on any row to change all", HttpError::seg($col));
    }

    /**
     * The group invariant, checked after every write to a localized row once its group writes are
     * done: every current member (re-read now, so members that hooks created count) agrees on every
     * synced column. Hooks run per row and may write anything; a divergent group fails the whole
     * write with 409 conflict.
     */
    private static function agrees(string $collection, int|string $id): void
    {
        $spec = db()->schema()->collection($collection);
        $pk = $spec['primaryKey'];
        $row = db()->findFirst($collection, ['where' => [$pk => $id], 'locale' => '*']);
        $ids = $row === null ? [] : array_values(Module::members($row, $pk));
        if (count($ids) < 2) {
            return;
        }
        $members = db()->findMany($collection, ['where' => [$pk => ['in' => $ids]], 'locale' => '*']);
        foreach (Module::synced($spec) as $col) {
            foreach ($members as $member) {
                if (!Module::same($member[$col], $members[0][$col])) {
                    throw new HttpError(409, 'conflict', "A hook changed $col on one translation; synced columns must stay equal across the group", HttpError::seg($col));
                }
            }
        }
    }

    /**
     * Every row of $rows (one intended group, oldest first) takes the oldest row's synced values;
     * the rows must then agree on every synced column (hooks run per row), or 409 conflict.
     *
     * @param non-empty-list<array> $rows
     */
    public static function adoptAll(string $collection, array $rows): void
    {
        $spec = db()->schema()->collection($collection);
        $pk = $spec['primaryKey'];
        $source = $rows[0];
        $synced = Module::synced($spec);
        foreach (array_slice($rows, 1) as $row) {
            $patch = [];
            foreach ($synced as $col) {
                if (!Module::same($row[$col], $source[$col])) {
                    $patch[$col] = $source[$col];
                }
            }
            if ($patch !== []) {
                self::quietly(self::key($collection, $row, $pk), static fn () => db()->update($collection, $patch, [$pk => $row[$pk]]));
            }
        }
        self::agrees($collection, $source[$pk]); // the group as it is now, members hooks created included
    }

    /** Give $row (decoded, `translations` filled) the synced values of its group's oldest row, unless it is that row. */
    public static function adopt(string $collection, array $row): void
    {
        $spec = db()->schema()->collection($collection);
        $pk = $spec['primaryKey'];
        $ids = array_values(Module::members($row, $pk));
        $oldest = $ids === [] ? null : min($ids);
        if ($oldest === null || $oldest === $row[$pk]
            || ($source = db()->findFirst($collection, ['where' => [$pk => $oldest], 'locale' => '*'])) === null) {
            return;
        }
        $patch = [];
        foreach (Module::synced($spec) as $col) {
            if (!Module::same($row[$col], $source[$col])) {
                $patch[$col] = $source[$col];
            }
        }
        if ($patch !== []) {
            self::quietly(self::key($collection, $row, $pk), static fn () => db()->update($collection, $patch, [$pk => $row[$pk]]));
            self::agrees($collection, $row[$pk]); // the adopting row's hooks may have rewritten what it adopted
        }
    }

    /** A translation group's identity while its members don't change: its oldest member. */
    private static function key(string $collection, array $row, string $pk): string
    {
        $ids = array_values(Module::members($row, $pk));

        return $collection . ':' . Js::str($ids === [] ? $row[$pk] : min($ids));
    }

    /** Propagation writes are trusted pipeline updates that must not re-propagate within the same group. */
    private static function quietly(string $group, callable $fn): void
    {
        $was = self::$active[$group] ?? null;
        self::$active[$group] = true;
        try {
            $fn();
        } finally {
            if ($was === null) {
                unset(self::$active[$group]);
            }
        }
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
