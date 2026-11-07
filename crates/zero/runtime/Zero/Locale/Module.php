<?php

declare(strict_types=1);

namespace Zero\Locale;

use Zero\Extensions;

use function Zero\db;

/**
 * Feature 7 (§7.3): localized collections store one row per locale; non-localized columns are synced
 * across a translation group. Read owns the `locale` query option and presents the derived
 * `translations` (computed by Sql); GroupSync owns `translationOf`, group membership and synced-column
 * propagation. The group is the `translationGroup` column on custom tables and Polylang's
 * translation group on post-backed WordPress (Wp\Polylang).
 */
final class Module
{
    public static function register(): void
    {
        Extensions::beforeRead(Read::query(...), constraintSafe: true);
        Extensions::onRead(Read::read(...), constraintSafe: true);
        Extensions::normalizeWrite(GroupSync::normalize(...));
        Extensions::onWrite(GroupSync::write(...));
        Extensions::onWritten(GroupSync::written(...));
        if (function_exists('add_action')) {
            \Zero\Wp\Polylang::boot();
        }
    }

    public static function localized(string $collection): bool
    {
        $schema = db()->schema();

        return $schema->locales() !== null && $schema->hasCollection($collection) && ($schema->collection($collection)['localized'] ?? false);
    }

    /** True when Polylang owns this collection's language and translation group (post-backed WP). */
    public static function polylang(string $collection): bool
    {
        $store = db()->store();

        return $store instanceof \Zero\Wp\CompositeStore && $store->isPostBacked($collection);
    }

    /**
     * Columns kept equal across a translation group: every column that isn't translated (see
     * translated()), the primary key, a system column (status/publishedAt/uid/locale/...), the slug,
     * or generated per row.
     *
     * @return list<string>
     */
    public static function synced(array $spec): array
    {
        $out = [];
        foreach ($spec['columns'] as $name => $col) {
            $generated = isset($col['onUpdate']) || isset($col['default']['now']) || isset($col['default']['fn']) || ($col['autoIncrement'] ?? false);
            if ($name !== $spec['primaryKey'] && !($col['system'] ?? false) && !self::translated($col)
                && $name !== ($spec['slug'] ?? null) && !$generated) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * A column is translated per locale when it, or any sub-field, is `.localized()`: a group or
     * repeater holding a translated field is translated whole (its sub-values aren't merged).
     */
    public static function translated(array $col): bool
    {
        if ($col['localized'] ?? false) {
            return true;
        }
        foreach ([...array_values($col['fields'] ?? []), ...array_merge([], ...array_map('array_values', array_values($col['layouts'] ?? [])))] as $sub) {
            if (self::translated($sub)) {
                return true;
            }
        }

        return false;
    }

    /** Equal JS-form values (decoded rows and normalized input compare as canonical JSON). */
    public static function same(mixed $a, mixed $b): bool
    {
        return \Zero\Js::jsonStringify($a) === \Zero\Js::jsonStringify($b);
    }

    /** locale => primary key of every row in $row's group (self included), from the derived `translations`. */
    public static function members(array $row, string $pk): array
    {
        $t = $row['translations'] ?? null;
        $t = is_object($t) ? (array) $t : (is_array($t) ? $t : []);

        return $t + ($row['locale'] === null ? [] : [$row['locale'] => $row[$pk]]);
    }
}
