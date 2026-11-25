<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Access;
use SpacePress\Json;

/** Authorization around Gutenberg's experimental, opaque collaboration documents. */
final class Coediting
{
    public const VERSION = '24.4.1';
    public const DOCUMENT = '_crdt_document';
    public const SNAPSHOT = '_crdt_autosave_snapshot';
    public const HEADER = 'X-SpacePress-Coediting-Schemas';
    private const SCHEMA = '_sp_coediting_schema';

    public static function register(): void
    {
        add_filter('wp_is_post_type_collaboration_disabled', [self::class, 'disabled'], PHP_INT_MAX, 2);
        add_filter('rest_pre_dispatch', [self::class, 'request'], 5, 3);
        add_filter('__unstable_wp_sync_storage', static fn ($storage) =>
            defined('GUTENBERG_VERSION') && GUTENBERG_VERSION === self::VERSION
                ? new CoeditingStorage($storage) : $storage, PHP_INT_MAX);
        add_filter('auth_post_meta_' . self::DOCUMENT, [self::class, 'authorizeMeta'], PHP_INT_MAX, 3);
        add_filter('get_post_metadata', [self::class, 'metadata'], PHP_INT_MAX, 5);
        add_action('added_post_meta', [self::class, 'saved'], 10, 4);
        add_action('updated_post_meta', [self::class, 'saved'], 10, 4);
        add_action('admin_enqueue_scripts', [self::class, 'editor']);
        add_action('sp_admin_init', [self::class, 'editor']);
    }

    /** All participants must have the same field and HTML authority. */
    public static function eligible(string $collection): bool
    {
        $plugin = Plugin::instance();
        if ($plugin === null || $plugin->unavailable() !== null || !defined('GUTENBERG_VERSION')
            || GUTENBERG_VERSION !== self::VERSION || !function_exists('wp_is_collaboration_enabled')
            || !wp_is_collaboration_enabled() || AgentScope::current() !== null
            || !current_user_can('unfiltered_html')) {
            return false;
        }
        $spec = $plugin->schema->collection($collection);
        $rules = Access::collectionRules($spec);
        $type = isset($spec['wp']['postType']) ? get_post_type_object($spec['wp']['postType']) : null;
        $viewer = CoreAccess::viewer();

        // Native posts retain core's role-specific publish/author capabilities. A peer's
        // save must not lend that authority to another participant's opaque updates.
        return !($spec['native'] ?? false) && $type !== null && ($type->rest_namespace ?: 'wp/v2') === 'wp/v2'
            && ($spec['content'] ?? null) !== null
            && !isset($rules['read']['fn']) && !isset($rules['update']['fn']) && self::fields($spec['columns'])
            && $viewer !== null && Access::evaluate($rules['read'], $viewer, 'read') === true
            && Access::evaluate($rules['update'], $viewer, 'update') === true;
    }

    private static function fields(array $fields): bool
    {
        foreach ($fields as $def) {
            if (!empty($def['access']) || isset($def['references'])
                || in_array($def['type'], ['media', 'file', 'refList'], true)) {
                return false;
            }
            if (!self::fields($def['fields'] ?? [])) {
                return false;
            }
            foreach ($def['layouts'] ?? [] as $layout) {
                if (!self::fields($layout)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function allows(string $collection, int $id): bool
    {
        if (!self::eligible($collection) || ($viewer = CoreAccess::viewer()) === null) {
            return false;
        }
        $post = get_post($id);
        $type = Plugin::instance()->schema->collection($collection)['wp']['postType'];

        return $post instanceof \WP_Post && $post->post_type === $type && $post->post_password === ''
            && (in_array($post->post_status, ['draft', 'pending', 'publish', 'future', 'private'], true)
                || ($post->post_status === 'auto-draft' && (int) $post->post_author === $viewer->user['id']))
            && current_user_can('edit_post', $id)
            && CoreAccess::covers($collection, 'read', $id, $viewer)
            && CoreAccess::covers($collection, 'update', $id, $viewer);
    }

    public static function disabled(bool $disabled, string $postType): bool
    {
        $collection = CoreAccess::collection($postType);

        return $disabled || ($collection !== null && !self::eligible($collection));
    }

    public static function fingerprint(string $collection): string
    {
        $spec = Plugin::instance()->schema->collection($collection);

        return hash('sha256', Json::encode([$spec, Plugin::instance()->buildHash()]));
    }

    /** A schema change starts another upstream storage namespace; old updates remain intact. */
    public static function storageRoom(string $room): string
    {
        if (preg_match('#^postType/([^/:]+):[1-9][0-9]*$#D', $room, $match)
            && ($collection = CoreAccess::collection($match[1])) !== null) {
            return $room . ':spacepress:' . self::fingerprint($collection);
        }

        return $room;
    }

    /** Validate the complete batch before Gutenberg can store any opaque update. */
    public static function request(mixed $result, mixed $server, mixed $request): mixed
    {
        if ($result !== null || !$request instanceof \WP_REST_Request) {
            return $result;
        }
        // WordPress matches registered REST routes without regard to case.
        $route = strtolower(rtrim($request->get_route(), '/'));
        if (in_array($route, ['/wp-sync/v1/updates', '/wp-sync/v1/save'], true)) {
            $rooms = $route === '/wp-sync/v1/save' ? [$request->get_param('room')]
                : array_map(static fn ($entry) => is_array($entry) ? ($entry['room'] ?? null) : null,
                    is_array($request->get_param('rooms')) ? $request->get_param('rooms') : []);
            $denied = [];
            foreach ($rooms as $room) {
                if (is_string($room) && self::managedRoom($room)
                    && !self::roomAllowed($room, $request)) {
                    $denied[] = $room;
                }
            }

            return $denied === [] ? $result : self::refused($denied);
        }
        $meta = $request->get_param('meta');
        if (((is_array($meta) && array_key_exists(self::DOCUMENT, $meta))
                || $request->has_param('crdt_snapshot'))
            && preg_match('#^/wp/v2/([^/]+)(?:/([0-9]+))?(?:/.*)?$#D', $route, $match)) {
            foreach (Plugin::instance()?->schema->collections() ?? [] as $collection => $spec) {
                $type = $spec['wp']['postType'] ?? null;
                $object = $type === null ? null : get_post_type_object($type);
                if ($object !== null && ($object->rest_base ?: $type) === $match[1]
                    && (!self::allows((string) $collection, (int) ($match[2] ?? 0))
                        || !self::currentSchema((string) $collection, $request))) {
                    return self::refused([]);
                }
            }
        }

        return $result;
    }

    private static function managedRoom(string $room): bool
    {
        if (!preg_match('#^(postType|taxonomy)/([^/:]+)(?::([^/]+))?$#D', $room, $match)) {
            return false;
        }
        if ($match[1] === 'taxonomy') {
            return CoreAccess::taxonomyKind($match[2]) !== null;
        }

        return CoreAccess::collection($match[2]) !== null
            || (isset($match[3]) && ctype_digit($match[3]) && self::owner((int) $match[3]) !== null);
    }

    private static function roomAllowed(string $room, \WP_REST_Request $request): bool
    {
        if (!preg_match('#^postType/([^/:]+):([1-9][0-9]*)$#D', $room, $match)
            || ($collection = CoreAccess::collection($match[1])) === null) {
            return false;
        }

        return self::allows($collection, (int) $match[2]) && self::currentSchema($collection, $request);
    }

    private static function currentSchema(string $collection, \WP_REST_Request $request): bool
    {
        $schemas = json_decode($request->get_header(self::HEADER) ?? '', true);
        $type = Plugin::instance()->schema->collection($collection)['wp']['postType'];

        return is_array($schemas) && ($schemas[$type] ?? null) === self::fingerprint($collection);
    }

    private static function refused(array $rooms): \WP_Error
    {
        return new \WP_Error('sp_coediting_unavailable', 'Collaboration is unavailable for this document or editor. Reload the editor after a schema change.', ['status' => 403, 'rooms' => $rooms]);
    }

    /** A revision or autosave belongs to its parent's collection. */
    private static function owner(int $id): ?array
    {
        $post = get_post($id);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        $parent = $post->post_type === 'revision' ? get_post($post->post_parent) : $post;
        $collection = $parent instanceof \WP_Post ? CoreAccess::collection($parent->post_type) : null;

        return $collection === null ? null : [$collection, $parent->ID];
    }

    public static function authorizeMeta(bool $allowed, string $key, int $id): bool
    {
        $owner = self::owner($id);

        return $allowed && ($owner === null || self::allows($owner[0], $owner[1]));
    }

    public static function readable(string $collection, int $id, string $key = self::DOCUMENT): bool
    {
        $owner = self::owner($id);

        return $owner !== null && self::allows($collection, $owner[1])
            && get_post_meta($id, self::SCHEMA . $key, true) === self::fingerprint($collection);
    }

    public static function metadata(mixed $value, int $id, string $key, bool $single, string $type): mixed
    {
        if (in_array($key, [self::DOCUMENT, self::SNAPSHOT], true)
            && ($owner = self::owner($id)) !== null && !self::readable($owner[0], $id, $key)) {
            return $single ? '' : [];
        }

        return $value;
    }

    public static function saved(mixed $metaId, int $id, string $key, mixed $value): void
    {
        if (in_array($key, [self::DOCUMENT, self::SNAPSHOT], true)
            && ($owner = self::owner($id)) !== null && self::allows($owner[0], $owner[1])) {
            update_metadata('post', $id, self::SCHEMA . $key, self::fingerprint($owner[0]));
        }
    }

    /** Bind an open editor to the schema it loaded, including in classic wp-admin. */
    public static function editor(): void
    {
        if (!defined('GUTENBERG_VERSION') || GUTENBERG_VERSION !== self::VERSION) {
            return;
        }
        // The SPA fetches editor assets after its import map has printed. Enqueue
        // Gutenberg's tiny loaders now so their lazy dependencies are in that map.
        foreach (['@wordpress/latex-to-mathml/loader', '@wordpress/vips/loader', '@wordpress/video-conversion/loader'] as $module) {
            wp_enqueue_script_module($module);
        }
        $schemas = [];
        foreach (Plugin::instance()?->schema->collections() ?? [] as $collection => $spec) {
            if (isset($spec['wp']['postType'])) {
                $schemas[$spec['wp']['postType']] = self::fingerprint((string) $collection);
            }
        }
        wp_add_inline_script('wp-api-fetch', 'wp.apiFetch.use((options, next) => { if (typeof options.path === "string" && /^\\/(wp-sync\\/v1|wp\\/v2)\\//.test(options.path)) { options = { ...options, headers: { ...options.headers, ' . Json::encode(self::HEADER) . ': ' . Json::encode(Json::encode($schemas)) . ' } }; } return next(options); });');
    }
}
