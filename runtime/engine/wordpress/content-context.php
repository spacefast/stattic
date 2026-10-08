<?php
declare(strict_types=1);

/** One Space, one source-backed document, one existing Space access policy. */
const SPACEFAST_CONTEXT_SOURCE = 'content/context.blocks';

function spacefast_context_binding(): array
{
    $model = spacefast_content_model_active_release();
    foreach ($model['syncBindings'] ?? [] as $candidate) {
        if (($candidate['source'] ?? null) !== SPACEFAST_CONTEXT_SOURCE) continue;
        $binding = spacefast_content_model_sync_binding($candidate['id']);
        if (is_array($binding) && $binding['post_type'] === 'page' && $binding['field_storage'] === 'post_content') {
            return $binding + ['id' => $candidate['id']];
        }
    }
    throw new Spacefast_Content_Error(404, 'content_context_not_found', 'This Space has no context document.');
}

function spacefast_context_post(): object
{
    $binding = spacefast_context_binding();
    global $wpdb;
    // The active binding, not a client-supplied id, selects the document.
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
         JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s AND b.meta_value = %s
         WHERE p.post_type = 'page' AND p.post_status = 'publish' LIMIT 2",
        SPACEFAST_CONTENT_SPACE_META, spacefast_content_require_space_id(),
        SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $binding['id']
    ));
    if (count($ids) !== 1) throw new Spacefast_Content_Error(404, 'content_context_not_found', 'The context document is unavailable.');
    $post = get_post((int) $ids[0]);
    if (!$post || spacefast_content_source_is_retired((int) $post->ID)) throw new Spacefast_Content_Error(404, 'content_context_not_found', 'The context document is unavailable.');
    return $post;
}

function spacefast_context_revision(object $post): string
{
    return spacefast_content_sync_digest_text(json_encode([
        $post->ID, $post->post_title, $post->post_content, $post->post_modified_gmt,
        spacefast_content_sync_current_revision_id((int) $post->ID),
    ], JSON_THROW_ON_ERROR));
}

function spacefast_context_read(): array
{
    $post = spacefast_context_post();
    return ['id' => (int) $post->ID, 'title' => $post->post_title, 'blocks' => $post->post_content,
        'markdown' => spacefast_content_markdown_representable($post->post_content) ? spacefast_content_markdown_from_blocks($post->post_content) : null,
        'html' => wp_kses_post(do_blocks($post->post_content)),
        'revision' => spacefast_context_revision($post), 'canEdit' => current_user_can('edit_post', $post->ID),
        'url' => rtrim(spacefast_content_public_origin(), '/') . '/', 'source' => SPACEFAST_CONTEXT_SOURCE];
}

function spacefast_context_require_edit(): object
{
    $post = spacefast_context_post();
    if (!current_user_can('edit_post', $post->ID)) throw new Spacefast_Content_Error(403, 'content_context_edit_denied', 'The Space access settings do not permit editing.');
    return $post;
}

function spacefast_context_save(array $input): array
{
    spacefast_context_require_edit();
    if (empty($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT']) || !function_exists('_stattic_space_write_lock_with')) throw new Spacefast_Content_Error(503, 'content_context_lock_unavailable', 'Context saves are unavailable. Retry later.');
    return spacefast_content_sync_locked(static function () use ($input): array {
        $post = spacefast_context_require_edit();
        clean_post_cache($post->ID);
        $post = spacefast_context_require_edit();
        if (array_diff(array_keys($input), ['baseRevision', 'title', 'blocks', 'markdown']) !== []) throw new Spacefast_Content_Error(400, 'content_context_invalid', 'Supply only title, blocks or markdown, and baseRevision.');
        $title = $input['title'] ?? $post->post_title;
        if (!is_string($title) || strlen($title) > 1000 || trim(sanitize_text_field($title)) === '') throw new Spacefast_Content_Error(400, 'content_context_invalid', 'Supply a title of at most 1000 bytes.');
        if (isset($input['blocks']) && isset($input['markdown'])) throw new Spacefast_Content_Error(400, 'content_context_invalid', 'Supply either blocks or markdown.');
        $content = $input['blocks'] ?? $input['markdown'] ?? $post->post_content;
        if (!is_string($content) || strlen($content) > 1000000) throw new Spacefast_Content_Error(400, 'content_context_invalid', 'Context content must be text of at most 1000000 bytes.');
        $blocks = wp_kses_post(isset($input['markdown']) ? spacefast_content_markdown_to_blocks($content) : $content);
        $title = sanitize_text_field($title);
        $changed = $title !== $post->post_title || $blocks !== $post->post_content;
        $revision = $input['baseRevision'] ?? null;
        if (!is_string($revision)) throw new Spacefast_Content_Error(400, 'content_context_invalid', 'Supply the baseRevision you read.');
        // Saving exactly the stored content loses nothing, whatever revision the
        // caller read: answer saved without a new history entry.
        if (!$changed) return spacefast_context_read();
        if (!hash_equals(spacefast_context_revision($post), $revision)) throw new Spacefast_Content_Error(409, 'content_context_conflict', 'The context changed. Read it again before saving.');
        $before = spacefast_content_sync_current_revision_id((int) $post->ID);
        $result = wp_update_post(wp_slash(['ID' => $post->ID, 'post_title' => $title, 'post_content' => $blocks]), true);
        if (is_wp_error($result)) throw new Spacefast_Content_Error(500, 'content_context_save_failed', 'The context could not be saved.');
        // WordPress revisions the update itself; adding another duplicated every
        // history entry. Only create one when the site's revisioning did not.
        $revisionId = spacefast_content_sync_current_revision_id((int) $post->ID);
        if ($revisionId === $before || $revisionId === (int) $post->ID) $revisionId = spacefast_content_sync_save_revision((int) $post->ID);
        spacefast_context_record_actor($revisionId);
        return spacefast_context_read();
    });
}

/**
 * History is WordPress's own revisions endpoint; this adds who saved each one,
 * which WordPress records only as a user id.
 */
function spacefast_context_revision_actor(array $revision): array
{
    $actor = get_metadata('post', (int) $revision['id'], '_spacefast_context_actor', true);
    if (is_array($actor)) return $actor;
    // Read the revision post itself: a `_fields` request leaves `author` out of $revision.
    $author = (int) (get_post((int) $revision['id'])->post_author ?? 0);
    return ['name' => get_the_author_meta('display_name', $author) ?: 'Space collaborator', 'kind' => 'user'];
}

/** One write path: the context API's revision fence. Native page writes cannot skip it. */
function spacefast_context_guard_rest(mixed $prepared, mixed $request): mixed
{
    $id = (int) ($request['id'] ?? 0);
    if ($id < 1) return $prepared;
    try { $context = spacefast_context_post(); } catch (Spacefast_Content_Error) { return $prepared; }
    return $id === (int) $context->ID ? new WP_Error('content_context_revision_required', 'Save this context through /spacefast/v1/context with baseRevision.', ['status' => 409]) : $prepared;
}

function spacefast_context_record_actor(int $revisionId): void
{
    $user = wp_get_current_user();
    $identity = spacefast_content_principal_user_authority((int) $user->ID);
    update_metadata('post', $revisionId, '_spacefast_context_actor', ['name' => $user->display_name ?: 'Space collaborator', 'kind' => ($identity['kind'] ?? 'user') === 'service' ? 'service' : 'user']);
}

/** The editor asset path under wp-includes, or null. */
function spacefast_context_asset_path(string $src, string $base): ?string
{
    $path = parse_url(str_starts_with($src, 'http') ? $src : $base . $src, PHP_URL_PATH);
    return is_string($path) && str_starts_with($path, '/wp-includes/') ? $path : null;
}

/** Gutenberg assets come from the runtime that stores this document. */
function spacefast_context_editor_assets(): array
{
    $post = spacefast_context_require_edit();
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    $scripts = wp_scripts();
    $context = new WP_Block_Editor_Context(['name' => 'spacefast-context', 'post' => $post]);
    wp_add_inline_script('wp-block-library', 'wp.blocks.setCategories(' . wp_json_encode(get_block_categories($context)) . ');wp.blocks.unstable__bootstrapServerSideBlockDefinitions(' . wp_json_encode(get_block_editor_server_block_settings()) . ');', 'before');
    $scripts->all_deps(['wp-block-editor', 'wp-block-library', 'wp-components', 'wp-format-library']);
    $result = []; $moduleIds = [];
    foreach ($scripts->to_do as $handle) {
        $asset = $scripts->registered[$handle] ?? null;
        if (!$asset || !$asset->src) continue;
        $path = spacefast_context_asset_path($asset->src, $scripts->base_url);
        if ($path === null) continue;
        $before = $scripts->get_data($handle, 'before'); $data = $scripts->get_data($handle, 'data'); $after = $scripts->get_data($handle, 'after');
        // No administrative nonce or user-meta persistence in the app editor.
        if ($handle === 'wp-api-fetch') $after = ['wp.apiFetch.use(wp.apiFetch.createRootURLMiddleware(location.origin + "/wp-json/"));'];
        if ($handle === 'wp-preferences') $after = ['wp.data.dispatch(wp.preferences.store).setPersistenceLayer({get:async()=>{try{return JSON.parse(localStorage.getItem("context:gutenberg:preferences")||"{}")}catch{return {}}},set:(data)=>{try{localStorage.setItem("context:gutenberg:preferences",JSON.stringify(data))}catch{}}});'];
        foreach (($scripts->get_data($handle, 'module_dependencies') ?: []) as $dependency) $moduleIds[] = is_string($dependency) ? $dependency : $dependency['id'];
        $result[] = ['handle' => $handle, 'src' => $path . '?ver=' . rawurlencode((string) $asset->ver),
            'before' => implode("\n", [...(is_array($before) ? $before : []), is_string($data) ? $data : '']), 'after' => implode("\n", is_array($after) ? $after : [])];
    }
    $styles = wp_styles(); $styles->all_deps(['wp-components', 'wp-block-editor', 'wp-block-library', 'wp-block-library-theme']); $urls = [];
    foreach ($styles->to_do as $handle) {
        $asset = $styles->registered[$handle] ?? null;
        if (!$asset || !$asset->src) continue;
        $path = spacefast_context_asset_path($asset->src, $styles->base_url);
        if ($path !== null) $urls[] = $path . '?ver=' . rawurlencode((string) $asset->ver);
    }
    $imports = []; $modules = wp_script_modules();
    while ($moduleIds !== []) {
        $moduleId = array_pop($moduleIds);
        if (isset($imports[$moduleId])) continue;
        $module = $modules->get_registered($moduleId);
        if ($module === null) throw new Spacefast_Content_Error(503, 'content_context_editor_unavailable', 'A Gutenberg module is unavailable.');
        $path = spacefast_context_asset_path($module['src'], '');
        if ($path === null) continue;
        $version = $module['version'] === false ? get_bloginfo('version') : $module['version'];
        $imports[$moduleId] = $path . ($version === null ? '' : '?ver=' . rawurlencode((string) $version));
        foreach ($module['dependencies'] as $dependency) $moduleIds[] = $dependency['id'];
    }
    return ['scripts' => $result, 'styles' => $urls, 'imports' => (object) $imports,
        'editorUser' => ['id' => get_current_user_id(), 'name' => wp_get_current_user()->display_name, 'capabilities' => (object) []]];
}

function spacefast_context_register_routes(): void
{
    $scope = static fn (): bool => spacefast_content_space_id() !== '';
    spacefast_content_register_rest_route('spacefast/v1', '/context', [
        ['methods' => 'GET', 'permission_callback' => $scope, 'callback' => static fn (): array => spacefast_context_read()],
        ['methods' => 'PATCH', 'permission_callback' => $scope, 'callback' => static fn ($request): array => spacefast_context_save($request->get_json_params() ?? [])],
    ]);
    register_rest_field('page-revision', 'spacefast_actor', ['get_callback' => 'spacefast_context_revision_actor', 'schema' => ['type' => 'object', 'context' => ['view', 'edit'], 'readonly' => true]]);
    spacefast_content_register_rest_route('spacefast/v1', '/context/editor', ['methods' => 'GET', 'permission_callback' => $scope, 'callback' => static fn (): array => spacefast_context_editor_assets()]);
}
