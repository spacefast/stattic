<?php

declare(strict_types=1);

namespace TypedPress\Wp;

use TypedPress\Js;
use TypedPress\Wp\Abilities\JsonSchema;

/**
 * Core `meta` for a TypedPress taxonomy (ruling 25): every authored field is registered term meta
 * (`tp_<field>`, `show_in_rest` under its own name), so `/wp/v2/tp_<name>` reads `meta.<field>`
 * in TypedPress's value form, with the fields this viewer may not read left out. Writes never go through
 * core's generic meta path (`auth_callback` false): {@see TermsController} reads `meta` and saves it
 * through TypedPress's pipeline.
 */
final class TermMeta extends \WP_REST_Term_Meta_Fields
{
    public function __construct(string $taxonomy, private readonly string $collection)
    {
        parent::__construct($taxonomy);
    }

    /** register_term_meta for every authored field of a taxonomy (init). */
    public static function register(array $spec): void
    {
        foreach (self::fields($spec) as $col => $def) {
            $schema = JsonSchema::column($def, true);
            $types = array_values(array_diff((array) $schema['type'], ['null']));
            register_term_meta($spec['wp']['taxonomy'], $spec['wp']['metaPrefix'] . $col, [
                'single' => true,
                'type' => count($types) === 1 ? $types[0] : 'object',
                'label' => (string) ($def['ui']['label'] ?? $col),
                'show_in_rest' => ['name' => $col, 'schema' => $schema],
                'auth_callback' => '__return_false',
            ]);
        }
    }

    /** @return array<string, array> the authored fields, kept as term meta */
    public static function fields(array $spec): array
    {
        return array_filter($spec['columns'], static fn (string $col) => WpTermStore::isMeta($spec, $col), ARRAY_FILTER_USE_KEY);
    }

    /** The request's `meta`, read from a JSON body with TypedPress's decoder (core's turns `{}` into `[]`). */
    public static function input(\WP_REST_Request $request): ?array
    {
        $body = (string) $request->get_body();
        if (($request->get_content_type()['value'] ?? null) === 'application/json' && trim($body) !== '') {
            try {
                $decoded = \TypedPress\Seam::container(Js::decodeJson($body));
                if (is_array($decoded) && array_key_exists('meta', $decoded)) {
                    $meta = \TypedPress\Seam::container($decoded['meta']);

                    return is_array($meta) ? $meta : null;
                }
            } catch (\JsonException) {
            }
        }
        $meta = $request->get_param('meta');

        return is_array($meta) ? $meta : null;
    }

    public function get_value($object_id, $request)
    {
        $plugin = Plugin::instance() ?? throw new \LogicException('No TypedPress app');
        $spec = $plugin->schema->collection($this->collection);
        $fields = array_keys(self::fields($spec));
        $viewer = CoreAccess::viewer();
        $db = $plugin->db();
        // The term as this viewer reads it: a field its rules hide is left out.
        $row = $viewer === null ? null : $db->findFirst($this->collection, ['where' => [$spec['primaryKey'] => (int) $object_id]], $viewer);
        $visible = $row === null ? [] : array_intersect_key($row, array_flip($fields));

        return PostMeta::values($this->collection, $visible, array_map('strval', array_keys($visible)));
    }
}
