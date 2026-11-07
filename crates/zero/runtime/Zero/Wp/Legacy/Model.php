<?php

declare(strict_types=1);

namespace Zero\Wp\Legacy;

use Zero\HttpError;
use Zero\Schema;

/**
 * The original Zero (0.8) content model a site was built with, mapped onto the new app's schema.
 *
 * Original Zero stored each collection as native `post` rows tagged with one term of the hidden
 * `zero_collection` taxonomy (term meta `_zero_resource_id` names the collection), every field as
 * post meta `_zero_<resource>_<field>` (dots and dashes in either part became `_`), the title in
 * `post_title`, and a reference as the target's post id. Its compiled release (`content-model.php`,
 * format `spacefast.wordpress-content-model.php` v1) names every resource and field.
 *
 * Collections map by authored key and fields by name, as `zero migrate config --from zero` emits them: a
 * reference field `project` becomes the column `projectId`. Anything the new schema can't hold
 * refuses the whole plan with an RFC 6901 pointer into the release.
 */
final class Model
{
    public const TAXONOMY = 'zero_collection';
    public const RESOURCE_META = '_zero_resource_id';
    private const FORMAT = 'spacefast.wordpress-content-model.php';

    /**
     * @param array<string, array{collection: string, pointer: string, fields: array<string, array{key: string, column: string, kind: string, multiple: bool, pointer: string}>}> $collections
     *        resource id → mapping; `fields` excludes the native title
     */
    private function __construct(public readonly array $collections)
    {
    }

    /** @param array<string, mixed> $release the original release (`require content-model.php`) */
    public static function map(array $release, Schema $schema): self
    {
        if (($release['format'] ?? null) !== self::FORMAT || ($release['version'] ?? null) !== 1 || !is_array($release['postTypes'] ?? null)) {
            throw self::refuse('', 'not an original Zero content-model.php release (format ' . self::FORMAT . ' v1)');
        }
        // Releases name authored collection keys; the schema is indexed by physical storage.
        $targets = [];
        foreach ($schema->collections() as $storage => $spec) {
            $targets[$spec['key'] ?? $storage] = $storage;
        }
        $out = [];
        foreach ($release['postTypes'] as $i => $resource) {
            $at = "/postTypes/$i";
            if (!is_array($resource) || !is_string($resource['id'] ?? null)) {
                throw self::refuse($at, 'resource has no id');
            }
            $kind = $resource['kind'] ?? null;
            if ($kind !== 'collection') {
                // posts/pages/media are WordPress's own stores; the new app never owned them.
                continue;
            }
            $id = $resource['id'];
            $collection = $targets[$id] ?? null;
            if ($collection === null) {
                throw self::refuse("$at/id", "collection $id has no counterpart in the new app; migrate its config first");
            }
            $spec = $schema->collection($collection);
            if (!($spec['editor'] ?? true) || ($spec['wp'] ?? null) === null) {
                throw self::refuse("$at/id", "collection $id is an editor: false table in the new app; only post-backed collections keep WordPress revisions and authors");
            }
            if (($spec['primaryKey'] ?? null) !== 'id') {
                // Post ids are kept; the new collection must read them back as its `id`.
                throw self::refuse("$at/id", "collection $id's primary key is not `id` in the new app; post ids are kept and must stay the key");
            }
            if (($spec['title'] ?? null) === null) {
                throw self::refuse("$at/id", "collection $id has no title column in the new app");
            }
            if (($spec['uid'] ?? false) || ($spec['sync'] ?? null) !== null) {
                // Each record would need a stable `_zero_uid` (and sync state, §5.3).
                throw self::refuse("$at/id", "collection $id has uid or sync in the new app; migrate first, then enable them");
            }
            if (($spec['slug'] ?? null) !== null) {
                // post_name has its own uniqueness and derivation rules; original posts' names
                // weren't chosen for them.
                throw self::refuse("$at/id", "collection $id has a slug() column in the new app; migrate first, then add the slug");
            }
            if ($spec['localized'] ?? false) {
                // Each record would need a Polylang language and translation group (§7.3).
                throw self::refuse("$at/id", "collection $id is localized in the new app; migrate into an unlocalized collection, then localize");
            }
            $fields = [];
            foreach (is_array($resource['fields'] ?? null) ? $resource['fields'] : [] as $j => $field) {
                $fat = "$at/fields/$j";
                $name = is_array($field) ? ($field['name'] ?? null) : null;
                $key = is_array($field) ? ($field['storageName'] ?? null) : null;
                $type = is_array($field) ? ($field['definition']['type'] ?? null) : null;
                if (!is_string($name) || !is_string($key) || !is_string($type)) {
                    throw self::refuse($fat, 'field has no name, storageName or definition type');
                }
                if (!empty($field['native'])) {
                    if ($name !== 'title') {
                        throw self::refuse("$fat/name", "native field $name has no new-Zero mapping");
                    }
                    continue;
                }
                $fields[$name] = self::field($spec, $id, $name, $key, $field['definition'], $fat, $targets);
            }
            self::refuseUnwritableDefaults($spec, $id, $fields, $at);
            $out[$id] = ['collection' => $collection, 'pointer' => $at, 'fields' => $fields];
        }
        $mapped = array_column($out, 'collection');
        foreach ($out as $c) {
            foreach ($c['fields'] as $f) {
                if ($f['kind'] === 'reference' && !in_array($schema->collection($c['collection'])['columns'][$f['column']]['references']['collection'], $mapped, true)) {
                    throw self::refuse($f['pointer'], 'reference target is not a migrated collection');
                }
            }
        }

        return new self($out);
    }

    /** @return array{key: string, column: string, kind: string, multiple: bool, pointer: string} */
    private static function field(array $spec, string $collection, string $name, string $key, array $def, string $at, array $targets): array
    {
        $type = $def['type'];
        $target = $def['collection'] ?? null;
        $target = is_string($target) ? ($targets[$target] ?? null) : null;
        $multiple = (bool) ($def['multiple'] ?? false);
        $column = $type === 'relation' ? $name . 'Id' : $name;
        $col = $spec['columns'][$column] ?? null;
        if ($col === null || ($col['system'] ?? false)) {
            throw self::refuse("$at/name", "field $name has no column $column in the new $collection collection");
        }
        // Original fields lived in postmeta; the new app reads these columns from wp_posts instead.
        foreach (['title' => 'post_title', 'content' => 'post_content', 'slug' => 'post_name'] as $native => $postColumn) {
            if (($spec[$native] ?? null) === $column) {
                throw self::refuse("$at/name", "field $name maps to $collection.$column, which the new app stores in $postColumn; rename the column");
            }
        }
        $have = $col['type'];
        [$kind, $ok] = match ($type) {
            'text' => ['scalar', in_array($have, ['text', 'richText'], true) && !isset($col['enum'])],
            'number' => ['scalar', in_array($have, ['real', 'integer'], true)],
            'boolean' => ['boolean', $have === 'boolean'],
            'datetime' => ['timestamp', $have === 'timestamp'],
            'select' => $multiple
                ? ['list', $have === 'select' && self::sameOptions($col['enum'] ?? [], $def['options'] ?? [])]
                : ['scalar', $have === 'text' && self::sameOptions($col['enum'] ?? [], $def['options'] ?? [])],
            'media' => $multiple ? ['mediaList', $have === 'mediaList'] : ['media', $have === 'media'],
            // Post ids are kept, so the reference must target the collection's id (the post id).
            'relation' => ['reference', !$multiple && $have === 'integer' && $target !== null && ($col['references']['collection'] ?? null) === $target
                && ($col['references']['column'] ?? null) === 'id'],
            default => [null, false],
        };
        if ($kind === null) {
            throw self::refuse("$at/definition/type", "original Zero $type fields (rich text, blocks) need a content port; they are not migrated");
        }
        if (!$ok) {
            throw self::refuse("$at/definition", "original $type" . ($multiple ? ' (multiple)' : '') . " field $name doesn't match the new $collection.$column column ($have)");
        }

        return ['key' => $key, 'column' => $column, 'kind' => $kind, 'multiple' => $multiple, 'pointer' => $at];
    }

    /**
     * Columns the original app never had are filled from their default. Only a plain postmeta value
     * can be: post content/slug live in wp_posts, references/media/files carry integrity rules,
     * structured fields are SCF sub-field trees. Those need no default (set them after migrating).
     */
    private static function refuseUnwritableDefaults(array $spec, string $collection, array $fields, string $at): void
    {
        $mapped = array_column($fields, 'column');
        foreach ($spec['columns'] as $column => $col) {
            $column = (string) $column;
            if (!isset($col['default']) || in_array($column, $mapped, true) || ($col['system'] ?? false)
                || in_array($column, [$spec['primaryKey'], $spec['title'] ?? null], true)) {
                continue;
            }
            $plain = !in_array($column, [$spec['content'] ?? null, $spec['slug'] ?? null], true) && !isset($col['references'])
                && in_array($col['type'], ['text', 'integer', 'real', 'boolean', 'timestamp', 'date', 'email', 'url', 'json', 'select'], true);
            if (!$plain) {
                throw self::refuse("$at/id", "$collection.$column ({$col['type']}) is new in this app and has a default the migration can't write; remove the default, migrate, then set it");
            }
        }
    }

    private static function sameOptions(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    public static function refuse(string $pointer, string $reason): HttpError
    {
        return new HttpError(422, 'legacy_unmappable', $reason, $pointer);
    }
}
