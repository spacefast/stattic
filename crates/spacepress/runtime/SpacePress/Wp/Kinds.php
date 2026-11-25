<?php

declare(strict_types=1);

namespace SpacePress\Wp;

/**
 * The facade's kind registry and the mapping between First rows and core shapes (spec §2.1, §3a).
 * Each kind names the core route that serves it; the mapping is data only.
 *
 * App kinds come from the schema:
 * - post kinds (`/wp/v2/<post type>`) and users (`/wp/v2/users`): core runs the route and decides the
 *   request; each item then carries SpacePress's row as this viewer reads it ({@see ROW}), so field masks,
 *   `{}` in json() values and system fields come out exactly as SpacePress's pipeline gives them. Input
 *   maps the other way: title/content/slug to core's fields, `status`/`publishedAt` to core's, the
 *   rest to `meta`;
 * - tables (`/wp/v2/<table>`): already First rows.
 *
 * WordPress kinds every site has (`builtin`): comments, posts, pages, media, categories, tags,
 * menus and settings, mapped to the field names of spec §2.1. A schema entry with `builtin` (an
 * `.extend()`) or an app kind of the same name replaces the plain built-in. Core decides access for
 * the built-ins, as it does for every other client of these routes.
 *
 * Relations `with` attached sit under `with` on core responses and are flattened onto the row.
 */
final class Kinds
{
    /**
     * Where a post-kind or users response carries SpacePress's row for this viewer during the facade's own
     * dispatches (PostsController, UserMeta::prepare): null when SpacePress's read rule doesn't cover it.
     */
    public const ROW = '__sp_row';

    /** The built-in kinds a site has without declaring them (spec §2.1), by name. */
    private const BUILTINS = [
        'comments' => ['type' => 'comments'],
        'posts' => ['type' => 'wp-post', 'postType' => 'post'],
        'pages' => ['type' => 'wp-post', 'postType' => 'page'],
        'media' => ['type' => 'media'],
        'categories' => ['type' => 'term', 'taxonomy' => 'category'],
        'tags' => ['type' => 'term', 'taxonomy' => 'post_tag'],
        'menus' => ['type' => 'menus'],
    ];

    /** @return array<string, array{name: string, type: string, route: string, spec: array, ...}> */
    public static function all(Plugin $plugin): array
    {
        $out = [];
        $auth = $plugin->schema->auth()['collection'] ?? null;
        foreach ($plugin->schema->collections() as $name => $spec) {
            $name = (string) $name;
            if (isset($spec['commerce'])) {
                $out[$name] = ['name' => $name, 'type' => 'table', 'route' => '/wp/v2/' . $spec['table'], 'spec' => $spec];
            } elseif ($name === $auth || ($spec['builtin'] ?? null) === 'users') {
                $out[$name] = ['name' => $name, 'type' => 'users', 'route' => '/wp/v2/users', 'spec' => $spec];
            } elseif (($spec['editor'] ?? true) && ($spec['wp'] ?? null) !== null) {
                $out[$name] = ['name' => $name, 'type' => 'post', 'route' => self::postRoute($spec['wp']['postType']), 'spec' => $spec]
                    + (($spec['drafts'] ?? false) ? ['drafts' => true] : []);
            } elseif (isset($spec['table'])) {
                $out[$name] = ['name' => $name, 'type' => 'table', 'route' => '/wp/v2/' . $spec['table'], 'spec' => $spec];
            }
        }
        // A `taxonomy()` kind: its core term route, answered through SpacePress's pipeline (TermsController).
        foreach ($plugin->schema->taxonomies() as $name => $spec) {
            $out[(string) $name] = ['name' => (string) $name, 'type' => isset($spec['commerce']) ? 'table' : 'taxonomy', 'route' => '/wp/v2/' . (isset($spec['commerce']) ? 'sp_' . $name : $spec['wp']['taxonomy']), 'spec' => $spec];
        }
        if ($auth !== null) {
            $out['users'] ??= $out[$auth]; // the spec's name for the users kind
        }
        foreach (self::BUILTINS as $name => $builtin) {
            if (isset($out[$name])) {
                continue; // the app's own kind of that name answers
            }
            $route = match ($builtin['type']) {
                'comments' => '/wp/v2/comments',
                'wp-post' => self::postRoute($builtin['postType']),
                'media' => '/wp/v2/media',
                'term' => self::termRoute($builtin['taxonomy']),
                'menus' => '/wp/v2/menu-locations',
            };
            if ($route !== null) {
                $out[$name] = ['name' => $name, 'route' => $route, 'spec' => ['builtin' => $name]] + $builtin;
            }
        }
        $out['users'] ??= ['name' => 'users', 'type' => 'wp-users', 'route' => '/wp/v2/users', 'spec' => ['builtin' => 'users']];

        return $out;
    }

    private static function postRoute(string $type): string
    {
        $object = get_post_type_object($type);

        return '/' . ($object?->rest_namespace ?: 'wp/v2') . '/' . ($object?->rest_base ?: $type);
    }

    private static function termRoute(string $taxonomy): ?string
    {
        $object = get_taxonomy($taxonomy);
        if (!$object instanceof \WP_Taxonomy || !$object->show_in_rest) {
            return null;
        }

        return '/' . ($object->rest_namespace ?: 'wp/v2') . '/' . ($object->rest_base ?: $taxonomy);
    }

    /** Whether the kind is one SpacePress's own pipeline answers (its rules, `where`, `with`), not core alone. */
    public static function first(array $kind): bool
    {
        return in_array($kind['type'], ['post', 'users', 'table', 'taxonomy'], true);
    }

    /** A core response item as the First row (fields flat, SpacePress's status, relations flattened). */
    public static function row(array $kind, mixed $item): mixed
    {
        if (!is_array($item)) {
            return $item;
        }
        // Relations attached by SpacePressQuery::attach, an object so that `{}` stays one on core's wire.
        $with = $item['with'] ?? [];
        $with = is_object($with) ? get_object_vars($with) : (is_array($with) ? $with : []);
        return match ($kind['type']) {
            'table' => (static function () use ($item, $with): array {
                unset($item['_links'], $item['with']);

                return array_replace($item, $with);
            })(),
            // SpacePress's row for this viewer, or null: core may list a user SpacePress's read rule hides.
            // `with` wins: a media column loaded with `with` replaces its id with the media row.
            'users', 'post', 'taxonomy' => ($first = $item[self::ROW] ?? null) === null ? null : array_replace(is_object($first) ? get_object_vars($first) : (array) $first, $with),
            'wp-post' => self::wpPostRow($kind, $item),
            'wp-users' => self::userRow($item),
            'comments' => self::commentRow($item),
            'media' => self::mediaRow($item),
            'term' => self::termRow($item),
            'menus' => $item,
        };
    }

    /** A core post or page (spec §2.1): the collection fields plus the type's own. */
    private static function wpPostRow(array $kind, array $item): array
    {
        $row = [
            'id' => $item['id'] ?? null,
            'title' => self::text($item['title'] ?? null),
            'content' => self::text($item['content'] ?? null),
            'slug' => $item['slug'] ?? null,
            'status' => self::status((string) ($item['status'] ?? '')),
            'publishedAt' => self::publishedAt($item),
            'url' => $item['link'] ?? null,
            'author' => ($item['author'] ?? 0) ?: null,
            'createdAt' => self::gmt($item['date_gmt'] ?? null),
            'updatedAt' => self::gmt($item['modified_gmt'] ?? null),
        ];

        return $row + match ($kind['postType']) {
            'post' => [
                'excerpt' => self::text($item['excerpt'] ?? null),
                'categories' => $item['categories'] ?? [],
                'tags' => $item['tags'] ?? [],
                'featuredImage' => ($item['featured_media'] ?? 0) ?: null,
                'sticky' => (bool) ($item['sticky'] ?? false),
            ],
            'page' => [
                'parent' => ($item['parent'] ?? 0) ?: null,
                'order' => (int) ($item['menu_order'] ?? 0),
                'template' => (string) ($item['template'] ?? ''),
            ],
            default => [],
        };
    }

    /** A WordPress user without a SpacePress users kind: what core shows this viewer. */
    private static function userRow(array $item): array
    {
        return array_filter([
            'id' => $item['id'] ?? null,
            'email' => $item['email'] ?? null,
            'name' => $item['name'] ?? null,
            'username' => $item['username'] ?? null,
            'roles' => $item['roles'] ?? null,
            'avatar' => is_array($item['avatar_urls'] ?? null) ? (end($item['avatar_urls']) ?: null) : null,
            'url' => ($item['url'] ?? '') ?: null,
            'locale' => $item['locale'] ?? null,
            'createdAt' => isset($item['registered_date']) ? self::iso((string) $item['registered_date']) : null,
        ], static fn ($v, $k) => $v !== null || in_array($k, ['id', 'name', 'avatar', 'url'], true), ARRAY_FILTER_USE_BOTH);
    }

    private static function commentRow(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'on' => ($item['post'] ?? 0) ?: null,
            'parent' => ($item['parent'] ?? 0) ?: null,
            'author' => ($item['author'] ?? 0) ?: null,
            'authorName' => $item['author_name'] ?? null,
            'authorEmail' => ($item['author_email'] ?? '') ?: null,
            'content' => self::text($item['content'] ?? null),
            'status' => match ($item['status'] ?? null) {
                'approved', 'approve', '1' => 'approved',
                'hold', 'unapproved', '0' => 'pending',
                default => $item['status'] ?? null,
            },
            'type' => $item['type'] ?? 'comment',
            'createdAt' => self::gmt($item['date_gmt'] ?? null),
        ];
    }

    /** @var array<int, array> SpacePress's media rows read ahead for the page being shaped ({@see rows()}) */
    private static array $media = [];

    /**
     * A page of core items as SpacePress's rows. For media, SpacePress's own rows are read in one query for the
     * whole page, not one per item.
     *
     * @param list<array> $items
     * @return list<mixed>
     */
    public static function rows(array $kind, array $items): array
    {
        if ($kind['type'] !== 'media' || $items === []) {
            return array_map(static fn (array $item) => self::row($kind, $item), $items);
        }
        $db = Plugin::instance()?->db();
        self::$media = $db === null ? [] : $db->media()->getMany(array_values(array_filter(array_map(static fn (array $i) => (int) ($i['id'] ?? 0), $items))));
        try {
            return array_map(static fn (array $item) => self::row($kind, $item), $items);
        } finally {
            self::$media = [];
        }
    }

    private static function mediaRow(array $item): array
    {
        $details = is_array($item['media_details'] ?? null) ? $item['media_details'] : [];
        $sizes = [];
        foreach ((array) ($details['sizes'] ?? []) as $name => $size) {
            if (is_array($size) && isset($size['source_url'])) {
                $sizes[(string) $name] = ['url' => $size['source_url'], 'width' => (int) ($size['width'] ?? 0), 'height' => (int) ($size['height'] ?? 0)];
            }
        }

        // SpacePress's own media row (its named sizes, focal point, file name and size) when SpacePress runs the
        // site; core's fields fill the rest (spec §2.1).
        $first = isset($item['id']) ? (self::$media[(int) $item['id']] ?? Plugin::instance()?->db()->mediaGet((int) $item['id'])) : null;

        return (is_array($first) ? $first : []) + [
            'id' => $item['id'] ?? null,
            'url' => $item['source_url'] ?? null,
            'alt' => ($item['alt_text'] ?? '') ?: null,
            'caption' => self::text($item['caption'] ?? null),
            'mime' => $item['mime_type'] ?? null,
            'width' => isset($details['width']) ? (int) $details['width'] : null,
            'height' => isset($details['height']) ? (int) $details['height'] : null,
            'sizes' => $sizes === [] ? new \stdClass() : $sizes,
            'focal' => $item['sp_focal'] ?? null,
            'author' => ($item['author'] ?? 0) ?: null,
            'createdAt' => self::gmt($item['date_gmt'] ?? null),
        ];
    }

    private static function termRow(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? null,
            'slug' => $item['slug'] ?? null,
            'description' => (string) ($item['description'] ?? ''),
            'parent' => ($item['parent'] ?? 0) ?: null,
            'count' => (int) ($item['count'] ?? 0),
            'url' => $item['link'] ?? null,
        ];
    }

    /** SpacePress input → core input: core's own fields for title/content/slug/status, `meta` for the rest. */
    public static function input(array $kind, array $data, bool $create = false): array
    {
        return match ($kind['type']) {
            'table' => $data,
            'post', 'users' => self::spInput($kind, $data, $create),
            // A term: its name as core's, everything else SpacePress's (TermsController reads `spacepress`).
            'taxonomy' => array_intersect_key($data, ['name' => true]) + (array_diff_key($data, ['name' => true]) === [] ? [] : ['spacepress' => (object) array_diff_key($data, ['name' => true])]),
            'wp-post' => self::rename($data, ['content' => 'content', 'title' => 'title', 'slug' => 'slug', 'excerpt' => 'excerpt',
                'categories' => 'categories', 'tags' => 'tags', 'featuredImage' => 'featured_media', 'sticky' => 'sticky',
                'parent' => 'parent', 'order' => 'menu_order', 'template' => 'template', 'author' => 'author'], self::statusInput($data, $create)),
            'comments' => self::rename($data, ['on' => 'post', 'parent' => 'parent', 'content' => 'content', 'authorName' => 'author_name',
                'authorEmail' => 'author_email', 'type' => 'type'], isset($data['status'])
                    ? ['status' => ['approved' => 'approved', 'pending' => 'hold'][$data['status']] ?? $data['status']] : []),
            'media' => self::rename($data, ['alt' => 'alt_text', 'caption' => 'caption', 'focal' => 'sp_focal']),
            'term' => self::rename($data, ['name' => 'name', 'slug' => 'slug', 'description' => 'description', 'parent' => 'parent']),
            default => throw new \SpacePress\HttpError(405, 'method_not_allowed', "{$kind['name']} is read-only here"),
        };
    }

    private static function statusInput(array $data, bool $create): array
    {
        if (isset($data['status'])) {
            return ['status' => ['published' => 'publish', 'scheduled' => 'future'][$data['status']] ?? $data['status']]
                + (isset($data['publishedAt']) ? ['date_gmt' => substr((string) $data['publishedAt'], 0, 19)] : []);
        }

        return ($create ? ['status' => 'publish'] : []) + (isset($data['publishedAt']) ? ['date_gmt' => substr((string) $data['publishedAt'], 0, 19)] : []);
    }

    /** @param array<string, string> $names SpacePress field → core field; any other field is refused */
    private static function rename(array $data, array $names, array $out = []): array
    {
        foreach ($data as $field => $value) {
            $field = (string) $field;
            if ($field === 'status' || $field === 'publishedAt') {
                continue; // mapped by the caller
            }
            $core = $names[$field] ?? throw \SpacePress\HttpError::invalidInput("Unknown field '$field'", \SpacePress\HttpError::seg($field));
            $out[$core] = $value;
        }

        return $out;
    }

    /** A field another plugin registered on $postType's REST route with register_rest_field, writable. */
    private static function restField(string $postType, string $field): bool
    {
        rest_get_server(); // fires rest_api_init once, where plugins register their fields

        return isset($GLOBALS['wp_rest_additional_fields'][$postType][$field]['update_callback']);
    }

    private static function spInput(array $kind, array $data, bool $create): array
    {
        $spec = $kind['spec'];
        $out = [];
        $meta = [];
        $first = [];
        foreach ($data as $col => $value) {
            $col = (string) $col;
            if ($kind['type'] === 'users') {
                // Core's own user fields; UserMeta applies them (and `meta`) through SpacePress's pipeline.
                match ($col) {
                    'email', 'name', 'password' => $out[$col] = $value,
                    // A role is one of SpacePress's role names; anything else (null included) is refused as
                    // SpacePress refuses it, never handed to core, which would read it as "no roles".
                    'role' => $out['roles'] = is_string($value) && $value !== ''
                        ? [WpUserStore::wpRole($value)]
                        : throw \SpacePress\HttpError::invalidInput('role is required', '/role'),
                    default => $meta[$col] = $value,
                };
                continue;
            }
            $designation = match ($col) {
                $spec['title'] ?? null => 'title',
                $spec['content'] ?? null => 'content',
                $spec['slug'] ?? null => 'slug',
                $spec['excerpt'] ?? null => 'excerpt',
                default => null,
            };
            if ($designation !== null) {
                $out[$designation] = $value;
            } elseif ($col === 'status' && ($spec['columns']['status']['system'] ?? false)) {
                $out['status'] = ['published' => 'publish', 'scheduled' => 'future', 'draft' => 'draft', 'pending' => 'pending'][$value] ?? $value;
            } elseif ($col === 'publishedAt' && ($spec['columns']['publishedAt']['system'] ?? false)) {
                $out['date_gmt'] = is_string($value) ? substr($value, 0, 19) : $value;
            } elseif (in_array($col, ['locale', 'translationOf'], true) && ($spec['localized'] ?? false)) {
                $out[$col] = $value; // a translation's language and group (PostsController::spWrite)
            } elseif ($kind['type'] === 'post' && self::restField($spec['wp']['postType'], $col)) {
                // A field another plugin registered on this post type's REST route (register_rest_field):
                // core's own update of it runs, as for any core client.
                $out[$col] = $value;
            } elseif (isset(PostMeta::fields($spec)[$col])) {
                // A field: core's `meta`, which core's permission check and SpacePress's rules on it read.
                $meta[$col] = $value;
            } else {
                // Anything else (`uid`, a typo): SpacePress's pipeline decides it (PostsController::spWrite
                // reads `spacepress`), so an unknown field is SpacePress's 422, not core's silent ignore.
                $first[$col] = $value;
            }
        }
        if ($kind['type'] === 'post' && !isset($out['status']) && $create) {
            $out['status'] = 'publish'; // SpacePress's default: a row without a status is published
        }
        if ($kind['type'] === 'users' && $create) {
            // Core requires a login and a password; SpacePress generates the login and, without one, a password.
            $out['username'] = 'zm_' . bin2hex(random_bytes(10));
            $out['password'] ??= wp_generate_password(32, true, true);
        }
        if ($meta !== []) {
            $out['meta'] = (object) $meta;
        }
        if ($first !== []) {
            $out['spacepress'] = (object) $first;
        }

        return $out;
    }

    /**
     * `menus` (spec §2.1): each registered location with its menu's items as a tree, read through
     * core's own menu routes as the viewer (public locations open to anyone, {@see Facade}).
     *
     * @param array<string, array> $locations core's `/wp/v2/menu-locations` answer
     * @param callable(int): list<array> $items a menu's items from `/wp/v2/menu-items?menus=<id>`
     */
    public static function menus(array $locations, callable $items): array
    {
        $out = [];
        foreach ($locations as $location) {
            if (!is_array($location)) {
                continue;
            }
            $menu = (int) ($location['menu'] ?? 0);
            $out[] = ['location' => (string) ($location['name'] ?? ''), 'items' => $menu > 0 ? self::tree($items($menu)) : []];
        }

        return $out;
    }

    /** @param list<array> $items core menu items, flat */
    private static function tree(array $items, int $parent = 0): array
    {
        $out = [];
        usort($items, static fn (array $a, array $b) => ($a['menu_order'] ?? 0) <=> ($b['menu_order'] ?? 0));
        foreach ($items as $item) {
            if ((int) ($item['parent'] ?? 0) !== $parent) {
                continue;
            }
            $out[] = [
                'label' => self::text($item['title'] ?? null),
                'url' => $item['url'] ?? null,
                'item' => ($item['object_id'] ?? 0) && ($item['type'] ?? '') !== 'custom' ? ['kind' => (string) ($item['object'] ?? ''), 'id' => (int) $item['object_id']] : null,
                'children' => self::tree($items, (int) ($item['id'] ?? 0)),
            ];
        }

        return $out;
    }

    /** `settings` (spec §2.1) from core's `/wp/v2/settings` and the index's public site fields. */
    public static function settings(array $index, ?array $settings): array
    {
        return [
            'title' => $settings['title'] ?? $index['name'] ?? '',
            'tagline' => $settings['description'] ?? $index['description'] ?? '',
            'timezone' => ($settings['timezone'] ?? $index['timezone_string'] ?? '') ?: 'UTC',
            'locale' => $settings['language'] ?? str_replace('-', '_', (string) get_bloginfo('language')),
            'icon' => ((int) ($settings['site_icon'] ?? $index['site_icon'] ?? 0)) ?: null,
            'logo' => ((int) ($settings['site_logo'] ?? $index['site_logo'] ?? 0)) ?: null,
            'frontPage' => ($settings['show_on_front'] ?? get_option('show_on_front')) === 'page'
                ? (((int) ($settings['page_on_front'] ?? get_option('page_on_front'))) ?: null) : null,
        ];
    }

    /** `settings` input → core's `/wp/v2/settings` body (admins only, by core's rules). */
    public static function settingsInput(array $data): array
    {
        return self::rename($data, ['title' => 'title', 'tagline' => 'description', 'timezone' => 'timezone', 'locale' => 'language',
            'icon' => 'site_icon', 'logo' => 'site_logo', 'frontPage' => 'page_on_front']);
    }

    /**
     * `?format=md` on an item: the post exactly as its Markdown twin renders it ({@see MarkdownTwins::post()}),
     * the same producer as core's `?format=md`. Tables and other non-post kinds have no Markdown form.
     */
    public static function markdown(array $kind, mixed $item): ?string
    {
        if (!in_array($kind['type'], ['post', 'wp-post'], true) || !is_array($item)) {
            return null;
        }
        $post = get_post((int) ($item['id'] ?? 0));

        return $post instanceof \WP_Post ? MarkdownTwins::post($post) : null;
    }

    /** `GET /wp-json/spacepress/v1`: the app's kinds (with their routes), fields, globals and actions. */
    public static function describe(Plugin $plugin): array
    {
        $described = \SpacePress\Extensions::describe($plugin->schema->describe());
        $kinds = [];
        foreach (self::all($plugin) as $name => $kind) {
            $kinds[$name] = ['route' => $kind['route'], 'type' => self::first($kind) ? $kind['type'] : 'builtin']
                + (self::first($kind) ? ($described['collections'][$kind['spec']['key'] ?? $name] ?? $described['collections'][$name] ?? []) : []);
        }
        $kinds['settings'] = ['route' => '/wp/v2/settings', 'type' => 'builtin'];
        $actions = [];
        foreach ($plugin->schema->actions() as $name => $a) {
            if (($a['signature'] ?? null) !== null) {
                continue;
            }
            $actions[$name] = ($described['actions'][$name] ?? []) + [
                'readonly' => (bool) ($a['readonly'] ?? false),
                'destructive' => (bool) ($a['destructive'] ?? false),
                'output' => $a['output'] ?? null,
            ];
        }

        return array_diff_key($described, ['collections' => true, 'actions' => true])
            + ['name' => $plugin->schema->name(), 'kinds' => $kinds, 'globals' => $described['globals'] ?? [], 'actions' => $actions];
    }

    private static function text(mixed $v): mixed
    {
        return is_array($v) ? ($v['raw'] ?? $v['rendered'] ?? null) : $v;
    }

    private static function status(string $status): string
    {
        return match ($status) {
            'publish' => 'published',
            'future' => 'scheduled',
            'pending' => 'pending',
            'private' => 'private',
            default => 'draft',
        };
    }

    private static function publishedAt(array $item): ?string
    {
        return in_array($item['status'] ?? null, ['publish', 'future'], true) && is_string($item['date_gmt'] ?? null) ? $item['date_gmt'] . '.000Z' : null;
    }

    private static function gmt(mixed $v): ?string
    {
        return is_string($v) && $v !== '' ? $v . '.000Z' : null;
    }

    private static function iso(string $v): string
    {
        $t = strtotime($v);

        return $t === false ? $v : gmdate('Y-m-d\TH:i:s', $t) . '.000Z';
    }
}
