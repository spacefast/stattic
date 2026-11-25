<?php

declare(strict_types=1);

namespace SpacePress\Wp;

use SpacePress\Db;

/**
 * A kind's `url` pattern on WordPress (docs/spec/spec.md §2.7) and the front-end modes (§5).
 *
 * One pattern parses incoming URLs and builds outgoing links, so the two never disagree:
 * - a rewrite rule per pattern, so WordPress itself resolves `/notes/hello` to the item, and its
 *   template hierarchy renders it with the theme when no `sp/app/` page serves the URL;
 * - `post_type_link` builds every link core makes (`get_permalink`, canonical, feeds, sitemaps,
 *   REST `link`) from the same pattern;
 * - a slug change records the old URL (core's `_wp_old_slug`), and a request for it 301s to the
 *   item's current URL.
 *
 * Front-end modes, on `template_redirect` at priority -1, before the app router (1):
 * - default: WordPress renders with its theme (no `sp/app/`), or the app router serves
 *   (with `sp/app/`);
 * - `frontend: false`: backend only. Every public HTML URL is a 404; wp-admin, login, REST,
 *   feeds of nothing and SpacePress's own paths keep working;
 * - `frontend: "https://…"`: an external site renders. Front-end URLs redirect there with their
 *   path, and every link core builds points there.
 */
final class KindUrls
{
    /** @var array<string, array{type: string, pattern: string, segments: list<string>, slug: ?string, reserved: list<string>}> */
    private array $kinds = [];
    /** @var array<string, array{taxonomy: string, pattern: string, segments: list<string>}> taxonomies with a `url` (ruling 30) */
    private array $terms = [];
    private static ?self $instance = null;

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public static function register(Plugin $plugin): void
    {
        $self = self::$instance = new self($plugin);
        if ($self->kinds !== [] || $self->terms !== []) {
            add_action('init', [$self, 'rewrites'], 1);
            // After Polylang's own (20), which prefixes a translation's link with its language
            // (`/tr/drives/x/`): the kind's pattern is the item's one URL in every language, and a
            // translation is told apart by its own slug.
            add_filter('post_type_link', [$self, 'link'], 100, 2);
            // WordPress's own posts (`wp.posts.extend({}, { url })`): core builds their links with `post_link`.
            add_filter('post_link', [$self, 'link'], 100, 2);
            add_action('template_redirect', [$self, 'oldSlug'], 0);
            add_filter('request', [$self, 'request']);
            add_filter('term_link', [$self, 'termLink'], 10, 3);
            add_action('pre_get_posts', [$self, 'termArchive']);
        }
        $frontend = $plugin->schema->frontend();
        if ($frontend === false) {
            add_action('template_redirect', [self::class, 'backendOnly'], -1);
        } elseif (is_string($frontend)) {
            add_action('template_redirect', static fn () => self::external($frontend), -1);
            foreach (['post_link', 'page_link', 'post_type_link', 'term_link', 'attachment_link'] as $filter) {
                add_filter($filter, static fn (string $url): string => self::rebase($url, $frontend), PHP_INT_MAX);
            }
            add_filter('preview_post_link', static fn (string $url): string => self::rebase($url, $frontend), PHP_INT_MAX);
        }
    }

    private function __construct(private readonly Plugin $plugin)
    {
        foreach ($plugin->schema->collections() as $name => $spec) {
            if (!is_string($spec['url'] ?? null) || !isset($spec['wp']['postType'])) {
                continue;
            }
            $this->kinds[$name] = [
                'type' => (string) $spec['wp']['postType'],
                'pattern' => $spec['url'],
                'segments' => explode('/', trim($spec['url'], '/')),
                'slug' => $spec['slug'] ?? null,
                // WordPress's and SpacePress's own first segments, which a kind at the root never takes.
                'reserved' => array_map('strtolower', $spec['reserved'] ?? []),
            ];
        }
        foreach ($plugin->schema->taxonomies() as $name => $spec) {
            if (is_string($spec['url'] ?? null)) {
                $this->terms[$name] = [
                    'taxonomy' => (string) $spec['wp']['taxonomy'],
                    'pattern' => $spec['url'],
                    'segments' => explode('/', trim($spec['url'], '/')),
                ];
            }
        }
    }

    /** One rewrite rule per kind: literals as written, each placeholder one segment, `:path` the rest. */
    public function rewrites(): void
    {
        foreach ($this->kinds as $name => $kind) {
            if (self::root($kind)) {
                continue; // resolved in request(), after every rule: a rule would match every path
            }
            $regex = '';
            $query = [];
            $n = 0;
            foreach ($kind['segments'] as $segment) {
                $regex .= ($regex === '' ? '' : '/');
                if (!str_starts_with($segment, ':')) {
                    $regex .= preg_quote($segment, '#');
                    continue;
                }
                $n++;
                $field = substr($segment, 1);
                $regex .= $field === 'path' ? '(.+?)' : '([^/]+)';
                $query[$field] = '$matches[' . $n . ']';
            }
            $vars = ['post_type=' . $kind['type'], 'sp_kind=' . $name];
            foreach ($query as $field => $match) {
                $vars[] = match ($field) {
                    'slug' => 'name=' . $match,
                    // A hierarchical kind's parent chain, in SpacePress's own query var and resolved in
                    // request(). Never core's `pagename`: under a permalink structure that starts with
                    // `%postname%` (SpacePress's own), WP::parse_request skips a rule that sets it unless
                    // a Page is at that path, so the URL was a 404 to the rest of WordPress.
                    'path' => 'sp_kind_path=' . $match,
                    'id' => 'p=' . $match,
                    'year' => 'year=' . $match,
                    'month' => 'monthnum=' . $match,
                    'day' => 'day=' . $match,
                    default => 'sp_url_' . $field . '=' . $match,
                };
            }
            add_rewrite_rule('^' . $regex . '/?$', 'index.php?' . implode('&', $vars), 'top');
        }
        // A taxonomy's pattern: one rule, resolved to a term of that taxonomy only (request()).
        foreach ($this->terms as $name => $kind) {
            $regex = '';
            $n = 0;
            $vars = ['sp_term=' . $name];
            foreach ($kind['segments'] as $segment) {
                $regex .= ($regex === '' ? '' : '/');
                if (!str_starts_with($segment, ':')) {
                    $regex .= preg_quote($segment, '#');
                    continue;
                }
                $n++;
                $field = substr($segment, 1);
                $regex .= $field === 'path' ? '(.+?)' : '([^/]+)';
                $vars[] = 'sp_term_' . $field . '=$matches[' . $n . ']';
            }
            add_rewrite_rule('^' . $regex . '/?$', 'index.php?' . implode('&', $vars), 'top');
        }
        foreach (['sp_kind', 'sp_kind_path', 'sp_term', 'sp_term_slug', 'sp_term_id', 'sp_term_path'] as $tag) {
            add_rewrite_tag("%$tag%", '([^&]+)');
        }
        // The rules come from the build: flush once whenever the set of patterns changes, kinds' and
        // taxonomies' alike. A managed build flushes from initialize instead (Runtime::flushRewrites),
        // never on a request.
        if (!Runtime::managed() && !self::recorded()) {
            flush_rewrite_rules(false);
            self::record();
        }
    }

    /**
     * The patterns of this build's kinds and taxonomies, as `sp_kind_urls` records them, and the
     * query var a `:path` rule carries the path in: rules stored while that was core's `pagename`
     * were recorded without it, so a site that has one rebuilds its rules once.
     */
    private static function digest(): string
    {
        $i = self::$instance;
        $paths = array_filter($i?->kinds ?? [], static fn (array $kind): bool => !self::root($kind) && in_array(':path', $kind['segments'], true));

        return md5((string) json_encode([...array_column($i?->kinds ?? [], 'pattern'), ...array_column($i?->terms ?? [], 'pattern'), ...($paths === [] ? [] : ['sp_kind_path'])]));
    }

    /** Whether the stored rules were built for this build's kind and taxonomy patterns. */
    public static function recorded(): bool
    {
        return self::$instance === null || (self::$instance->kinds === [] && self::$instance->terms === []) || get_option('sp_kind_urls') === self::digest();
    }

    /** Records that the stored rules were just built for this build's patterns. */
    public static function record(): void
    {
        if (self::$instance !== null && (self::$instance->kinds !== [] || self::$instance->terms !== [])) {
            update_option('sp_kind_urls', self::digest(), true);
        }
    }

    /**
     * A matched kind URL queries that kind's items only, so `/notes/hello` never resolves a post
     * named `hello` of another type; the remaining placeholders (`:year`, fields) must agree with
     * the item, or the URL is a 404.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public function request(array $vars): array
    {
        $term = $vars['sp_term'] ?? null;
        if (is_string($term) && isset($this->terms[$term])) {
            return $this->termRequest($term, $vars);
        }
        $name = $vars['sp_kind'] ?? null;
        if (!is_string($name) || !isset($this->kinds[$name])) {
            return $this->rootItem($vars) ?? $vars;
        }
        unset($vars['sp_kind']);
        $vars['post_type'] = $this->kinds[$name]['type'];
        // `pagename`: a rule stored before the path had a query var of SpacePress's own, which a managed
        // site keeps until its next deploy rebuilds the rules.
        $path = $vars['sp_kind_path'] ?? $vars['pagename'] ?? null;
        if ($path !== null && in_array(':path', $this->kinds[$name]['segments'], true)) {
            // Resolve the path in this kind only, the whole parent chain, and query that item by id.
            // Core would resolve a `pagename` as a Page first (WP_Query::parse_query), so a Page at the
            // same path became the queried object and the URL redirected to itself.
            $post = get_page_by_path((string) $path, OBJECT, $this->kinds[$name]['type']);
            unset($vars['sp_kind_path'], $vars['pagename']);
            // A `/` written as `%2F` (or `.`/`..`) in the URL isn't a path segment: no item has it.
            if (self::segments() === null) {
                $post = null;
            }
            // Under an unpublished page an item is as hidden as that page, to everyone but an editor's preview.
            if ($post instanceof \WP_Post && !self::published($post) && !(isset($vars['preview']) && current_user_can('edit_post', $post->ID))) {
                $post = null;
            }
            if ($post instanceof \WP_Post) {
                $vars['p'] = $post->ID;
            } else {
                $vars['error'] = '404';
            }
        }
        // Fields other than slug/id/date are checked once the item is known (oldSlug runs then).
        return $vars;
    }

    /** A kind at the site root: `url: "/:path"`, a hierarchical kind's whole parent chain. */
    private static function root(array $kind): bool
    {
        return $kind['segments'] === [':path'];
    }

    /**
     * A kind at the site root (`/:path`) answers a path no other kind's pattern matched, and that
     * WordPress itself has nothing at (no published post, Page or other kind's item by that name or
     * path), when one of its published items has exactly that path, parent by parent:
     * `/reference/config` is the `config` item under `reference`. WordPress's own entry points,
     * SpacePress's, feeds and archives are never asked, and a path segment that is empty, `.`, `..` or
     * hides a `/` (`%2F`) is no item's. Every other query var (`preview`, `page`) is kept.
     *
     * @param array<string, mixed> $vars
     * @return ?array<string, mixed> the query for that item
     */
    private function rootItem(array $vars): ?array
    {
        foreach ($this->kinds as $kind) {
            if (!self::root($kind)) {
                continue;
            }
            // Only WordPress's own catch-all permalink rules: a post by name, a Page by path, or
            // nothing matched. Any other rule (a plugin's, an archive) is WordPress's request.
            $rest = array_diff_key($vars, array_flip(['name', 'pagename', 'page', 'error', 'attachment', 'preview', 'p', 'post_type', 'cpage']));
            if ($rest !== []) {
                return null;
            }
            $segments = self::segments();
            if ($segments === null || $segments === []) {
                return null;
            }
            $first = strtolower($segments[0]);
            if (in_array($first, $kind['reserved'], true) || str_starts_with($first, 'wp-')) {
                return null;
            }
            $path = implode('/', $segments);
            // WordPress has something at this path: it keeps it.
            $types = array_values(array_diff(get_post_types(['public' => true]), [$kind['type'], 'attachment']));
            if (get_page_by_path($path, OBJECT, $types) instanceof \WP_Post) {
                return null;
            }
            $post = get_page_by_path($path, OBJECT, $kind['type']);
            if (!$post instanceof \WP_Post) {
                return null;
            }
            $preview = isset($vars['preview']) && current_user_can('edit_post', $post->ID);
            if (!$preview && ($post->post_status !== 'publish' || !self::published($post))) {
                return null;
            }
            $kept = array_diff_key($vars, array_flip(['name', 'pagename', 'attachment', 'error', 'p', 'post_type']));
            return ['post_type' => $kind['type'], 'p' => $post->ID] + $kept;
        }
        return null;
    }

    /**
     * The request's path below the home URL as decoded segments, or null when a segment is no
     * part of a path (empty, `.`, `..`, or a `/` written as `%2F`).
     *
     * @return ?list<string>
     */
    private static function segments(): ?array
    {
        $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '') {
            if ($path !== $home && !str_starts_with($path, $home . '/')) {
                return null;
            }
            $path = substr($path, strlen($home));
        }
        $out = [];
        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }
            $segment = rawurldecode($raw);
            if ($segment === '.' || $segment === '..' || str_contains($segment, '/') || str_contains($segment, '\\') || str_contains($segment, "\0")) {
                return null;
            }
            $out[] = $segment;
        }
        return $out;
    }

    /** Whether every page above a post is published: a page under a draft is as hidden as the draft. */
    public static function published(\WP_Post $post): bool
    {
        foreach (get_post_ancestors($post) as $ancestor) {
            if (get_post_status($ancestor) !== 'publish') {
                return false;
            }
        }
        return true;
    }

    /**
     * A matched taxonomy URL queries that one term's archive: `:slug` and `:id` name it, `:path` names
     * it with the chain of its ancestors' slugs, which must match. Anything else is a 404.
     *
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function termRequest(string $name, array $vars): array
    {
        $kind = $this->terms[$name];
        $given = [];
        foreach (['slug', 'id', 'path'] as $field) {
            if (isset($vars["sp_term_$field"])) {
                $given[$field] = (string) $vars["sp_term_$field"];
            }
            unset($vars["sp_term_$field"]);
        }
        unset($vars['sp_term']);
        $term = null;
        if (isset($given['id']) && ctype_digit($given['id'])) {
            $term = get_term((int) $given['id'], $kind['taxonomy']);
        } elseif (isset($given['slug'])) {
            $term = get_term_by('slug', rawurldecode($given['slug']), $kind['taxonomy']);
        } elseif (isset($given['path'])) {
            $slugs = explode('/', trim(rawurldecode($given['path']), '/'));
            $term = get_term_by('slug', (string) end($slugs), $kind['taxonomy']);
            if ($term instanceof \WP_Term && $this->termChain($term) !== $slugs) {
                $term = null;
            }
        }
        if (!$term instanceof \WP_Term || (isset($given['slug']) && isset($given['id']) && $term->slug !== rawurldecode($given['slug']))) {
            return ['error' => '404'];
        }

        return ['taxonomy' => $kind['taxonomy'], 'term' => $term->slug] + array_diff_key($vars, ['taxonomy' => 1, 'term' => 1]);
    }

    /**
     * A SpacePress term's archive (ruling 30) lists the items of its attached kinds that have a `url` of
     * their own, each kind's under its read rule for this viewer; core would list none of them, since
     * SpacePress's post types are left out of searches.
     */
    public function termArchive(\WP_Query $query): void
    {
        if (is_admin() || !$query->is_main_query() || !$query->is_tax()) {
            return;
        }
        $taxonomy = (string) $query->get('taxonomy');
        $kind = null;
        foreach ($this->terms as $n => $t) {
            if ($t['taxonomy'] === $taxonomy) {
                $kind = $n;
            }
        }
        if ($kind === null) {
            return;
        }
        $viewer = CoreAccess::viewer();
        $types = $clauses = [];
        foreach ($this->plugin->schema->collection($kind)['wp']['on'] ?? [] as $attached) {
            if (!isset($this->kinds[$attached])) {
                continue;
            }
            $keys = $viewer === null ? false : CoreAccess::keys($attached, 'read', $viewer);
            if ($keys === false) {
                continue;
            }
            $type = $this->kinds[$attached]['type'];
            $types[] = $type;
            if (is_array($keys)) {
                $clauses[$type] = array_map('intval', $keys);
            }
        }
        $query->set('post_type', $types === [] ? ['sp__none'] : $types);
        if ($clauses !== []) {
            add_filter('posts_where', static function (string $where, \WP_Query $q) use ($query, $clauses): string {
                global $wpdb;
                if ($q !== $query) {
                    return $where;
                }
                foreach ($clauses as $type => $ids) {
                    $where .= $wpdb->prepare(" AND ({$wpdb->posts}.post_type <> %s", $type)
                        . ' OR ' . ($ids === [] ? '0 = 1' : "{$wpdb->posts}.ID IN (" . implode(', ', $ids) . ')') . ')';
                }

                return $where;
            }, 10, 2);
        }
    }

    /** @return list<string> the slugs from the root down to $term */
    private function termChain(\WP_Term $term): array
    {
        $slugs = [$term->slug];
        foreach (get_ancestors($term->term_id, $term->taxonomy, 'taxonomy') as $ancestor) {
            $parent = get_term($ancestor, $term->taxonomy);
            if ($parent instanceof \WP_Term) {
                array_unshift($slugs, $parent->slug);
            }
        }

        return $slugs;
    }

    /** `term_link`: a term of a SpacePress taxonomy with a `url`, at the URL its pattern gives. */
    public function termLink(string $link, mixed $term, string $taxonomy): string
    {
        foreach ($this->terms as $name => $kind) {
            if ($kind['taxonomy'] === $taxonomy && $term instanceof \WP_Term) {
                return user_trailingslashit(home_url($this->termPath($name, $term)));
            }
        }
        return $link;
    }

    public function termPath(string $name, \WP_Term $term): ?string
    {
        if (!isset($this->terms[$name])) { return null; }
        $parts = [];
        foreach ($this->terms[$name]['segments'] as $segment) {
            $parts[] = match ($segment) {
                ':slug' => rawurlencode($term->slug),
                ':id' => (string) $term->term_id,
                ':path' => implode('/', array_map('rawurlencode', $this->termChain($term))),
                default => $segment,
            };
        }
        return '/' . implode('/', $parts);
    }

    /** `post_type_link`: the item's URL from its kind's pattern. */
    public function link(string $link, \WP_Post $post): string
    {
        foreach ($this->kinds as $name => $kind) {
            if ($kind['type'] !== $post->post_type) {
                continue;
            }
            $url = $this->build($name, $post);
            return $url ?? $link;
        }
        return $link;
    }

    /** The path a pattern gives an item, or null while a placeholder it needs is empty (a draft's slug). */
    public function path(string $name, \WP_Post $post): ?string
    {
        $parts = [];
        $date = strtotime($post->post_date_gmt !== '0000-00-00 00:00:00' ? $post->post_date_gmt . ' UTC' : $post->post_date);
        foreach ($this->kinds[$name]['segments'] as $segment) {
            if (!str_starts_with($segment, ':')) {
                $parts[] = $segment;
                continue;
            }
            $field = substr($segment, 1);
            $value = match ($field) {
                'slug', 'path' => $post->post_name,
                'id' => (string) $post->ID,
                'year' => gmdate('Y', (int) $date),
                'month' => gmdate('m', (int) $date),
                'day' => gmdate('d', (int) $date),
                default => self::field($name, $post, $field),
            };
            if ($value === null || $value === '') {
                return null;
            }
            $parts[] = $field === 'path' ? implode('/', array_map('rawurlencode', explode('/', get_page_uri($post) ?: $value))) : rawurlencode($value);
        }
        // At the site root, an item whose first segment WordPress or SpacePress owns can't be reached:
        // it has no URL, so no link, tree or sitemap points at it.
        if (self::root($this->kinds[$name])) {
            $first = strtolower(rawurldecode(explode('/', $parts[0] ?? '', 2)[0]));
            if (in_array($first, $this->kinds[$name]['reserved'], true) || str_starts_with($first, 'wp-')) {
                return null;
            }
        }
        return '/' . implode('/', $parts);
    }

    /**
     * Loads what path() reads for each of $ids in one pass, before a loop that asks for many: the
     * posts, and their meta when the pattern holds a stored field (`:brand`). A hierarchical kind's
     * parents are posts of the same list in nearly every tree; one that is not is read on its own,
     * as before. A term's slug (`:topics.slug`) is read per item either way: core does not cache it.
     *
     * @param list<int> $ids
     */
    public function prime(string $name, array $ids): void
    {
        $columns = Plugin::instance()?->schema->collection($name)['columns'] ?? [];
        $meta = false;
        foreach ($this->kinds[$name]['segments'] ?? [] as $segment) {
            if (!str_starts_with($segment, ':') || in_array($segment, [':slug', ':path', ':id', ':year', ':month', ':day'], true)) {
                continue;
            }
            [$column, $through] = array_pad(explode('.', substr($segment, 1), 2), 2, null);
            $meta = $meta || !($through === 'slug' && PostMeta::terms($columns[$column] ?? []));
        }
        _prime_post_caches($ids, false, $meta);
    }

    private function build(string $name, \WP_Post $post): ?string
    {
        $path = $this->path($name, $post);
        return $path === null ? null : user_trailingslashit(home_url($path));
    }

    /** A scalar field's value for a placeholder, or a referenced item's slug (`:author.slug`). */
    /**
     * Whether $viewer may read every field the kind's `url` pattern puts in an address. A pattern
     * that shows a field the viewer may not read (`:slug` with a read rule on the slug) gives them no
     * address: it would carry the hidden value. The sitemap and core's search both ask.
     */
    public static function patternReadable(array $spec, \SpacePress\Viewer $viewer): bool
    {
        foreach (explode('/', trim((string) $spec['url'], '/')) as $segment) {
            if (!str_starts_with($segment, ':')) {
                continue;
            }
            [$field, $through] = array_pad(explode('.', substr($segment, 1), 2), 2, null);
            $column = match ($field) {
                'slug', 'path' => $spec['slug'] ?? null,
                'id', 'year', 'month', 'day' => null,
                default => $field,
            };
            if ($column !== null && isset($spec['columns'][$column]) && \SpacePress\FieldAccess::mayHide($spec['columns'][$column], $viewer)) {
                return false;
            }
            // `:author.slug`: the address also shows the referenced item's slug ({@see field()}).
            $target = $through === 'slug' && $column !== null ? ($spec['columns'][$column]['references']['collection'] ?? null) : null;
            if ($target !== null) {
                $schema = Plugin::instance()?->schema;
                $targetSpec = $schema !== null && $schema->hasCollection($target) ? $schema->collection($target) : null;
                $slug = $targetSpec['slug'] ?? null;
                if ($targetSpec === null || ($slug !== null && isset($targetSpec['columns'][$slug]) && \SpacePress\FieldAccess::mayHide($targetSpec['columns'][$slug], $viewer))) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function field(string $kind, \WP_Post $post, string $field): ?string
    {
        [$column, $through] = array_pad(explode('.', $field, 2), 2, null);
        $def = Plugin::instance()?->schema->collection($kind)['columns'][$column] ?? [];
        if ($through === 'slug' && PostMeta::terms($def)) {
            // `:topics.slug`: the first of the item's terms in write order; none, no URL yet.
            $taxonomy = (string) (Plugin::instance()?->schema->collection($def['references']['collection'])['wp']['taxonomy'] ?? '');
            $terms = wp_get_object_terms($post->ID, $taxonomy, ['orderby' => 'term_order', 'order' => 'ASC']);

            return is_array($terms) && ($terms[0] ?? null) instanceof \WP_Term ? $terms[0]->slug : null;
        }
        $value = get_post_meta($post->ID, 'sp_' . $column, true);
        if ($through === 'slug' && is_numeric($value)) {
            $target = get_post((int) $value);
            return $target instanceof \WP_Post && $target->post_status === 'publish' ? $target->post_name : null;
        }
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * After a slug change the old URL 301s to the current one (core keeps `_wp_old_slug`). And a
     * kind URL that resolved to an item whose pattern gives another URL (a wrong year, a stale
     * field) redirects to the canonical one, so every item has exactly one URL.
     */
    public function oldSlug(): void
    {
        $name = get_query_var('name');
        $type = get_query_var('post_type');
        $kind = null;
        foreach ($this->kinds as $n => $k) {
            if ($k['type'] === $type) {
                $kind = $n;
            }
        }
        // An item WordPress found by another rule (core's own `/%postname%/` for WordPress's posts):
        // its kind is its post type's, and the address below sends it to the kind's URL.
        $queried = is_singular() ? get_queried_object() : null;
        if ($kind === null && $queried instanceof \WP_Post) {
            foreach ($this->kinds as $n => $k) {
                if ($k['type'] === $queried->post_type) {
                    $kind = $n;
                }
            }
        }
        if ($kind === null) {
            return;
        }
        if (is_404() && is_string($name) && $name !== '') {
            // The published item with this slug now (the URL's other placeholders were stale, like
            // a wrong year), or the one that had it before a rename (core's `_wp_old_slug`).
            global $wpdb;
            $type = $this->kinds[$kind]['type'];
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name = %s LIMIT 1",
                $type, $name,
            ));
            $id = $id ?: (int) $wpdb->get_var($wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_old_slug'
                 WHERE p.post_type = %s AND p.post_status = 'publish' AND m.meta_value = %s ORDER BY p.ID DESC LIMIT 1",
                $type, $name,
            ));
            $post = $id > 0 ? get_post($id) : null;
            if ($post instanceof \WP_Post && ($url = $this->build($kind, $post)) !== null) {
                $this->move($kind, $post, $url);
            }
            return;
        }
        $post = get_queried_object();
        if (!is_singular() || !$post instanceof \WP_Post || $post->post_type !== $this->kinds[$kind]['type']) {
            return;
        }
        $want = $this->path($kind, $post);
        $have = '/' . trim((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($have, $home)) {
            $have = substr($have, strlen($home)) ?: '/';
        }
        if ($want !== null && rawurldecode($have) !== rawurldecode($want)) {
            $this->move($kind, $post, (string) $this->build($kind, $post));
        }
    }

    /**
     * Sends the visitor to an item's URL, only when that item is theirs to see: a redirect says it
     * exists. A sp/app/ page that serves the URL decides (AppRouter::moveTo): it renders the item for
     * the visitor, guards and notFound() included, and redirects only what renders. Otherwise the
     * theme renders it, and the redirect needs the kind's read rule and published parents.
     */
    private function move(string $kind, \WP_Post $post, string $url): void
    {
        if (AppRouter::instance()?->moveTo($url) === true) {
            return;
        }
        $viewer = CoreAccess::viewer();
        if ($viewer === null || !self::published($post) || !Db::instance()->permits($kind, 'read', $post->ID, $viewer)) {
            return;
        }
        wp_safe_redirect($url, 301);
        exit;
    }

    /** `frontend: false`: no public HTML at all. */
    public static function backendOnly(): void
    {
        if (is_admin() || is_feed() || is_robots() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo "This site has no public pages.\n";
        exit;
    }

    /** `frontend: "https://…"`: the external site renders; front-end URLs go there with their path. */
    private static function external(string $frontend): void
    {
        if (is_admin() || is_feed() || is_robots() || (defined('REST_REQUEST') && REST_REQUEST) || is_preview()) {
            return;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && str_starts_with($uri, $home)) {
            $uri = substr($uri, strlen($home)) ?: '/';
        }
        nocache_headers();
        wp_redirect(rtrim($frontend, '/') . '/' . ltrim($uri, '/'), 308);
        exit;
    }

    /** A link WordPress built for this site, moved to the external front end with the same path. */
    public static function rebase(string $url, string $frontend): string
    {
        $home = untrailingslashit(home_url());
        if (!str_starts_with($url, $home)) {
            return $url;
        }
        return rtrim($frontend, '/') . '/' . ltrim(substr($url, strlen($home)), '/');
    }
}
