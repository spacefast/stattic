<?php
/**
 * A Space's block templates: what the site editor opens, and what
 * `content-page.php` renders a document through.
 *
 * **They are supplied through a filter, never seeded as `wp_template` rows.**
 * One wp.cloud site hosts many Spaces behind one theme, and a `wp_template` post
 * is scoped by the `wp_theme` taxonomy — not by Space. Seeded rows would
 * therefore appear in every co-hosted Space's site editor. A filtered template
 * is per request and so per Space by construction, because the Space id is
 * already a request global.
 *
 * When a human EDITS one, WordPress writes its own `wp_template` post through
 * its normal customization path. That row is made private by the same two hooks
 * everything else uses: `spacefast_content_scope_post` stamps it with the Space
 * meta, and `spacefast_content_scope_post_query` adds the clause that hides it
 * from every other Space. `wp_global_styles` is scoped for the same reason and
 * is not optional — the site editor creates one lazily on first save, and an
 * unstamped one would hand every co-hosted Space a single shared appearance.
 *
 * A saved row also carries the `wp_theme` term of the active theme, because
 * WordPress's OWN template resolution is what renders a document on a real box:
 * the provider keeps core under `__wp__/` and its front controller answers a
 * post's URL, so `get_query_template()` → `locate_block_template()` →
 * `resolve_block_template()` → `get_block_templates(['slug__in' => ...])` is the
 * path that decides which markup a reader gets. That query is fenced by a
 * `wp_theme` tax_query, so a row without the term is invisible to core and a
 * Space's saved edit rendered nowhere. The term says which THEME a row belongs
 * to and never which Space — the Space fence stays the meta clause, on this
 * file's own reads and on core's query alike (`pre_get_posts` scopes both).
 *
 * System pages (`_pages/`) are deliberately NOT registered here. A compiled
 * `_pages/404.html` is served by the static lane and the platform 404 tail;
 * neither boots WordPress. Registering it would produce a screen where saving
 * changes nothing, which is worse than its absence.
 */
declare(strict_types=1);

/**
 * The post types the site editor writes. They are listed here rather than added
 * to the collection map because a template is not a collection: it has no
 * fields, no abilities and no REST projection, and only the scoping applies.
 */
const SPACEFAST_CONTENT_TEMPLATE_POST_TYPES = ['wp_template', 'wp_template_part', 'wp_global_styles'];

/**
 * The markup an unedited template opens with: the managed theme's own
 * `templates/index.html`. An unedited Space therefore renders exactly as it did
 * before it had templates at all, and the editor has something real to open.
 */
function spacefast_content_templates_default_markup(): string
{
    $file = function_exists('get_theme_file_path') ? get_theme_file_path('templates/index.html') : null;
    $markup = is_string($file) && is_file($file) ? file_get_contents($file) : false;
    // A box whose theme cannot be read still serves the document rather than a
    // blank page: post content is the one block that makes a template mean
    // anything at all.
    return is_string($markup) && trim($markup) !== '' ? $markup : '<!-- wp:post-content /-->';
}

/** The template slug a resource implies, or null when no request ever renders one. */
function spacefast_content_templates_slug_for_resource(array $resource): ?string
{
    return match ((string) ($resource['kind'] ?? '')) {
        'posts' => 'single',
        'pages' => 'page',
        'collection' => 'single-' . str_replace(['.', '_'], '-', (string) ($resource['id'] ?? '')),
        // An attachment is not a page, so media gets no template: a screen the
        // editor can open but no reader can reach is worse than its absence.
        default => null,
    };
}

function spacefast_content_templates_theme(): string
{
    $theme = function_exists('get_stylesheet') ? get_stylesheet() : null;
    return is_string($theme) && $theme !== '' ? $theme : 'spacefast';
}

/**
 * The `wp_theme` term core scopes a saved template row by, assigned on save.
 *
 * `get_block_templates()` queries `wp_template` rows behind a `wp_theme`
 * tax_query on `get_stylesheet()`. A row saved without that term is not a row
 * core can find, so every Space's site-editor edit lost to the release default
 * on the one lane that matters — WordPress's own front controller. This is the
 * theme association core's REST controller makes for exactly the same reason;
 * it is not a scope, and it is not what keeps one Space's row out of another's.
 */
function spacefast_content_templates_scope_theme(int $postId, string $postType): void
{
    if (
        $postId < 1
        || !in_array($postType, ['wp_template', 'wp_template_part'], true)
        || !function_exists('wp_set_object_terms')
    ) {
        return;
    }
    wp_set_object_terms($postId, spacefast_content_templates_theme(), 'wp_theme');
}

/**
 * Templates the active release implies, as WP_Block_Template-shaped objects.
 *
 * A release the kernel cannot project costs the Space its templates, never its
 * page — the same rule ability registration follows.
 *
 * @return list<object>
 */
function spacefast_content_templates_for_release(): array
{
    try {
        return spacefast_content_templates_project();
    } catch (Throwable $error) {
        error_log('spacefast content templates unavailable: ' . get_debug_type($error));
        return [];
    }
}

/** @return list<object> */
function spacefast_content_templates_project(): array
{
    $contentModel = spacefast_content_model_active_release();
    if ($contentModel === null) {
        return [];
    }
    $markup = spacefast_content_templates_default_markup();
    $theme = spacefast_content_templates_theme();
    $templates = [];
    foreach (is_array($contentModel['postTypes'] ?? null) ? $contentModel['postTypes'] : [] as $resource) {
        $slug = is_array($resource) ? spacefast_content_templates_slug_for_resource($resource) : null;
        if ($slug === null || isset($templates[$slug])) {
            continue;
        }
        $templates[$slug] = (object) [
            'id' => $theme . '//' . $slug,
            'theme' => $theme,
            'slug' => $slug,
            'type' => 'wp_template',
            'source' => 'theme',
            'origin' => 'theme',
            'content' => $markup,
            'title' => (string) ($resource['label'] ?? $slug),
            'description' => 'Supplied by the active Spacefast content release.',
            'status' => 'publish',
            'has_theme_file' => false,
            'is_custom' => false,
            'author' => null,
            'post_types' => [(string) ($resource['postType'] ?? '')],
        ];
    }
    return array_values($templates);
}

/**
 * The `get_block_templates` filter.
 *
 * Appending is deliberate: `pre_get_block_templates` short-circuits core's own
 * resolution, which would also hide the rows a human's edits produced. This adds
 * the release's templates to whatever core found and leaves core's answer intact.
 *
 * A release default is only ever a default. Where this Space has saved an edit
 * for the same slug, the saved markup is what the returned template carries — so
 * the answer core resolves a document through is the Space's own template, on
 * every path that asks, and not only on the one that asks by post. Core normally
 * finds that row itself; folding it here means the filter's answer does not
 * depend on the row's taxonomy being intact.
 *
 * `slug__in` is honoured because `resolve_block_template()` sorts the result by
 * each slug's position in the hierarchy it asked for: a template outside that
 * set has no position to sort by.
 */
function spacefast_content_templates_filter(mixed $templates, mixed $query = [], mixed $type = 'wp_template'): array
{
    $templates = is_array($templates) ? $templates : [];
    if ($type !== 'wp_template' || spacefast_content_space_id() === '') {
        return $templates;
    }
    $present = [];
    foreach ($templates as $template) {
        if (is_object($template) && is_string($template->slug ?? null)) {
            $present[$template->slug] = true;
        }
    }
    $wanted = is_array($query) && is_array($query['slug__in'] ?? null) ? $query['slug__in'] : null;
    $defaults = [];
    foreach (spacefast_content_templates_for_release() as $template) {
        if (isset($present[$template->slug]) || ($wanted !== null && !in_array($template->slug, $wanted, true))) {
            continue;
        }
        $defaults[$template->slug] = $template;
    }
    if ($defaults === []) {
        return $templates;
    }
    $saved = spacefast_content_templates_saved(array_keys($defaults));
    foreach ($defaults as $slug => $template) {
        $post = $saved[$slug] ?? null;
        if (is_object($post) && trim((string) ($post->post_content ?? '')) !== '') {
            $template->content = (string) $post->post_content;
            $template->source = 'custom';
            $template->origin = null;
            $template->is_custom = true;
            $template->wp_id = (int) ($post->ID ?? 0);
        }
        $templates[] = $template;
    }
    return $templates;
}

/**
 * A Space's own saved edits of these template slugs, keyed by slug.
 *
 * One query for the whole set, memoized per request because the serve path asks
 * once per document and the answer cannot change inside one request. That keeps
 * the templated page at one extra bounded lookup over the untemplated one, never
 * one per block and never one per slug in the hierarchy.
 *
 * The Space fence is stated on the query rather than left to the `pre_get_posts`
 * filter. A template read that answers with a co-hosted Space's row is a leak,
 * not a wrong screen, so this query carries its own scope whatever a caller does
 * with filters. The clause is the same one the filter would add, and
 * `spacefast_content_scope_meta_query()` recognizes it rather than stacking it.
 *
 * @param list<string> $slugs
 * @return array<string,object>
 */
function spacefast_content_templates_saved(array $slugs): array
{
    static $cache = [];
    $missing = [];
    foreach ($slugs as $slug) {
        if (is_string($slug) && $slug !== '' && !array_key_exists($slug, $cache)) {
            $missing[$slug] = true;
        }
    }
    if ($missing !== [] && function_exists('get_posts')) {
        foreach (array_keys($missing) as $slug) {
            $cache[$slug] = null;
        }
        $posts = get_posts([
            'post_type' => 'wp_template',
            'post_status' => 'publish',
            'post_name__in' => array_keys($missing),
            'numberposts' => count($missing),
            'meta_query' => [spacefast_content_space_meta_clause()],
        ]);
        foreach (is_array($posts) ? $posts : [] as $post) {
            $slug = is_object($post) ? (string) ($post->post_name ?? '') : '';
            if (isset($missing[$slug])) {
                $cache[$slug] = $post;
            }
        }
    }
    $saved = [];
    foreach ($slugs as $slug) {
        $post = is_string($slug) ? ($cache[$slug] ?? null) : null;
        if (is_object($post)) {
            $saved[$slug] = $post;
        }
    }
    return $saved;
}

/**
 * The declared collection this post belongs to, or null for one that carries no
 * collection term.
 *
 * A collection's items live on the `post` post type behind a term, so the post
 * type says only "post" and the term is the only thing that names the
 * collection. One bounded term read, on a path that already does one bounded
 * template read.
 *
 * @return array<string,mixed>|null
 */
function spacefast_content_templates_collection_resource(int $postId): ?array
{
    if ($postId < 1 || !function_exists('wp_get_object_terms')) {
        return null;
    }
    $contentModel = spacefast_content_model_active_release();
    $spaceId = spacefast_content_require_space_id();
    $byTerm = [];
    foreach (is_array($contentModel['postTypes'] ?? null) ? $contentModel['postTypes'] : [] as $resource) {
        if (is_array($resource) && ($resource['kind'] ?? '') === 'collection' && is_string($resource['id'] ?? null)) {
            $byTerm[spacefast_content_model_collection_term_slug($spaceId, $resource['id'])] = $resource;
        }
    }
    if ($byTerm === []) {
        return null;
    }
    $slugs = wp_get_object_terms($postId, SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, ['fields' => 'slugs']);
    foreach (is_array($slugs) ? $slugs : [] as $slug) {
        if (isset($byTerm[(string) $slug])) {
            return $byTerm[(string) $slug];
        }
    }
    return null;
}

/**
 * The resource this document's template comes from: its collection when it
 * carries a collection term, and otherwise the resource its post type maps to.
 *
 * The order is the whole point. `spacefast_content_collection_for_post_type()`
 * maps only `post`, `page` and `attachment`, so asking it first resolves every
 * declared collection's item to the `posts` resource and therefore to the
 * `single` template — which is how a `single-projects` template a human edited
 * in the site editor reached no reader at all.
 *
 * Memoized per request for the same reason `spacefast_content_templates_saved`
 * is: the serve path asks once per document and the answer cannot change inside
 * one request.
 *
 * @return array<string,mixed>|null
 */
function spacefast_content_templates_resource_for_post(object $post): ?array
{
    static $cache = [];
    $postId = (int) ($post->ID ?? 0);
    $key = $postId > 0 ? (string) $postId : 'type:' . (string) ($post->post_type ?? '');
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $resource = spacefast_content_templates_collection_resource($postId);
    if ($resource === null) {
        $collection = spacefast_content_collection_for_post_type((string) ($post->post_type ?? ''));
        $resource = is_array($collection)
            ? spacefast_content_model_resource((string) $collection['name'])
            : null;
    }
    return $cache[$key] = $resource;
}

/**
 * The Space-owned template this document renders through, or null.
 *
 * A human's edit wins over the release's default, because that edited row IS the
 * Space's template. Never throws: a template this Space cannot resolve costs it
 * the template, and `content-page.php` still serves the document.
 */
function spacefast_content_template_for_post(object $post): ?object
{
    try {
        $resource = spacefast_content_templates_resource_for_post($post);
        $slug = is_array($resource) ? spacefast_content_templates_slug_for_resource($resource) : null;
        if ($slug === null) {
            return null;
        }
        $saved = spacefast_content_templates_saved([$slug])[$slug] ?? null;
        if (is_object($saved) && trim((string) ($saved->post_content ?? '')) !== '') {
            return (object) [
                'slug' => $slug,
                'type' => 'wp_template',
                'content' => (string) $saved->post_content,
            ];
        }
        foreach (spacefast_content_templates_for_release() as $template) {
            if ($template->slug === $slug) {
                return $template;
            }
        }
        return null;
    } catch (Throwable $error) {
        error_log('spacefast content template unresolved: ' . get_debug_type($error));
        return null;
    }
}

/**
 * Whether a site-editor resource id belongs to this Space.
 *
 * The editor addresses a template either as `theme//slug` — one this release
 * implies, and therefore this Space's by construction — or as the numeric id of
 * a row somebody saved, which is this Space's only if the row carries its meta.
 */
function spacefast_content_templates_resource_allowed(string $resourceId): bool
{
    if ($resourceId === '') {
        return true;
    }
    if (preg_match('/^[1-9][0-9]*$/', $resourceId) === 1) {
        return spacefast_content_post_belongs_to_space((int) $resourceId);
    }
    $separator = strpos($resourceId, '//');
    $slug = $separator === false ? $resourceId : substr($resourceId, $separator + 2);
    foreach (spacefast_content_templates_for_release() as $template) {
        if ($template->slug === $slug) {
            return true;
        }
    }
    return false;
}
