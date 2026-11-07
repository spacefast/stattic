<?php

declare(strict_types=1);

namespace Zero\Locale;

use Zero\HttpError;
use Zero\Js;
use Zero\Viewer;

use function Zero\db;

/**
 * Localized reads (§7.3). `locale` becomes a Locale\Filter under `where` (compiled by Sql); `*` reads
 * every row. API list reads default to locales.default. A read pinned to one primary key names that
 * row: without `locale` it is returned as is; with `locale` its group's row in that locale is. Trusted
 * reads (hooks, CLI, the write pipeline) see rows as stored, every locale, unless they pass `locale`.
 * `with`: a `one` relation resolves the stored id; a `many` relation resolves to each localized parent
 * row's own locale (Db::attach, with the same per-group fallback), and follows an explicit query locale
 * under a non-localized parent.
 */
final class Read
{
    public static function query(string $collection, array &$opts, ?Viewer $viewer, string $ptr): void
    {
        $schema = db()->schema();
        if ($schema->locales() === null || !$schema->hasCollection($collection)) {
            return; // nothing is localized here: a `locale` option stays unknown and Db rejects it
        }
        $has = array_key_exists('locale', $opts);
        $locale = $opts['locale'] ?? null;
        unset($opts['locale']);
        $localized = Module::localized($collection);
        if ($has && !$localized) {
            throw HttpError::invalidQuery("$collection is not localized", $ptr . '/locale');
        }
        $locales = $schema->locales();
        if ($has && $locale !== '*' && !(is_string($locale) && in_array($locale, $locales['all'], true))) {
            throw HttpError::invalidQuery('locale must be one of ' . implode(', ', $locales['all']) . ' or *', $ptr . '/locale');
        }
        $spec = $schema->collection($collection);
        if (is_array($opts['with'] ?? null)) {
            foreach ($opts['with'] as $name => &$nested) {
                $rel = $spec['relations'][$name] ?? null;
                if ($rel === null || !Module::localized($rel['collection']) || ($nested !== true && !is_array($nested))
                    || (is_array($nested) && array_key_exists('locale', $nested))) {
                    continue;
                }
                // `one` stores a specific row's id. Under a localized parent, `many` children are read
                // in each parent row's own locale by Db::attach (a page may mix locales through
                // fallback); under a non-localized parent they follow an explicit query locale.
                if ($rel['kind'] === 'many' && $localized) {
                    continue;
                }
                $follow = $rel['kind'] === 'many' && $has;
                $nested = ($nested === true ? [] : $nested) + ['locale' => $follow ? $locale : '*'];
            }
            unset($nested);
        }
        if (!$localized) {
            return;
        }
        $pinned = self::pinned($opts['where'] ?? null, $spec['primaryKey']);
        if (!$has) {
            if ($viewer === null || $pinned !== null) {
                return;
            }
            $locale = $locales['default'];
        }
        if ($locale === '*') {
            return;
        }
        $filter = new Filter($locale, ($locales['fallback'] ?? true) ? $locales['default'] : null, $pinned);
        $opts['where'] = $pinned !== null || !isset($opts['where']) ? ['locale' => $filter] : ['AND' => [$opts['where'], ['locale' => $filter]]];
    }

    /** `translations` (locale => id, a JSON object) in locales.all order; API viewers never see the hidden `translationGroup`. */
    public static function read(array $rows, string $collection, ?Viewer $viewer): array
    {
        if ($rows === [] || !Module::localized($collection)) {
            return $rows;
        }
        $order = array_flip(db()->schema()->locales()['all']);
        $pk = db()->schema()->collection($collection)['primaryKey'];
        $current = Module::polylang($collection) ? null : self::groups($collection, $rows, $pk);
        foreach ($rows as &$row) {
            if (!array_key_exists('locale', $row)) {
                continue;
            }
            // Current membership for every row, revision snapshots included (§7.3: one query per page),
            // resolved through the row's primary key, so a snapshot taken before its row had a group counts.
            $members = $current === null ? Module::members($row, $pk) : ($current[Js::str($row[$pk])] ?? [$row['locale'] => $row[$pk]]);
            uksort($members, static fn ($a, $b) => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));
            $row['translations'] = $members;
            if ($viewer !== null) {
                unset($row['translationGroup']);
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * pk => [locale => pk] of each row's current group (the row's stored group, not a snapshot's),
     * from two queries on the store.
     *
     * @return array<string, array<string, int|string>>
     */
    private static function groups(string $collection, array $rows, string $pk): array
    {
        $ids = array_values(array_unique(array_column($rows, $pk)));
        if ($ids === []) {
            return [];
        }
        $select = static fn (array $where) => db()->store()->select($collection, \Zero\Sql::select(db()->schema(), db()->store(), $collection, ['where' => $where]));
        $pkCol = db()->schema()->collection($collection)['columns'][$pk];
        $key = static fn (mixed $v) => \Zero\Schema::decode($pkCol, $v); // the column's own type: text ids stay text
        $groupOf = [];
        foreach ($select([$pk => ['in' => $ids]]) as $raw) {
            $groupOf[Js::str($key($raw[$pk]))] = $raw['translationGroup'];
        }
        $byGroup = [];
        $groups = array_values(array_unique(array_filter($groupOf, static fn ($g) => $g !== null)));
        foreach ($groups === [] ? [] : $select(['translationGroup' => ['in' => $groups]]) as $raw) {
            $byGroup[(string) $raw['translationGroup']][(string) $raw['locale']] = $key($raw[$pk]);
        }
        $out = [];
        foreach ($groupOf as $id => $group) {
            if ($group !== null && isset($byGroup[$group])) {
                $out[$id] = $byGroup[$group];
            }
        }

        return $out;
    }

    private static function pinned(mixed $where, string $pk): int|string|null
    {
        if (!is_array($where) || array_keys($where) !== [$pk]) {
            return null;
        }
        $v = $where[$pk];

        return is_int($v) || is_string($v) ? $v : null;
    }
}
