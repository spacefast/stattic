<?php

declare(strict_types=1);

namespace TypedPress\RuntimeSchema;

/**
 * Collections and globals added at runtime (spec §2.10): the compiler's rules for a `collection()`
 * or `global()` whose options are data, and the array `schema.php` would hold for one.
 *
 * A stored kind is `{ label?, access?, fields }`. {@see collection()} and {@see global()} check one
 * and return it in stored form; {@see expandCollection()} and {@see expandGlobal()} give what the
 * compiler emits for it. `tests/runtime-schema/kinds.schema.php` is the compiler's output for the
 * same kinds, and the suites compare the two.
 */
final class Kinds
{
    /** `RESERVED_COLLECTIONS` in src/extract.rs: names TypedPress serves a route under. */
    private const RESERVED_ROUTES = ['media', 'files', 'globals', 'actions', 'me', '_schema', 'auth', 'services'];
    /** WordPress limits post types to 20 characters; TypedPress prefixes `tp_`. */
    private const MAX_COLLECTION_NAME = 17;
    private const ROLES = ['anyone', 'authenticated', 'guest', 'subscriber', 'author', 'editor', 'admin'];
    private const COLLECTION_OPS = ['read', 'create', 'update', 'delete'];
    private const GLOBAL_OPS = ['read', 'update'];

    /** @return array the stored kind */
    public static function collection(string $name, mixed $def): array
    {
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw Refused::rule('F3001', $name, 'a collection added at runtime has a lower-case name of letters, digits and underscores');
        }
        if (in_array($name, self::RESERVED_ROUTES, true)) {
            throw Refused::rule('F3001', $name, "\"$name\" is reserved: TypedPress serves a built-in route with that name");
        }
        if (strlen($name) > self::MAX_COLLECTION_NAME) {
            throw Refused::rule('F3001', $name, "\"$name\" is too long: WordPress post types are limited to 20 characters and TypedPress prefixes `tp_`; use at most " . self::MAX_COLLECTION_NAME . ' characters');
        }
        [$stored, $fields] = self::shape($name, $def);
        $title = $fields['title'] ?? null;
        if (!is_array($title) || ($title['type'] ?? null) !== 'text' || ($title['notNull'] ?? null) !== true || array_key_exists('enum', $title)) {
            throw Refused::rule('F3001', $name, 'a collection needs `title`: a required text field');
        }
        if (isset($fields['content']) && (!is_array($fields['content']) || ($fields['content']['type'] ?? null) !== 'richText')) {
            throw Refused::rule('F3001', $name, '`content` is the body of a collection: a richText field');
        }
        if (array_key_exists('slug', $fields)) {
            throw Refused::rule('F3001', $name, '`slug` is built in: every collection item has one');
        }
        foreach ($fields as $field => $definition) {
            $field = (string) $field;
            if ($field === 'id') {
                throw Refused::rule('F2001', "$name.id", '`id` is built in: every kind has an integer `id`');
            }
            if ($field === 'status' || $field === 'publishedAt') {
                throw Refused::rule('F3001', "$name.$field", "`$field` is reserved: collections have these (status, publishedAt)");
            }
            $stored['fields'][$field] = Validator::definition("$name.$field", $field, $definition, Validator::COLLECTION);
        }
        $stored += self::access($name, $def, self::COLLECTION_OPS);

        return self::ordered($stored);
    }

    /** @return array the stored kind */
    public static function global(string $name, mixed $def): array
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $name) !== 1) {
            throw Refused::rule('F3001', $name, "\"$name\" isn't a valid global name; use letters, digits, and underscores, starting with a letter");
        }
        if (in_array(ucfirst($name), ReservedNames::CLIENT_TYPES, true)) {
            throw Refused::rule('F3001', $name, "\"$name\" is reserved by generated client types");
        }
        [$stored, $fields] = self::shape($name, $def);
        foreach ($fields as $field => $definition) {
            $stored['fields'][(string) $field] = Validator::definition("$name.$field", (string) $field, $definition, Validator::GLOBAL);
        }
        $stored += self::access($name, $def, self::GLOBAL_OPS);

        return self::ordered($stored);
    }

    /** `{ label?, access?, fields }`: the label checked, the fields still as given. */
    private static function shape(string $name, mixed $def): array
    {
        if (!is_array($def) || array_is_list($def)) {
            throw Refused::rule('F3001', $name, 'a kind is `{ "label", "access", "fields" }`');
        }
        foreach ($def as $key => $_) {
            if (!in_array($key, ['label', 'access', 'fields'], true)) {
                throw Refused::rule('F3001', $name, "has no key `$key`");
            }
        }
        $fields = $def['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || array_is_list($fields)) {
            throw Refused::rule('F3001', $name, 'a kind needs at least one field');
        }
        $stored = ['fields' => []];
        if (array_key_exists('label', $def)) {
            if (!is_string($def['label']) || $def['label'] === '') {
                throw Refused::rule('F3001', $name, '`label` must be a string that isn\'t empty');
            }
            $stored['label'] = $def['label'];
        }

        return [$stored, $fields];
    }

    /** `access`: operations to a role, "anyone", "authenticated" or a boolean; functions are source. */
    private static function access(string $name, array $def, array $ops): array
    {
        if (!array_key_exists('access', $def)) {
            return [];
        }
        $access = $def['access'];
        if (!is_array($access) || $access === [] || array_is_list($access) || array_filter($access, static fn (mixed $rule): bool => !is_string($rule) && !is_bool($rule)) !== []) {
            throw Refused::rule('F3001', $name, '`access` maps operations to a role, "anyone", "authenticated", true or false');
        }
        foreach ($access as $op => $rule) {
            if (!in_array($op, $ops, true)) {
                throw Refused::rule('F3001', $name, "`access` has no operation `$op`; use " . implode(', ', $ops));
            }
            if (is_string($rule) && !in_array($rule, self::ROLES, true)) {
                throw Refused::rule('F3001', $name, "\"$rule\" isn't an access rule that can be set at runtime; use a role, \"anyone\", \"authenticated\", true or false, or write a function rule in source");
            }
        }

        return ['access' => array_intersect_key(array_replace(array_intersect_key(array_flip($ops), $access), $access), $access)];
    }

    private static function ordered(array $stored): array
    {
        return array_intersect_key(array_replace(array_intersect_key(['label' => 0, 'access' => 0, 'fields' => 0], $stored), $stored), $stored);
    }

    private static function rule(bool|string $rule): array
    {
        return is_bool($rule) ? ['allow' => $rule] : ['role' => $rule];
    }

    /** "press_notes" → "Press Notes" (`title_case` in src/schema.rs). */
    private static function label(string $name): string
    {
        return implode(' ', array_map('ucfirst', array_filter(preg_split('/[_-]/', $name), static fn (string $w): bool => $w !== '')));
    }

    /** What `schema.php` holds for a stored collection. */
    public static function expandCollection(string $name, array $stored): array
    {
        $access = array_fill_keys(self::COLLECTION_OPS, ['role' => 'editor']);
        $access['read'] = ['role' => 'anyone'];
        foreach ($stored['access'] ?? [] as $op => $rule) {
            $access[$op] = self::rule($rule);
        }

        return [
            'key' => $name,
            'label' => $stored['label'] ?? self::label($name),
            'editor' => true, 'drafts' => true, 'revisions' => true, 'softDelete' => true, 'comments' => false, 'uid' => false, 'sync' => null,
            'auth' => false, 'agents' => true, 'localized' => false,
            'title' => 'title', 'content' => isset($stored['fields']['content']) ? 'content' : null, 'slug' => null,
            'primaryKey' => 'id',
            'columns' => ['id' => ['type' => 'integer', 'primaryKey' => true, 'autoIncrement' => true, 'notNull' => true]] + $stored['fields'] + [
                'status' => ['type' => 'text', 'notNull' => true, 'enum' => ['draft', 'published', 'scheduled'], 'default' => ['value' => 'published'], 'system' => true],
                'publishedAt' => ['type' => 'timestamp', 'system' => true],
                'author' => ['type' => 'integer', 'readOnly' => true, 'system' => true],
                'createdAt' => ['type' => 'timestamp', 'notNull' => true, 'default' => ['now' => true], 'readOnly' => true, 'system' => true],
                'updatedAt' => ['type' => 'timestamp', 'notNull' => true, 'default' => ['now' => true], 'onUpdate' => ['now' => true], 'readOnly' => true, 'system' => true],
                'deletedAt' => ['type' => 'timestamp', 'readOnly' => true, 'system' => true],
                'deletedBy' => ['type' => 'integer', 'readOnly' => true, 'system' => true],
                'deletedGroup' => ['type' => 'text', 'rules' => ['max' => 36], 'hidden' => true, 'readOnly' => true, 'system' => true],
            ],
            'relations' => [], 'hooks' => [], 'beforeCreateFills' => [],
            'access' => $access,
            'api' => ['list' => true, 'get' => true, 'create' => true, 'update' => true, 'delete' => true],
            'wp' => ['postType' => "tp_$name", 'groupKey' => "group_tp_$name", 'metaPrefix' => 'tp_', 'fieldKeyPrefix' => "field_tp_{$name}_"],
            'table' => null,
        ];
    }

    /** What `schema.php` holds for a stored global. */
    public static function expandGlobal(string $name, array $stored): array
    {
        $access = ['read' => ['role' => 'anyone'], 'update' => ['role' => 'editor']];
        foreach ($stored['access'] ?? [] as $op => $rule) {
            $access[$op] = self::rule($rule);
        }

        return [
            'label' => $stored['label'] ?? self::label($name),
            'agents' => true,
            'fields' => $stored['fields'],
            'access' => $access,
            'wp' => ['postId' => "tp_global_$name", 'menuSlug' => "typedpress-global-$name", 'groupKey' => "group_tp_global_$name",
                'fieldKeyPrefix' => "field_tp_global_{$name}_", 'optionPrefix' => "tp_global_{$name}_"],
        ];
    }
}
