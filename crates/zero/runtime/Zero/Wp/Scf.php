<?php

declare(strict_types=1);

namespace Zero\Wp;

use Zero\HttpError;
use Zero\Js;
use Zero\Schema;

/**
 * Secure Custom Fields wiring (§4.3, §4.5): one local field group per post-backed collection and per
 * global (options page), plus the validate/update/load filter trio that keeps wp-admin and core
 * REST saves on Zero's rules and storage forms.
 *
 * Keys are fixed by the compiler (schema.php `wp`): group `group_zero_<table>`, field
 * `field_zero_<table>_<col>`, sub-field `<field key>_<sub>`, blocks layout sub-field
 * `<field key>_<layout>_<sub>`. Every Zero field carries its column definition under `zero` so the
 * filters know what they are looking at; top-level fields also carry `zero_top`.
 *
 * Value forms: Zero's storage form (§3.4) ⇄ SCF's unformatted form (what update_field takes and
 * get_field(..., false) returns): booleans 1/0, dates `Ymd`, timestamps `Y-m-d H:i:s` UTC, lists as
 * arrays, group/repeater/blocks as arrays keyed by sub-field key (blocks rows carry acf_fc_layout).
 */
final class Scf
{
    /**
     * > 0 while Zero itself reads or writes SCF values: the timestamp filters then pass values
     * through (Zero already speaks UTC); otherwise they convert for the site-timezone picker.
     */
    public static int $io = 0;

    private const SCF_TYPES = ['text', 'textarea', 'select', 'number', 'true_false', 'date_picker', 'date_time_picker', 'email', 'url',
        'wysiwyg', 'image', 'file', 'gallery', 'post_object', 'group', 'repeater', 'flexible_content'];

    /** @var list<callable> */
    private static array $fieldFilters = [];

    /** @param callable(array $field, array $def, string $col, array $spec): array $fn */
    public static function fieldFilter(callable $fn): void
    {
        self::$fieldFilters[] = $fn;
    }

    // ================================================================ registration (acf/init, priority 5)

    public static function register(Schema $schema): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        foreach ($schema->collections() as $name => $spec) {
            if (!($spec['editor'] ?? true) || ($spec['wp'] ?? null) === null) {
                continue;
            }
            $fields = [];
            foreach ($spec['columns'] as $col => $def) {
                $col = (string) $col;
                if ($col === $spec['primaryKey'] || $col === ($spec['title'] ?? null) || $col === ($spec['content'] ?? null)
                    || $col === ($spec['slug'] ?? null) || ($def['system'] ?? false)) {
                    continue;
                }
                $fields[] = ['zero_top' => true] + self::field($schema, $spec['wp']['fieldKeyPrefix'] . $col, $spec['wp']['metaPrefix'] . $col, $col, $def, $spec);
            }
            acf_add_local_field_group([
                'key' => $spec['wp']['groupKey'],
                'title' => $spec['label'] ?? $name,
                'fields' => $fields,
                'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => $spec['wp']['postType']]]],
                'show_in_rest' => 1,
                'active' => true,
            ]);
        }
        $menu = 'zero-' . $schema->name();
        foreach ($schema->globals() as $name => $g) {
            $wp = $g['wp'];
            acf_add_options_page([
                'page_title' => $g['label'] ?? $name,
                'menu_title' => $g['label'] ?? $name,
                'menu_slug' => $wp['menuSlug'],
                'post_id' => $wp['postId'],
                'capability' => 'edit_others_posts',
                'parent_slug' => $menu,
                'redirect' => false,
                'show_in_rest' => true,
            ]);
            $fields = [];
            foreach ($g['fields'] as $field => $def) {
                // Option names are `<postId>_<name>` (SCF's convention), so the field name is the bare field.
                $fields[] = ['zero_top' => true] + self::field($schema, $wp['fieldKeyPrefix'] . $field, (string) $field, (string) $field, $def, $g);
            }
            acf_add_local_field_group([
                'key' => $wp['groupKey'],
                'title' => $g['label'] ?? $name,
                'fields' => $fields,
                'location' => [[['param' => 'options_page', 'operator' => '==', 'value' => $wp['menuSlug']]]],
                'show_in_rest' => 1,
                'active' => true,
            ]);
        }
    }

    public static function registerFilters(): void
    {
        add_filter('acf/validate_value', [self::class, 'validateValue'], 20, 4);
        foreach (self::SCF_TYPES as $type) {
            add_filter("acf/validate_rest_value/type=$type", [self::class, 'validateRestValue'], 20, 3);
        }
        add_filter('acf/update_value', [self::class, 'prepareValue'], 5, 3);
        add_filter('acf/update_value', [self::class, 'updateValue'], 20, 3);
        add_filter('acf/load_value', [self::class, 'loadValue'], 20, 3);
        // Zero's validator is the one authority on core REST: drop the schema's own bounds so a too-long
        // value reports Zero's message (the same one /zero/v1 returns), not core's.
        add_filter('acf/rest/get_field_schema', [self::class, 'restSchema'], 20, 2);
    }

    /** The SCF field array for one column (the field-builder switch, adapted from the original Zero). */
    /** One generated SCF field (rules, validation metadata, field filters), for other field groups (user forms, §6.2). */
    public static function fieldFor(Schema $schema, string $key, string $name, string $col, array $def, array $spec): array
    {
        return ['zero_top' => true] + self::field($schema, $key, $name, $col, $def, $spec);
    }

    private static function field(Schema $schema, string $key, string $name, string $col, array $def, array $spec): array
    {
        $rules = $def['rules'] ?? [];
        $type = $def['type'];
        $field = [
            'key' => $key,
            'label' => $def['ui']['label'] ?? self::label($col),
            'name' => $name,
            'required' => Schema::isRequired($def) ? 1 : 0,
            'conditional_logic' => 0,
            'wrapper' => ['width' => $def['ui']['width'] ?? '', 'class' => '', 'id' => ''],
            'zero' => $def + ['name' => $col],
        ];
        $choices = static fn (array $values) => array_combine($values, $values);
        $sub = static function (array $fields, string $prefix) use ($schema): array {
            $out = [];
            foreach ($fields as $s => $sdef) {
                $out[] = self::field($schema, $prefix . $s, (string) $s, (string) $s, $sdef, ['fields' => $fields]);
            }

            return $out;
        };

        $field += match (true) {
            $type === 'text' && isset($def['enum']) => ['type' => 'select', 'choices' => $choices($def['enum']), 'allow_null' => ($def['notNull'] ?? false) ? 0 : 1, 'multiple' => 0, 'return_format' => 'value', 'ui' => 1],
            $type === 'text' && ($def['multiline'] ?? false) => ['type' => 'textarea', 'maxlength' => $rules['max'] ?? '', 'rows' => 4, 'new_lines' => ''],
            $type === 'text' => ['type' => 'text', 'maxlength' => $rules['max'] ?? ''],
            $type === 'select' => ['type' => 'select', 'choices' => $choices($def['enum'] ?? []), 'allow_null' => 1, 'multiple' => 1, 'return_format' => 'value', 'ui' => 1],
            $type === 'integer' && isset($def['references']) => self::reference($schema, $def['references']),
            $type === 'integer' => ['type' => 'number', 'step' => 1, 'min' => $rules['min'] ?? '', 'max' => $rules['max'] ?? ''],
            $type === 'file' => ['type' => 'number', 'step' => 1, 'min' => 1, 'instructions' => 'A private file id (bucket ' . ($def['bucket'] ?? '') . ').'],
            $type === 'real' => ['type' => 'number', 'step' => 'any', 'min' => $rules['min'] ?? '', 'max' => $rules['max'] ?? ''],
            $type === 'boolean' => ['type' => 'true_false', 'ui' => 1, 'default_value' => ($def['default']['value'] ?? false) ? 1 : 0],
            $type === 'date' => ['type' => 'date_picker', 'display_format' => 'Y-m-d', 'return_format' => 'Y-m-d', 'first_day' => 1],
            $type === 'timestamp' => ['type' => 'date_time_picker', 'display_format' => 'Y-m-d H:i', 'return_format' => 'Y-m-d\TH:i:s', 'first_day' => 1],
            $type === 'email' => ['type' => 'email'],
            $type === 'url' => ['type' => 'url'],
            $type === 'slug' => ['type' => 'text', 'maxlength' => 200],
            $type === 'richText' => ['type' => 'wysiwyg', 'toolbar' => 'full', 'media_upload' => 1, 'tabs' => 'all'],
            $type === 'media' && ($def['accept'] ?? 'any') === 'image' => ['type' => 'image', 'return_format' => 'id', 'library' => 'all', 'preview_size' => 'medium'],
            $type === 'media' => ['type' => 'file', 'return_format' => 'id', 'library' => 'all'],
            $type === 'mediaList' => ['type' => 'gallery', 'return_format' => 'id', 'library' => 'all', 'preview_size' => 'medium'],
            $type === 'json' => ['type' => 'textarea', 'rows' => 8, 'new_lines' => '', 'instructions' => 'Enter valid JSON.'],
            $type === 'group' => ['type' => 'group', 'layout' => 'block', 'sub_fields' => $sub($def['fields'] ?? [], $key . '_')],
            $type === 'repeater' => ['type' => 'repeater', 'layout' => 'table', 'min' => $rules['min'] ?? 0, 'max' => $rules['max'] ?? 0,
                'button_label' => 'Add row', 'sub_fields' => $sub($def['fields'] ?? [], $key . '_')],
            $type === 'blocks' => ['type' => 'flexible_content', 'button_label' => 'Add block', 'min' => $rules['min'] ?? '', 'max' => $rules['max'] ?? '',
                'layouts' => self::layouts($key, $def['layouts'] ?? [], $sub)],
            default => throw new \LogicException("No SCF field for column type '$type'"),
        };
        if (isset($def['ui']['help'])) {
            $field['instructions'] = htmlspecialchars($def['ui']['help'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $field['instructions'] ??= '';
        if (isset($def['ui']['placeholder']) && in_array($field['type'], ['text', 'textarea', 'email', 'url', 'number', 'select'], true)) {
            $field['placeholder'] = $def['ui']['placeholder'];
        }
        foreach (self::$fieldFilters as $fn) {
            $field = $fn($field, $def, $col, $spec);
        }

        return $field;
    }

    private static function reference(Schema $schema, array $ref): array
    {
        $target = $schema->collection($ref['collection']);
        if (($target['editor'] ?? true) && ($target['wp'] ?? null) !== null) {
            return ['type' => 'post_object', 'post_type' => [$target['wp']['postType']], 'taxonomy' => [], 'allow_null' => 1, 'multiple' => 0, 'return_format' => 'id', 'ui' => 1];
        }

        return ['type' => 'number', 'step' => 1, 'instructions' => 'ID of a ' . ($target['label'] ?? $ref['collection'])];
    }

    private static function layouts(string $key, array $layouts, callable $sub): array
    {
        $out = [];
        foreach ($layouts as $layout => $fields) {
            $lkey = 'layout_' . substr($key, strlen('field_')) . '_' . $layout;
            $out[$lkey] = ['key' => $lkey, 'name' => (string) $layout, 'label' => self::label((string) $layout), 'display' => 'block',
                'sub_fields' => $sub($fields, $key . '_' . $layout . '_'), 'min' => '', 'max' => ''];
        }

        return $out;
    }

    private static function label(string $col): string
    {
        return ucfirst(strtolower((string) preg_replace('/(?<=[a-z0-9])([A-Z])/', ' $1', str_replace('_', ' ', $col))));
    }

    // ================================================================ filters

    /** wp-admin form saves: run Zero's codec + rules; a string return is SCF's error message. */
    public static function validateValue(mixed $valid, mixed $value, mixed $field, mixed $input): mixed
    {
        if (!is_array($field) || empty($field['zero_top']) || $valid === false) {
            return $valid;
        }

        // Zero's message wins over SCF's own wording for the same rule (e.g. maxlength).
        return self::check($field, $value) ?? $valid;
    }

    /** Core REST saves (`acf` in /wp/v2/<type>): same check, reported as a WP_Error (400). */
    public static function validateRestValue(mixed $valid, mixed $value, mixed $field): mixed
    {
        if (!is_array($field) || !isset($field['zero'])) {
            return $valid;
        }
        // SCF's post-object check distinguishes missing IDs before the controller can apply the
        // target's read policy. Zero validates the scalar here and resolves existence/visibility
        // together in the write pipeline, with the same response for an absent or hidden target.
        if (isset($field['zero']['references'])) {
            $valid = true;
        } elseif ($valid !== true) {
            return $valid;
        }
        if (empty($field['zero_top'])) {
            $field = acf_get_field($field['key']) ?: $field;
            if (empty($field['zero_top'])) {
                return $valid;
            }
        }
        $message = self::check($field, $value);

        return $message === null ? true : new \WP_Error('rest_invalid_param', $message, ['status' => 400]);
    }

    /** Zero's message for an invalid editor value, or null when it is valid. */
    private static function check(array $field, mixed $raw): ?string
    {
        $def = $field['zero'];
        $name = $def['name'];
        try {
            $js = !empty($field['zero_member'])
                ? ProfileForm::validationValue($raw, $field)
                : self::fromScf($def, $raw, $field['key'], true);
            $norm = Schema::normalize($def, $js, $name, HttpError::seg($name));
            Schema::checkRules($def, $norm, $name, HttpError::seg($name), [
                'data' => [$name => $norm],
                'user' => WpAuth::current(),
            ]);
        } catch (HttpError $e) {
            return $e->getMessage();
        } catch (\JsonException) {
            return 'Enter valid JSON.';
        }

        return null;
    }

    /** SCF's structured writers use isset(), so explicit nullable toggles need a stored empty value. */
    public static function prepareValue(mixed $value, mixed $postId, mixed $field): mixed
    {
        $def = is_array($field) ? ($field['zero'] ?? null) : null;
        if (!is_array($def) || !is_array($value)) {
            return $value;
        }
        $key = $field['key'];
        if ($def['type'] === 'group') {
            return self::nullableToggles($def['fields'] ?? [], $value, $key . '_');
        }
        if ($def['type'] === 'repeater' || $def['type'] === 'blocks') {
            foreach ($value as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $layout = (string) ($row['acf_fc_layout'] ?? $row['layout'] ?? '');
                $row = $def['type'] === 'blocks'
                    ? self::nullableToggles($def['layouts'][$layout] ?? [], $row, $key . '_' . $layout . '_')
                    : self::nullableToggles($def['fields'] ?? [], $row, $key . '_');
            }
            unset($row);
        }

        return $value;
    }

    private static function nullableToggles(array $fields, array $row, string $prefix): array
    {
        foreach ($fields as $name => $def) {
            if ($def['type'] !== 'boolean' || ($def['notNull'] ?? false)) {
                continue;
            }
            foreach ([$prefix . $name, $name] as $key) {
                if (array_key_exists($key, $row) && $row[$key] === null) {
                    $row[$key] = '';
                }
            }
        }

        return $row;
    }

    public static function updateValue(mixed $value, mixed $postId, mixed $field): mixed
    {
        $def = is_array($field) ? ($field['zero'] ?? null) : null;
        // WordPress serializes PHP false as '', which is also SCF's nullable
        // sub-field representation. Keep booleans in SCF's numeric storage form.
        if (is_array($def) && $def['type'] === 'boolean' && is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (!is_array($def) || $value === null || $value === '' || $value === false) {
            return $value;
        }
        if ($def['type'] === 'json' && is_string($value)) {
            try {
                return wp_slash((string) Js::jsonStringify(Js::decodeJson(wp_unslash($value))));
            } catch (\JsonException) {
                return $value;
            }
        }
        if ($def['type'] === 'timestamp' && is_string($value) && self::$io === 0) {
            return get_gmt_from_date($value, 'Y-m-d H:i:s'); // the picker posts site time; meta keeps UTC
        }

        return $value;
    }

    public static function loadValue(mixed $value, mixed $postId, mixed $field): mixed
    {
        $def = is_array($field) ? ($field['zero'] ?? null) : null;
        if (is_array($def) && $def['type'] === 'timestamp' && is_string($value) && $value !== '' && self::$io === 0) {
            return get_date_from_gmt($value, 'Y-m-d H:i:s');
        }

        return $value;
    }

    public static function restSchema(mixed $schema, mixed $field): mixed
    {
        if (!is_array($schema) || !is_array($field) || !isset($field['zero'])) {
            return $schema;
        }

        return self::looseSchema($schema);
    }

    private static function looseSchema(array $schema): array
    {
        unset($schema['maxLength'], $schema['minLength'], $schema['minimum'], $schema['maximum'], $schema['maxItems'], $schema['minItems'], $schema['required']);
        foreach (['properties', 'items'] as $k) {
            if (isset($schema[$k]) && is_array($schema[$k])) {
                $schema[$k] = isset($schema[$k]['type']) ? self::looseSchema($schema[$k]) : array_map(static fn ($s) => is_array($s) ? self::looseSchema($s) : $s, $schema[$k]);
            }
        }

        return $schema;
    }

    // ================================================================ value forms

    /** Storage form (§3.4) → SCF unformatted form, for update_field. */
    public static function toScf(array $def, int|float|string|null $stored, string $key): mixed
    {
        return $stored === null ? null : self::scfValue($def, Schema::decode($def, $stored), $key);
    }

    /** SCF unformatted value → storage form. */
    public static function toStorage(array $def, mixed $raw, string $key): int|float|string|null
    {
        return Schema::store($def, self::fromScf($def, $raw, $key));
    }

    /** Storage form → the string SCF keeps in a scalar meta row (unique checks compare against it). */
    public static function metaScalar(array $def, int|float|string $stored): string
    {
        $v = self::scfValue($def, Schema::decode($def, $stored), '');

        return is_float($v) ? Js::numStr($v) : (string) $v;
    }

    private static function scfValue(array $def, mixed $v, string $key): mixed
    {
        if ($v === null) {
            return null;
        }

        return match ($def['type']) {
            'boolean' => $v ? 1 : 0,
            'date' => str_replace('-', '', (string) $v),
            'timestamp' => substr((string) $v, 0, 10) . ' ' . substr((string) $v, 11, 8),
            'json' => Js::jsonStringify($v),
            'mediaList' => array_map('intval', $v),
            'group' => self::scfFields($def['fields'] ?? [], $v, $key . '_'),
            'repeater' => array_map(static fn ($row) => self::scfFields($def['fields'] ?? [], $row, $key . '_'), $v),
            'blocks' => array_map(
                static fn ($row) => ['acf_fc_layout' => $row['layout']] + self::scfFields($def['layouts'][$row['layout']] ?? [], $row, $key . '_' . $row['layout'] . '_'),
                $v,
            ),
            default => $v,
        };
    }

    private static function scfFields(array $fields, array $obj, string $prefix): array
    {
        $out = [];
        foreach ($fields as $name => $f) {
            $out[$prefix . $name] = self::scfValue($f, $obj[$name] ?? null, $prefix . $name) ?? '';
        }

        return $out;
    }

    /**
     * SCF value (unformatted meta, or editor input) → Zero's JS form. Empty ('' / false / no row)
     * reads as null. Shapes Zero can't interpret are passed through so Schema::normalize reports them.
     * $strictJson: throw JsonException on an unparsable json field (validation) instead of passing it.
     */
    public static function fromScf(array $def, mixed $v, string $key, bool $strictJson = false): mixed
    {
        $type = $def['type'];
        if ($v === null || $v === '' || ($v === false && $type !== 'boolean')) {
            return in_array($type, ['select', 'mediaList'], true) && ($def['notNull'] ?? false) ? [] : null;
        }

        return match ($type) {
            'integer', 'media', 'file' => is_numeric($v) && (float) $v == (int) $v ? (int) $v : $v,
            'real' => is_numeric($v) ? (float) $v : $v,
            'boolean' => match (true) {
                is_bool($v) => $v,
                $v === 1, $v === '1' => true,
                $v === 0, $v === '0' => false,
                default => $v,
            },
            'date' => is_string($v) && preg_match('/^(\d{4})(\d{2})(\d{2})$/D', $v, $m) ? "$m[1]-$m[2]-$m[3]" : $v,
            'timestamp' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $v) ? str_replace(' ', 'T', $v) . '.000Z' : $v,
            'json' => is_string($v) ? self::json($v, $strictJson) : $v,
            'select' => is_array($v) ? array_values(array_map('strval', $v)) : (is_string($v) && is_serialized($v) ? array_values((array) unserialize($v, ['allowed_classes' => false])) : [$v]),
            'mediaList' => is_array($v) ? array_values(array_map('intval', $v)) : $v,
            'group' => is_array($v) ? self::jsFields($def['fields'] ?? [], $v, $key . '_') : $v,
            'repeater' => is_array($v) ? array_values(array_map(
                static fn ($row) => is_array($row) ? self::jsFields($def['fields'] ?? [], $row, $key . '_') : $row,
                array_filter($v, static fn ($k) => $k !== 'acfcloneindex', ARRAY_FILTER_USE_KEY),
            )) : $v,
            'blocks' => is_array($v) ? array_values(array_map(static function ($row) use ($def, $key) {
                if (!is_array($row)) {
                    return $row;
                }
                $layout = (string) ($row['acf_fc_layout'] ?? $row['layout'] ?? '');

                return ['layout' => $layout] + self::jsFields($def['layouts'][$layout] ?? [], $row, $key . '_' . $layout . '_');
            }, array_filter($v, static fn ($k) => $k !== 'acfcloneindex', ARRAY_FILTER_USE_KEY))) : $v,
            default => is_scalar($v) ? (string) $v : $v,
        };
    }

    /**
     * Rows keyed by sub-field key (SCF), meta name (core REST input) or bare name → keyed by name.
     * Absent sub-fields stay absent so Schema::normalize applies their defaults.
     */
    private static function jsFields(array $fields, array $row, string $prefix): array
    {
        $out = [];
        foreach ($fields as $name => $f) {
            $key = null;
            foreach ([$prefix . $name, $name] as $k) {
                if (array_key_exists($k, $row)) {
                    $key = $k;
                    break;
                }
            }
            if ($key === null) {
                foreach ($row as $k => $v) {
                    if (is_string($k) && str_ends_with($k, '_' . $name) && !str_starts_with($k, 'field_')) {
                        $key = $k;
                        break;
                    }
                }
            }
            if ($key === null) {
                continue;   // absent: Schema::normalize applies the default
            }
            // SCF keeps an unchecked true_false as ''. For a notNull boolean that can only mean false
            // (a nullable one keeps '' as null, which is how a null is written).
            $out[$name] = ($f['type'] ?? null) === 'boolean' && ($f['notNull'] ?? false) && $row[$key] === ''
                ? false
                : self::fromScf($f, $row[$key], $prefix . $name);
        }

        return $out;
    }

    private static function json(string $s, bool $strict): mixed
    {
        try {
            return Js::decodeJson($s);
        } catch (\JsonException $e) {
            if ($strict) {
                throw $e;
            }

            return $s;
        }
    }
}
