<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\HttpError;
use TypedPress\Js;
use TypedPress\Schema;
use TypedPress\Wp\Abilities\JsonSchema;

/**
 * Core `meta` for a TypedPress post type (spec §3a): every field is registered post meta (`show_in_rest`,
 * with its JSON Schema) under its own name, so `/wp/v2/<type>` reads and writes `meta.<field>`.
 *
 * Values are TypedPress's, not raw postmeta: a read is the decoded row (a group or repeater is one value,
 * not SCF's sub-field rows); a write goes through TypedPress's codec and rules and the post store's write
 * path (unique checks, SCF storage forms). Who may read or write a field is not decided here:
 * {@see CoreAccess::strip()} drops hidden fields from responses and {@see EditorSave} authorizes the
 * save, `meta` included ({@see CoreAccess::incoming()}).
 */
final class PostMeta extends \WP_REST_Post_Meta_Fields
{
    public function __construct(string $postType, private readonly string $collection)
    {
        parent::__construct($postType);
    }

    /** register_post_meta for every field of a post-backed collection (init). */
    public static function register(array $spec): void
    {
        foreach (self::fields($spec) as $col => $def) {
            $schema = JsonSchema::column($def, true);
            $types = array_values(array_diff((array) $schema['type'], ['null']));
            register_post_meta($spec['wp']['postType'], $spec['wp']['metaPrefix'] . $col, [
                'single' => true,
                // register_meta's own type is one name; the schema carries the full (nullable) type.
                'type' => count($types) === 1 ? $types[0] : 'object',
                'label' => (string) ($def['ui']['label'] ?? $col),
                'show_in_rest' => ['name' => $col, 'schema' => $schema],
                // Never written through core's generic meta path (it would bypass TypedPress's codec).
                'auth_callback' => '__return_false',
            ]);
        }
    }

    /**
     * The request's `meta`, read from the JSON body with TypedPress's decoder when there is one: core's
     * parser turns `{}` into `[]`, which a json() field keeps apart.
     */
    public static function input(\WP_REST_Request $request): mixed
    {
        $body = (string) $request->get_body();
        if (($request->get_content_type()['value'] ?? null) === 'application/json' && trim($body) !== '') {
            try {
                $decoded = \TypedPress\Seam::container(Js::decodeJson($body));
                if (is_array($decoded) && array_key_exists('meta', $decoded)) {
                    return \TypedPress\Seam::container($decoded['meta']);
                }
            } catch (\JsonException) {
            }
        }

        return $request->get_param('meta');
    }

    /** @var ?array the current save's `meta`, as {@see input()} read it */
    private ?array $input = null;

    /** Run a create or update with $request's `meta` read by {@see input()}. */
    public function saving(\WP_REST_Request $request, callable $save): mixed
    {
        $previous = $this->input;
        $input = self::input($request);
        $this->input = is_array($input) ? $input : null;
        try {
            return $save();
        } finally {
            $this->input = $previous;
        }
    }

    /**
     * Fields of $row as TypedPress's API encodes them (shape-aware: a group stays an object even when every
     * sub-field is hidden, a json() value keeps `{}` apart from `[]`), for core's JSON encoder.
     *
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public static function values(string $collection, array $row, array $fields): array
    {
        $js = Js::jsonTree(\TypedPress\Seam::rowToJs($collection, array_intersect_key($row, array_flip($fields))));
        $js = $js instanceof \stdClass ? get_object_vars($js) : (array) $js;

        return array_intersect_key($js, array_flip($fields));
    }

    /** @return array<string, array> the columns that live in postmeta, by name */
    public static function fields(array $spec): array
    {
        $out = [];
        foreach ($spec['columns'] as $col => $def) {
            $col = (string) $col;
            // A `terms` field is core's own taxonomy field on the post (`tp_<taxonomy>`), not post meta.
            if ($col !== $spec['primaryKey'] && $col !== ($spec['title'] ?? null) && $col !== ($spec['content'] ?? null)
                && $col !== ($spec['slug'] ?? null) && $col !== ($spec['excerpt'] ?? null) && !($def['system'] ?? false) && !self::terms($def)) {
                $out[$col] = $def;
            }
        }

        return $out;
    }

    /** Whether a column is a `terms` field: its links are the post's term relationships. */
    public static function terms(array $def): bool
    {
        return str_starts_with((string) ($def['references']['join'] ?? ''), 'term:');
    }

    /** True while core's own code handles the meta that isn't TypedPress's (other plugins' registered keys). */
    private bool $others = false;

    protected function get_registered_fields()
    {
        $spec = $this->spec();
        $first = [];
        foreach (self::fields($spec) as $col => $def) {
            $first[$spec['wp']['metaPrefix'] . $col] = ['name' => $col, 'single' => true, 'schema' => ['default' => null] + JsonSchema::column($def, true), 'prepare_callback' => null];
        }
        $others = array_diff_key(parent::get_registered_fields(), $first);

        return $this->others ? $others : $first + $others;
    }

    /** Core's handling, for the registered meta that isn't a TypedPress field. */
    private function others(callable $fn): mixed
    {
        $this->others = true;
        try {
            return $fn();
        } finally {
            $this->others = false;
        }
    }

    /** @var array{int, ?array}|null the row being prepared, so a response reads it once */
    private ?array $row = null;

    /** Run $prepare with $row as post $id's stored row (the controller already read it). */
    public function with(int $id, ?array $row, callable $prepare): mixed
    {
        $previous = $this->row;
        $this->row = [$id, $row];
        try {
            return $prepare();
        } finally {
            $this->row = $previous;
        }
    }

    public function get_value($object_id, $request)
    {
        $spec = $this->spec();
        $out = [];
        $row = ($this->row !== null && $this->row[0] === (int) $object_id ? $this->row[1]
            : Plugin::instance()?->db()->editorRow($this->collection, (int) $object_id)) ?? [];
        $fields = array_keys(self::fields($spec));
        $out = array_fill_keys($fields, null);
        foreach (self::values($this->collection, $row, $fields) as $col => $v) {
            $out[$col] = $v;
        }

        return $out + $this->others(fn () => parent::get_value($object_id, $request));
    }

    public function update_value($meta, $object_id)
    {
        if (!is_array($meta)) {
            return null;
        }
        $fields = self::fields($this->spec());
        $values = $norms = [];
        $first = $this->input ?? $meta;
        $db = Plugin::instance()?->db() ?? throw new \LogicException('No TypedPress app');
        $viewer = CoreAccess::viewer();
        $before = null;
        foreach (array_intersect_key($first, $fields) as $col => $v) {
            $col = (string) $col;
            $def = $fields[$col];
            $ptr = HttpError::seg($col);
            $v = \TypedPress\Seam::toPhp($v, $def);
            if ($def['type'] === 'group' && $viewer !== null) {
                // A partial group keeps the stored sub-fields this viewer can't read or set, as in TypedPress's write pipeline.
                $before ??= $db->editorRow($this->collection, (int) $object_id) ?? [];
                $v = $db->current(static fn () => \TypedPress\FieldAccess::effectiveGroup('update', $def, $v, $before[$col] ?? null, $viewer));
            }
            $norm = Schema::normalize($def, $v, $col, $ptr);
            $norms[$col] = $norm;
            $values[$col] = Schema::store($def, $norm);
        }
        if ($norms !== []) {
            // Rules see the row as it will be, as Db::checkValues gives them: a rule may read another field.
            $before ??= $db->editorRow($this->collection, (int) $object_id) ?? [];
            $ctx = ['data' => $norms + $before, 'user' => WpAuth::current()];
            foreach ($norms as $col => $norm) {
                Schema::checkRules($fields[$col], $norm, (string) $col, HttpError::seg((string) $col), $ctx, $this->spec()['columns']);
            }
        }
        // Media ids must name attachments (of an accepted kind), as every other write path checks.
        $db->checkMedia($fields, $norms);
        if ($values !== []) {
            $store = $db->store();
            if (!$store instanceof CompositeStore) {
                throw new \LogicException('TypedPress post meta needs the WordPress store');
            }
            $store->posts->update($this->collection, (int) $object_id, $values);
        }
        $rest = array_diff_key($meta, $fields);

        return $rest === [] ? null : $this->others(fn () => parent::update_value($rest, $object_id));
    }

    private function spec(): array
    {
        return Plugin::instance()?->schema->collection($this->collection) ?? throw new \LogicException('No TypedPress app');
    }
}
