<?php

declare(strict_types=1);

namespace SpacePress\RuntimeSchema;

/**
 * A runtime change set (spec §2.10) resolved against the schema a site runs: the changes in stored
 * form, and the site's schema state after them. Nothing is stored here;
 * {@see \SpacePress\Wp\SchemaVersions} stores the result as a numbered version.
 *
 * A change set is `{ "format": 1, "changes": [ … ] }`. The operations:
 *
 *   addField        { kind, field, definition }      a field on a collection
 *   addGlobalField  { global, field, definition }    a field on a global
 *   addCollection   { kind, definition }             { label?, access?, fields } with a required text `title`
 *   addGlobal       { global, definition }           { label?, access?, fields }
 *   setLabel        { kind | global, label }         null takes a runtime label off again
 *   setFieldUi      { kind | global, field, ui }     label, help, placeholder, width; null takes it off
 *   removeField     { kind | global, field }         a field added at runtime: hidden, its data kept
 *   removeCollection { kind } / removeGlobal { global }   a kind added at runtime: hidden, its data kept
 *
 * Field and kind rules are the compiler's ({@see Validator}, {@see Kinds}). The rules below are the
 * running site's own; a build can't hit them, so they have their own problem codes.
 *
 * The state ({@see fold()}) holds what was added (`collections`, `globals`, `added`), labels and
 * presentation (`overrides`), what was removed (`removed`: name and stored type, so a later add
 * can't give old values another meaning), and `compiled`: the added kinds as `schema.php` arrays,
 * kept so a request only has to add them.
 */
final class Changes
{
    /** The resolved state is one autoloaded option; past this it is time to `spacepress pull` and build. */
    public const MAX_STATE_BYTES = 65536;
    public const MAX_CHANGES = 50;
    /** WordPress keeps meta keys in 255 characters and cuts longer ones off without an error. */
    public const MAX_NAME = 64;

    /**
     * Names spec §2.4 gives every kind, which a runtime field may not take before the build does,
     * and `system` and `staged`: their reference keys `_sp_system` and `_sp_staged` are where
     * SpacePress keeps a revision's snapshot and a post's staged version.
     */
    private const RESERVED = ['id', 'author', 'createdAt', 'updatedAt', 'deletedAt', 'deletedBy', 'deletedGroup', '_live', 'status', 'publishedAt', 'url', 'terms', 'uid', 'locale', 'translations', 'system', 'staged'];
    /** Kinds every site has (spec §2.1), and the names the API serves beside them. */
    public const BUILTIN_KINDS = ['users', 'comments', 'posts', 'pages', 'media', 'categories', 'tags', 'menus', 'settings', 'me', 'actions', 'globals', 'files', 'services', 'auth'];

    /**
     * @param array $compiled the build's `schema.php` ({@see Loader::compiled()})
     * @param array $state the site's newest version ({@see Loader::state()})
     * @param ?array $references the build's references index ({@see Loader::references()})
     * @param array $declared the stored types every build on the site declared ({@see Loader::declared()})
     * @return array{changes: list<array>, state: array} the changes in stored form and the state after them
     * @throws Refused
     */
    public static function resolve(array $compiled, array $state, mixed $changeSet, ?array $references = null, array $declared = []): array
    {
        if (!is_array($changeSet) || ($changeSet['format'] ?? null) !== Loader::FORMAT) {
            throw new Refused('schema_format', 'A change set is { "format": ' . Loader::FORMAT . ', "changes": [...] }; this runtime reads format ' . Loader::FORMAT, null, '/format');
        }
        if (array_diff(array_keys($changeSet), ['format', 'changes']) !== []) {
            throw new Refused('schema_format', 'A change set has `format` and `changes` only', null, \SpacePress\HttpError::seg((string) array_values(array_diff(array_keys($changeSet), ['format', 'changes']))[0]));
        }
        $changes = $changeSet['changes'] ?? null;
        if (!is_array($changes) || !array_is_list($changes) || $changes === []) {
            throw new Refused('schema_format', '`changes` must be a list with at least one change', null, '/changes');
        }
        if (count($changes) > self::MAX_CHANGES) {
            throw new Refused('schema_format', 'One version holds at most ' . self::MAX_CHANGES . ' changes', null, '/changes');
        }
        $resolved = [];
        foreach ($changes as $i => $change) {
            $pointer = "/changes/$i";
            if (!is_array($change) || !is_string($change['op'] ?? null)) {
                throw new Refused('schema_format', 'A change is an object with an `op`', null, $pointer);
            }
            try {
                $one = self::one(Loader::apply($compiled, $state, $references), $compiled, $state, $change, $pointer, $references, $declared);
            } catch (Refused $refused) {
                // A rule from the validator has no pointer of its own: it is about this change's definition.
                throw $refused->pointer !== null ? $refused : new Refused($refused->errorCode, $refused->getMessage(), $refused->diagnostic, "$pointer/definition");
            }
            $resolved[] = $one;
            $state = self::fold($state, [$one]);
        }
        if (strlen(serialize($state)) > self::MAX_STATE_BYTES) {
            throw new Refused('schema_too_large', 'The site\'s runtime schema would pass ' . self::MAX_STATE_BYTES . ' bytes. Move what it holds into the project: run `spacepress pull`, build and deploy, then apply this change', null, '/changes');
        }

        return ['changes' => $resolved, 'state' => $state];
    }

    /**
     * One change in stored form.
     *
     * @param array $schema the schema as it stands before this change: the build plus the state
     */
    private static function one(array $schema, array $compiled, array $state, array $change, string $pointer, ?array $references, array $declared): array
    {
        $shape = static function (array $keys) use ($change, $pointer): void {
            if (array_diff(array_keys($change), ['op', ...$keys]) !== [] || array_diff($keys, array_keys($change)) !== []) {
                throw new Refused('schema_format', "{$change['op']} is { \"op\", \"" . implode('", "', $keys) . '" }', null, $pointer);
            }
        };
        // `kind` names a collection, `global` a global; an operation that works on both takes either.
        $target = static function (bool $either) use ($change, $shape, $pointer): array {
            $global = array_key_exists('global', $change);
            if ($global && (!$either || array_key_exists('kind', $change))) {
                throw new Refused('schema_format', "{$change['op']} names " . ($either ? 'a `kind` or a `global`, not both' : 'a `kind`'), null, $pointer);
            }
            $name = $change[$global ? 'global' : 'kind'] ?? null;

            return is_string($name) ? [$global, $name] : throw new Refused('schema_format', "{$change['op']} names a " . ($either ? '`kind` or a `global`' : '`kind`'), null, $pointer);
        };
        $name = static fn (string $key): string => is_string($change[$key] ?? null) ? $change[$key]
            : throw new Refused('schema_format', "`$key` must be a string", null, "$pointer/$key");

        switch ($change['op']) {
            case 'addField':
                $shape(['kind', 'field', 'definition']);
                [$kind, $field] = [$name('kind'), $name('field')];
                $stored = Validator::field($kind, $schema['collections'][$kind] ?? null, $field, $change['definition']);
                self::addable($schema, $compiled, $state, false, $kind, $field, $stored, $pointer, $declared);

                return ['op' => 'addField', 'kind' => $kind, 'field' => $field, 'definition' => $stored];

            case 'addGlobalField':
                $shape(['global', 'field', 'definition']);
                [$kind, $field] = [$name('global'), $name('field')];
                if (!isset($schema['globals'][$kind])) {
                    throw Refused::rule('F3001', $kind, 'this app declares no global with that name');
                }
                $stored = Validator::definition("$kind.$field", $field, $change['definition'], Validator::GLOBAL);
                self::addable($schema, $compiled, $state, true, $kind, $field, $stored, $pointer, $declared);

                return ['op' => 'addGlobalField', 'global' => $kind, 'field' => $field, 'definition' => $stored];

            case 'addCollection':
            case 'addGlobal':
                $global = $change['op'] === 'addGlobal';
                $key = $global ? 'global' : 'kind';
                $shape([$key, 'definition']);
                $kind = $name($key);
                $stored = $global ? Kinds::global($kind, $change['definition']) : Kinds::collection($kind, $change['definition']);
                foreach (['collections', 'globals', 'actions', 'jobs'] as $section) {
                    if (isset($schema[$section][$kind])) {
                        // The same sort of thing under the name is a redefinition (F3001); anything else is
                        // one name for two things (F1221, spec §1.1). A table() is not a collection.
                        $same = $global ? $section === 'globals' : $section === 'collections' && ($schema[$section][$kind]['editor'] ?? false);
                        throw new Refused('schema_invalid', "$kind: `$kind` already names one of the app's " . ($section === 'collections' && !$same ? 'kinds' : $section), $same ? 'F3001' : 'F1221', "$pointer/$key");
                    }
                }
                if (in_array($kind, self::BUILTIN_KINDS, true)) {
                    throw new Refused('schema_reserved', "$kind: `$kind` is a kind every site has or a name the API serves (docs/spec/spec.md §2.1); choose another name", null, "$pointer/$key");
                }
                // A removed kind keeps its items under its storage name; the same name means the same fields' types.
                foreach (($state['removed'][$global ? 'globals' : 'collections'][$kind]['fields'] ?? []) as $field => $type) {
                    if (isset($stored['fields'][$field]) && $stored['fields'][$field]['type'] !== $type) {
                        throw new Refused('schema_retype', "$kind.$field: `$kind` was removed with `$field` stored as $type, and its values are still there. Add `$field` as $type again, or give the field another name", null, "$pointer/definition/fields/$field/type");
                    }
                }
                // A build that ran here declared this kind and stored its fields; the same name means
                // the same stored types.
                foreach (($declared[$global ? 'globals' : 'collections'][$kind] ?? []) as $field => $type) {
                    if (isset($stored['fields'][$field]) && $stored['fields'][$field]['type'] !== $type) {
                        throw new Refused('schema_retype', "$kind.$field: a build that ran on this site stores `$field` of `$kind` as $type. Add it as $type, or give the field another name", null, "$pointer/definition/fields/$field/type");
                    }
                }
                foreach ($stored['fields'] as $field => $definition) {
                    self::named($kind, (string) $field, $global ? [] : ['id', 'title', 'content'], "$pointer/definition/fields/$field");
                }
                self::storable($schema, $compiled, $state, $declared, $global, $kind, $global ? $stored['fields'] : array_diff_key($stored['fields'], ['title' => 1, 'content' => 1]), "$pointer/definition/fields");

                return ['op' => $change['op'], $key => $kind, 'definition' => $stored];

            case 'setLabel':
                [$global, $kind] = $target(true);
                $shape([$global ? 'global' : 'kind', 'label']);
                $label = $change['label'];
                // A table() kind is build-only; taking off a label stored before that rule is allowed.
                self::declared($schema, $global, $kind, $pointer, $label === null);
                if ($label !== null && (!is_string($label) || $label === '' || strlen($label) > 120)) {
                    throw new Refused('schema_format', '`label` is a string of at most 120 characters, or null to take the runtime label off', null, "$pointer/label");
                }

                return ['op' => 'setLabel', $global ? 'global' : 'kind' => $kind, 'label' => $label];

            case 'setFieldUi':
                [$global, $kind] = $target(true);
                $shape([$global ? 'global' : 'kind', 'field', 'ui']);
                $field = $name('field');
                $clearing = $change['ui'] === null || $change['ui'] === [];
                $definition = self::declared($schema, $global, $kind, $pointer, $clearing)[$global ? 'fields' : 'columns'][$field] ?? null;
                if ($definition === null || ($definition['system'] ?? false) || ($definition['primaryKey'] ?? false)) {
                    throw new Refused('schema_invalid', "$kind.$field: `$kind` has no field `$field` to present", 'F3001', "$pointer/field");
                }
                $ui = $change['ui'] === null || $change['ui'] === [] ? null : Validator::presentation($change['ui'], "$kind.$field");

                return ['op' => 'setFieldUi', $global ? 'global' : 'kind' => $kind, 'field' => $field, 'ui' => $ui];

            case 'removeField':
                [$global, $kind] = $target(true);
                $shape([$global ? 'global' : 'kind', 'field']);
                $field = $name('field');
                self::declared($schema, $global, $kind, $pointer);
                [$section, $inner] = $global ? ['globals', 'fields'] : ['collections', 'columns'];
                $stored = $state[$section][$kind][$inner][$field] ?? null;
                if ($stored === null && isset($state['added'][$section][$kind]['fields'][$field])) {
                    throw new Refused('schema_build_only', "$kind.$field: `$field` came with `$kind` when it was added. Remove `$kind`, or leave the field", null, "$pointer/field");
                }
                if ($stored === null) {
                    throw self::sourceOwned("$kind.$field", isset($compiled[$section][$kind][$inner][$field]), "$pointer/field");
                }
                if (isset($compiled[$section][$kind][$inner][$field]) && !Loader::hidableField($compiled, $references, $section, $kind, $field)) {
                    throw new Refused('schema_in_use', "$kind.$field: the build declares `$field` and it is required or named in the project's code, so compiled code may use it. Remove it from the project's code and from sp/_generated/runtime-schema.json, then build and deploy", null, "$pointer/field");
                }

                return ['op' => 'removeField', $global ? 'global' : 'kind' => $kind, 'field' => $field, 'was' => $stored['type']];

            case 'removeCollection':
            case 'removeGlobal':
                $global = $change['op'] === 'removeGlobal';
                [$key, $section] = $global ? ['global', 'globals'] : ['kind', 'collections'];
                $shape([$key]);
                $kind = $name($key);
                $stored = $state['added'][$section][$kind] ?? null;
                if ($stored === null) {
                    throw self::sourceOwned($kind, isset($compiled[$section][$kind]), "$pointer/$key");
                }
                if (isset($compiled[$section][$kind]) && !Loader::hidableKind($compiled, $references, $section, $kind)) {
                    throw new Refused('schema_in_use', "$kind: the build declares `$kind` and the project's code names it, so compiled code may use it. Remove it from the project's code and from sp/_generated/runtime-schema.json, then build and deploy", null, "$pointer/$key");
                }
                $types = array_map(static fn (array $field): string => $field['type'], $stored['fields'] + ($state[$section][$kind][$global ? 'fields' : 'columns'] ?? []));

                return ['op' => $change['op'], $key => $kind, 'was' => $types];

            default:
                throw self::buildOnly($change['op'], $pointer);
        }
    }

    /**
     * The compiled-plus-runtime kind, or the compiler's refusal for a name that isn't one. A table()
     * kind is build-only, its label and presentation included (the decision record's scope).
     */
    private static function declared(array $schema, bool $global, string $kind, string $pointer, bool $clearing = false): array
    {
        $spec = $schema[$global ? 'globals' : 'collections'][$kind]
            ?? throw new Refused('schema_invalid', "$kind: this app declares no " . ($global ? 'global' : 'collection') . ' with that name', 'F3001', $pointer . ($global ? '/global' : '/kind'));
        if (!$global && !$clearing && !isset($spec['wp']['postType'])) {
            throw new Refused('schema_build_only', "$kind: `$kind` is a table(), and tables change only in the project's schema file: edit it there, then build and deploy", null, "$pointer/kind");
        }

        return $spec;
    }

    /** The running site's own rules for a field joining a kind that exists. */
    private static function addable(array $schema, array $compiled, array $state, bool $global, string $kind, string $field, array $stored, string $pointer, array $declared): void
    {
        [$section, $inner] = $global ? ['globals', 'fields'] : ['collections', 'columns'];
        $spec = $schema[$section][$kind];
        if (isset($spec[$inner][$field])) {
            // The compiler's refusal for a lock field the source declares differently.
            throw new Refused('schema_invalid', "$kind.$field: `$field` is already a field of `$kind`", 'F3001', "$pointer/field");
        }
        if (isset($spec['relations'][$field])) {
            throw new Refused('schema_invalid', "$kind.$field: `$field` is already a relation of `$kind`", 'F3001', "$pointer/field");
        }
        // Not verified for these kinds yet, so refused rather than guessed: a translated kind's fields
        // are copied or translated per the build's wpml-config.xml, and a synced kind's fields come
        // from content files in the project.
        foreach (['localized' => 'translated', 'sync' => 'synced from content files'] as $key => $why) {
            if ($spec[$key] ?? false) {
                throw new Refused('schema_build_only', "$kind.$field: `$kind` is $why, and fields of such a kind can't be added at runtime yet: add `$field` to `$kind` in the project's schema file, then build and deploy", null, "$pointer/kind");
            }
        }
        self::named($kind, $field, [], "$pointer/field");
        self::storable($schema, $compiled, $state, $declared, $global, $kind, [$field => $stored], "$pointer/field");
        $was = $state['removed'][$section][$kind]['fields'][$field] ?? null;
        if ($was !== null && $was !== $stored['type']) {
            throw new Refused('schema_retype', "$kind.$field: `$field` was removed as a $was field and its values are still stored. Add it as $was again, or use another name", null, "$pointer/definition/type");
        }
        $other = $declared[$section][$kind][$field] ?? null;
        if ($other !== null && $other !== $stored['type']) {
            // Another build on this site (one the host still serves, or an older one) stores this
            // field as another type; giving it a second meaning would garble values for one of them.
            throw new Refused('schema_retype', "$kind.$field: a build that ran on this site stores `$field` as $other. Add it as $other, or give the field another name", null, "$pointer/definition/type");
        }
        if (!$global && ($stored['notNull'] ?? false) && !isset($stored['default'])) {
            throw new Refused('schema_required', "$kind.$field: a field added at runtime is optional or has a default, because existing items have no value for it. Leave out `notNull`, or add `default`", null, "$pointer/definition/notNull");
        }
    }

    /**
     * New fields store under keys no other field uses (the compiler's rule, `storage_collision` in
     * src/runtime_schema.rs): a collection's fields share its posts' meta, and every global's options
     * share one name space, `sp_global_<global>_<field>`. Checked among the new fields themselves
     * and against every field whose values may still be stored: the kind as the build and the state
     * declare it, the build's fields the site hid, fields a version removed, and fields of every
     * build that ran on the site. A name the new field shares exactly is the retype rules' to judge.
     *
     * @param array<string, array> $fields the new fields, by name
     */
    private static function storable(array $schema, array $compiled, array $state, array $declared, bool $global, string $kind, array $fields, string $pointer): void
    {
        $existing = [];
        $known = static function (string $owner, string $field, array $keys) use (&$existing, $fields, $kind): void {
            if ($owner !== $kind || !array_key_exists($field, $fields)) {
                $existing["$owner.$field"] ??= $keys;
            }
        };
        if ($global) {
            foreach ([$schema, $compiled] as $source) {
                foreach ($source['globals'] ?? [] as $name => $spec) {
                    foreach ($spec['fields'] as $field => $definition) {
                        $known((string) $name, (string) $field, self::prefixed("{$name}_", StorageKeys::of((string) $field, $definition)));
                    }
                }
            }
            $byType = array_merge_recursive(array_map(static fn (array $removed): array => $removed['fields'] ?? [], $state['removed']['globals'] ?? []), $declared['globals'] ?? []);
            foreach ($byType as $name => $types) {
                foreach ($types as $field => $type) {
                    $known((string) $name, (string) $field, self::prefixed("{$name}_", StorageKeys::ofType((string) $field, is_array($type) ? $type[0] : $type)));
                }
            }
        } else {
            foreach ([$schema, $compiled] as $source) {
                $spec = $source['collections'][$kind] ?? ['columns' => []];
                foreach ($spec['columns'] as $field => $definition) {
                    if (self::meta($spec, (string) $field, $definition)) {
                        $known($kind, (string) $field, StorageKeys::of((string) $field, $definition));
                    }
                }
            }
            $byType = ($state['removed']['collections'][$kind]['fields'] ?? []) + ($declared['collections'][$kind] ?? []);
            $own = $schema['collections'][$kind] ?? $compiled['collections'][$kind] ?? ['columns' => []];
            foreach ($byType as $field => $type) {
                // The post's own columns (its title, body, status) were never meta.
                if (!isset($own['columns'][$field]) || self::meta($own, (string) $field, $own['columns'][$field])) {
                    $known($kind, (string) $field, StorageKeys::ofType((string) $field, $type));
                }
            }
        }
        foreach ($fields as $field => $definition) {
            $keys = StorageKeys::of((string) $field, $definition);
            $keys = $global ? self::prefixed("{$kind}_", $keys) : $keys;
            foreach ($existing as $other => $otherKeys) {
                $key = StorageKeys::shared($keys, $otherKeys);
                if ($key !== null) {
                    $where = $global ? "the option sp_global_$key" : "the post meta key sp_$key";
                    throw new Refused('schema_invalid', "$kind.$field: `$field` would store values under $where, which $other already uses or kept; give the field another name", 'F3001', $pointer);
                }
            }
            $existing["$kind.$field"] = $keys;
        }
    }

    /** @param list<list<?string|true>> $keys */
    public static function prefixed(string $prefix, array $keys): array
    {
        return array_map(static fn (array $key): array => is_string($key[0]) ? [$prefix . $key[0], ...array_slice($key, 1)] : [$prefix, ...$key], $keys);
    }

    /** Whether a collection column is stored as post meta (PostMeta::fields' rule). */
    public static function meta(array $spec, string $field, array $definition): bool
    {
        return !in_array($field, [$spec['primaryKey'] ?? 'id', $spec['title'] ?? null, $spec['content'] ?? null, $spec['slug'] ?? null, $spec['excerpt'] ?? null], true) && !($definition['system'] ?? false);
    }

    /** Whether a runtime field may have this name ({@see named()}'s rule, as a yes or no). */
    public static function nameable(string $field, array $allowed): bool
    {
        return strlen($field) <= self::MAX_NAME && (!in_array($field, self::RESERVED, true) || in_array($field, $allowed, true));
    }

    /** A field name the site can store and that no kind will get from a build. `$allowed`: built-in names this place may use. */
    private static function named(string $kind, string $field, array $allowed, string $pointer): void
    {
        if (strlen($field) > self::MAX_NAME) {
            throw new Refused('schema_name', "$kind.$field: a field added at runtime has a name of at most " . self::MAX_NAME . ' characters; it is stored under that name as a meta key or an option', null, $pointer);
        }
        if (in_array($field, self::RESERVED, true) && !in_array($field, $allowed, true)) {
            throw new Refused('schema_reserved', "$kind.$field: `$field` is a field every kind has or will have (docs/spec/spec.md §2.4); choose another name", null, $pointer);
        }
    }

    /** Removing something a version didn't add: the build's, or nothing at all. */
    private static function sourceOwned(string $what, bool $compiled, string $pointer): Refused
    {
        return $compiled
            ? new Refused('schema_build_only', "$what is declared in the project's source, and compiled code may use it: remove it there, then build and deploy. Its data is checked against live rows at deploy", null, $pointer)
            : new Refused('schema_invalid', "$what: no runtime change added that", 'F3001', $pointer);
    }

    /**
     * The state after stored changes, which are already in resolved form: what {@see resolve()}
     * returned when each version was applied. Replaying every version from {@see Loader::EMPTY}
     * gives the site's active state again, so the option {@see Loader} reads is a copy, never the record.
     *
     * @param list<array> $changes
     */
    public static function fold(array $state, array $changes): array
    {
        foreach ($changes as $change) {
            $global = isset($change['global']);
            [$section, $inner] = $global ? ['globals', 'fields'] : ['collections', 'columns'];
            $kind = $change[$global ? 'global' : 'kind'];
            switch ($change['op']) {
                case 'addField':
                case 'addGlobalField':
                    $state[$section][$kind][$inner][$change['field']] = $change['definition'];
                    unset($state['removed'][$section][$kind]['fields'][$change['field']]);
                    break;
                case 'addCollection':
                case 'addGlobal':
                    $state['added'][$section][$kind] = $change['definition'];
                    $state['compiled'][$section][$kind] = $global ? Kinds::expandGlobal($kind, $change['definition']) : Kinds::expandCollection($kind, $change['definition']);
                    unset($state['removed'][$section][$kind]);
                    break;
                case 'setLabel':
                    $state['overrides'][$section][$kind]['label'] = $change['label'];
                    break;
                case 'setFieldUi':
                    $state['overrides'][$section][$kind]['ui'][$change['field']] = $change['ui'];
                    break;
                case 'removeField':
                    unset($state[$section][$kind][$inner][$change['field']], $state['overrides'][$section][$kind]['ui'][$change['field']]);
                    $state['removed'][$section][$kind]['fields'][$change['field']] = $change['was'];
                    break;
                case 'removeCollection':
                case 'removeGlobal':
                    unset($state['added'][$section][$kind], $state['compiled'][$section][$kind], $state[$section][$kind], $state['overrides'][$section][$kind]);
                    $state['removed'][$section][$kind] = ['kind' => true, 'fields' => $change['was'] + ($state['removed'][$section][$kind]['fields'] ?? [])];
                    break;
            }
        }

        return self::tidy($state);
    }

    /**
     * A state as `sp/_generated/runtime-schema.json`: what the compiler reads
     * (src/runtime_schema.rs). `removed` and `compiled` are the site's own and stay there.
     */
    public static function lock(array $state, ?array $compiled = null): array
    {
        // A table() kind's label or presentation (stored before that was refused) is the site's own:
        // the compiler refuses an override on a table(), so the lock leaves it out.
        foreach (array_keys($state['overrides']['collections'] ?? []) as $kind) {
            if ($compiled !== null && isset($compiled['collections'][$kind]) && !isset($compiled['collections'][$kind]['wp']['postType'])) {
                unset($state['overrides']['collections'][$kind]);
            }
        }

        return ['format' => Loader::FORMAT, 'version' => (int) ($state['version'] ?? 0), 'hash' => (string) ($state['hash'] ?? '')]
            + array_intersect_key(self::tidy($state), ['collections' => 1, 'globals' => 1, 'added' => 1, 'overrides' => 1]);
    }

    /** No empty maps or null overrides: two states that mean the same are the same bytes. */
    private static function tidy(array $value, bool $top = true): array
    {
        foreach ($value as $key => $item) {
            if ($top && $key === 'compiled') {
                // `schema.php` arrays: kept as the compiler writes them, nulls and empty lists included.
                $item = array_filter($item, static fn (array $kinds): bool => $kinds !== []);
            } elseif (is_array($item)) {
                $item = self::tidy($item, false);
            }
            if ($item === null || $item === []) {
                unset($value[$key]);
            } else {
                $value[$key] = $item;
            }
        }

        return $value;
    }

    /**
     * The changes that undo stored ones, newest first: what a rollback version holds. `$before` is
     * the state the changes were applied to. A removal is undone by adding the same thing back; its
     * data never left.
     *
     * @param list<array> $changes
     * @return list<array>
     */
    public static function reverse(array $before, array $changes): array
    {
        $states = [$before];
        foreach ($changes as $change) {
            $states[] = self::fold(end($states), [$change]);
        }
        $out = [];
        foreach (array_reverse($changes, true) as $i => $change) {
            $was = $states[$i];
            $global = isset($change['global']);
            [$key, $section, $inner] = $global ? ['global', 'globals', 'fields'] : ['kind', 'collections', 'columns'];
            $kind = $change[$key];
            $out[] = match ($change['op']) {
                'addField', 'addGlobalField' => ['op' => 'removeField', $key => $kind, 'field' => $change['field']],
                'addCollection' => ['op' => 'removeCollection', 'kind' => $kind],
                'addGlobal' => ['op' => 'removeGlobal', 'global' => $kind],
                'setLabel' => ['op' => 'setLabel', $key => $kind, 'label' => $was['overrides'][$section][$kind]['label'] ?? null],
                'setFieldUi' => ['op' => 'setFieldUi', $key => $kind, 'field' => $change['field'], 'ui' => $was['overrides'][$section][$kind]['ui'][$change['field']] ?? null],
                'removeField' => ['op' => $global ? 'addGlobalField' : 'addField', $key => $kind, 'field' => $change['field'], 'definition' => $was[$section][$kind][$inner][$change['field']]],
                'removeCollection', 'removeGlobal' => ['op' => $global ? 'addGlobal' : 'addCollection', $key => $kind, 'definition' => $was['added'][$section][$kind]],
            };
            // What a removal took with it comes back with it: fields added to the kind after it was
            // created, its runtime label, and the presentation of the fields that return.
            $overrides = $was['overrides'][$section][$kind] ?? [];
            if ($change['op'] === 'removeCollection' || $change['op'] === 'removeGlobal') {
                foreach ($was[$section][$kind][$inner] ?? [] as $field => $definition) {
                    $out[] = ['op' => $global ? 'addGlobalField' : 'addField', $key => $kind, 'field' => $field, 'definition' => $definition];
                }
                if (isset($overrides['label'])) {
                    $out[] = ['op' => 'setLabel', $key => $kind, 'label' => $overrides['label']];
                }
                foreach ($overrides['ui'] ?? [] as $field => $ui) {
                    $out[] = ['op' => 'setFieldUi', $key => $kind, 'field' => $field, 'ui' => $ui];
                }
            } elseif ($change['op'] === 'removeField' && isset($overrides['ui'][$change['field']])) {
                $out[] = ['op' => 'setFieldUi', $key => $kind, 'field' => $change['field'], 'ui' => $overrides['ui'][$change['field']]];
            }
        }

        return $out;
    }

    /** An operation only a build can make, with the source edit that makes it. */
    private static function buildOnly(string $op, string $pointer): Refused
    {
        $edit = match ($op) {
            'renameField', 'changeField', 'retypeField' => 'edit the field in the project\'s schema file',
            'renameCollection', 'renameGlobal' => 'rename it in the project\'s schema file',
            'addTable', 'changeTable', 'removeTable', 'addTableField' => 'declare the table() in the project\'s schema file; its SQL changes are checked against live rows at deploy',
            'addAction', 'addJob', 'addHook', 'addPage', 'addRoute', 'setAccessFunction' => 'write it in the project under sp/',
            'addTaxonomy' => 'taxonomies are not in this SpacePress version yet',
            default => 'make the change in the project',
        };

        return new Refused('schema_build_only', "`$op` can't be done at runtime: $edit, then build and deploy", null, "$pointer/op");
    }
}
