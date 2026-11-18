<?php

declare(strict_types=1);

namespace TypedPress\RuntimeSchema;

/**
 * What a build about to run on a site does to the site's runtime changes (spec §2.10): a pure
 * comparison of the build's `schema.php` with the state of the site's newest version, so the deploy
 * check ({@see \TypedPress\Wp\SchemaVersions::deployCheck()}) and the suites read the same answer.
 *
 * Nothing is ever dropped. A runtime change the build doesn't contain is `kept`, and so is a field
 * of a kind the site added that the build declares without it. A field both declare with the same
 * stored shape and other rules (a length, a default, presentation) is `changed`: the build's
 * declaration is used and its values read the same. Everything that reads or writes stored values
 * another way is a refusal: another type, another set of options, a list against a single value,
 * other sub-fields or layouts (`runtime_type_differs`); a build field stored under a key a runtime
 * field uses (`runtime_key_shared`); a build that names something other than a collection like a
 * runtime collection (`runtime_kind_differs`); and one that declares a runtime collection without
 * the body the site stored for it (`runtime_body_dropped`).
 */
final class Deploy
{
    /**
     * @return array{kept: list<string>, changed: list<string>, refusals: list<array{code: string, name: string, message: string, fix: string}>}
     */
    public static function check(array $compiled, array $state): array
    {
        $kept = $changed = $refusals = [];
        $compare = static function (string $name, array $site, array $built) use (&$changed, &$refusals): void {
            $why = self::stored($site, $built);
            if ($why !== null) {
                $refusals[] = ['code' => 'runtime_type_differs', 'name' => $name,
                    'message' => "$name is stored on this site as " . self::describe($site) . " (a runtime schema change) and declared in this build as " . self::describe($built) . ": $why.",
                    'fix' => "Declare $name as the site stores it (run `typedpress pull` to take the site's definition), or give the build's field another name."];
            } elseif (self::rules($site) !== self::rules($built)) {
                $changed[] = $name;
            }
        };
        // Every runtime field the site serves after this deploy, by storage: [kind, field, definition].
        $runtime = ['collections' => [], 'globals' => []];
        foreach (['collections' => 'columns', 'globals' => 'fields'] as $section => $inner) {
            foreach ($state[$section] ?? [] as $kind => $entry) {
                foreach ($entry[$inner] ?? [] as $field => $definition) {
                    $built = $compiled[$section][$kind][$inner][$field] ?? null;
                    $built === null ? $kept[] = "$kind.$field" : $compare("$kind.$field", $definition, $built);
                    $built ?? $runtime[$section][] = [(string) $kind, (string) $field, $definition];
                }
            }
            foreach ($state['added'][$section] ?? [] as $kind => $stored) {
                $kind = (string) $kind;
                $other = self::otherThing($compiled, $section, $kind);
                if ($other !== null) {
                    $refusals[] = ['code' => 'runtime_kind_differs', 'name' => $kind,
                        'message' => "$kind is a " . ($section === 'globals' ? 'global' : 'collection') . " on this site (a runtime schema change), and this build declares $other of that name: the site's items can't be read through it.",
                        'fix' => "Declare $kind as the site stores it (run `typedpress pull`), or give the build's one another name."];
                    continue;
                }
                $built = $compiled[$section][$kind] ?? null;
                if ($built === null) {
                    $kept[] = $kind;
                    continue;
                }
                if ($section === 'collections' && isset($stored['fields']['content']) && ($built['content'] ?? null) === null) {
                    $refusals[] = ['code' => 'runtime_body_dropped', 'name' => $kind,
                        'message' => "$kind has a body on this site (its `content`, a runtime schema change), and this build declares $kind without one: the body would no longer be read.",
                        'fix' => "Declare $kind's body in the build (run `typedpress pull` to take the site's definition)."];
                }
                foreach ($stored['fields'] as $field => $definition) {
                    if ($section === 'collections' && ($field === 'title' || $field === 'content')) {
                        continue; // the post's own columns, which the build names as it chooses
                    }
                    if (isset($built[$inner][$field])) {
                        $compare("$kind.$field", $definition, $built[$inner][$field]);
                    } else {
                        $kept[] = "$kind.$field";
                        $runtime[$section][] = [$kind, (string) $field, $definition];
                    }
                }
            }
            // A removed name keeps its values; a build that declares it again must read them as they were stored.
            foreach ($state['removed'][$section] ?? [] as $kind => $removed) {
                foreach ($removed['fields'] ?? [] as $field => $type) {
                    $built = $compiled[$section][$kind][$inner][$field] ?? null;
                    if ($built !== null && $built['type'] !== $type) {
                        $compare("$kind.$field", ['type' => $type], $built);
                    }
                }
            }
        }
        foreach (self::sharedKeys($compiled, $runtime) as [$name, $key, $other]) {
            $refusals[] = ['code' => 'runtime_key_shared', 'name' => $name,
                'message' => "$name is stored under $key, which $other (a runtime schema change) already uses on this site: the two would write one value.",
                'fix' => "Give $name another name, or declare $other in the build as the site stores it (run `typedpress pull`)."];
        }

        return ['kept' => $kept, 'changed' => $changed, 'refusals' => $refusals];
    }

    /** What else the build declares under a runtime kind's name: a table(), a global, an action or a job. */
    private static function otherThing(array $compiled, string $section, string $kind): ?string
    {
        if ($section === 'collections' && isset($compiled['collections'][$kind]) && !isset($compiled['collections'][$kind]['wp']['postType'])) {
            return 'a table()';
        }
        $others = ['collections' => 'a collection', 'globals' => 'a global', 'actions' => 'an action', 'jobs' => 'a job'];
        unset($others[$section]);
        foreach ($others as $other => $what) {
            if (isset($compiled[$other][$kind])) {
                return $what;
            }
        }

        return null;
    }

    /**
     * Build fields stored under a key a runtime field the build doesn't declare also uses:
     * `[build field, key, runtime field]`. A collection's fields share its posts' meta and every
     * global's options share one name space, as for a runtime change ({@see StorageKeys}).
     *
     * @param array{collections: list<array{string, string, array}>, globals: list<array{string, string, array}>} $runtime
     */
    private static function sharedKeys(array $compiled, array $runtime): array
    {
        $out = [];
        foreach ($runtime['collections'] as [$kind, $field, $definition]) {
            $spec = $compiled['collections'][$kind] ?? null;
            if (!isset($spec['wp']['postType'])) {
                continue;
            }
            $keys = StorageKeys::of($field, $definition);
            foreach ($spec['columns'] as $name => $column) {
                $name = (string) $name;
                if (in_array($name, [$spec['primaryKey'] ?? 'id', $spec['title'] ?? null, $spec['content'] ?? null, $spec['slug'] ?? null, $spec['excerpt'] ?? null], true) || ($column['system'] ?? false)) {
                    continue;
                }
                $key = StorageKeys::shared(StorageKeys::of($name, $column), $keys);
                if ($key !== null) {
                    $out[] = ["$kind.$name", "the post meta key tp_$key", "$kind.$field"];
                }
            }
        }
        foreach ($runtime['globals'] as [$kind, $field, $definition]) {
            $keys = self::prefixed("{$kind}_", StorageKeys::of($field, $definition));
            foreach ($compiled['globals'] ?? [] as $global => $spec) {
                foreach ($spec['fields'] as $name => $column) {
                    $key = StorageKeys::shared(self::prefixed("{$global}_", StorageKeys::of((string) $name, $column)), $keys);
                    if ($key !== null) {
                        $out[] = ["$global.$name", "the option tp_global_$key", "$kind.$field"];
                    }
                }
            }
        }

        return $out;
    }

    /** @param list<list<?string>> $keys */
    private static function prefixed(string $prefix, array $keys): array
    {
        return array_map(static fn (array $key): array => is_string($key[0]) ? [$prefix . $key[0], ...array_slice($key, 1)] : [$prefix, ...$key], $keys);
    }

    /** Why two definitions read stored values differently, or null when they read them the same. */
    private static function stored(array $site, array $built): ?string
    {
        if ($site['type'] !== $built['type']) {
            return 'another type';
        }
        if (self::sorted($site['enum'] ?? null) !== self::sorted($built['enum'] ?? null)) {
            return 'other options';
        }
        if (($site['accept'] ?? 'any') !== ($built['accept'] ?? 'any')) {
            return 'another kind of media';
        }
        foreach (['fields' => 'sub-fields', 'layouts' => 'layouts'] as $key => $what) {
            $a = $site[$key] ?? [];
            $b = $built[$key] ?? [];
            if ($key === 'layouts') {
                if (self::sorted(array_keys($a)) !== self::sorted(array_keys($b))) {
                    return "other $what";
                }
                foreach ($a as $layout => $fields) {
                    $why = self::stored(['type' => 'group', 'fields' => $fields], ['type' => 'group', 'fields' => $b[$layout]]);
                    if ($why !== null) {
                        return "layout $layout has $why";
                    }
                }
                continue;
            }
            if (self::sorted(array_keys($a)) !== self::sorted(array_keys($b))) {
                return "other $what";
            }
            foreach ($a as $sub => $definition) {
                $why = self::stored($definition, $b[$sub]);
                if ($why !== null) {
                    return "sub-field $sub has $why";
                }
            }
        }

        return null;
    }

    /** A list in one order, so two that hold the same values compare equal: order changes no stored value. */
    private static function sorted(?array $list): ?array
    {
        if ($list !== null) {
            sort($list);
        }

        return $list;
    }

    /** What, beside the stored shape, a definition says: rules, a default, whether it is required, presentation. */
    private static function rules(array $definition): array
    {
        $out = array_intersect_key($definition, ['notNull' => 1, 'default' => 1, 'rules' => 1, 'ui' => 1, 'multiline' => 1]);
        foreach (['fields', 'layouts'] as $key) {
            foreach ($definition[$key] ?? [] as $name => $sub) {
                $out[$key][$name] = $key === 'layouts' ? array_map(self::rules(...), $sub) : self::rules($sub);
            }
        }
        ksort($out);

        return $out;
    }

    private static function describe(array $definition): string
    {
        $type = $definition['type'];
        if (isset($definition['enum'])) {
            return "$type (" . implode(', ', $definition['enum']) . ')';
        }
        if (isset($definition['fields'])) {
            return "$type of " . implode(', ', array_keys($definition['fields']));
        }

        return $type;
    }
}
