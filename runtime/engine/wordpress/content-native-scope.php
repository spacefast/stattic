<?php
/** Native WordPress routes share the installation, never its content identities. */
declare(strict_types=1);

function spacefast_content_write_collection(int $postId = 0): ?string
{
    $resource = $GLOBALS['SPACEFAST_CONTENT_WRITE_COLLECTION'] ?? null;
    if (is_string($resource)) {
        return $resource;
    }
    $terms = $_POST['tax_input'][SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY] ?? null;
    if (is_array($terms)) {
        foreach ($terms as $termId) {
            if ((int) $termId > 0 && get_term_meta((int) $termId, SPACEFAST_CONTENT_SPACE_META, true) === spacefast_content_space_id()) {
                return (string) get_term_meta((int) $termId, '_zero_resource_id', true);
            }
        }
    }
    return $postId > 0 ? (spacefast_content_collection_for_post($postId)['name'] ?? null) : null;
}

function spacefast_content_rest_write_collection(mixed $response, mixed $handler, mixed $request): mixed
{
    $GLOBALS['SPACEFAST_CONTENT_WRITE_COLLECTION'] = null;
    if (!in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) {
        return $response;
    }
    $terms = array_values(array_unique((array) ($request[SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY] ?? [])));
    if (count($terms) > 1) {
        return new WP_Error('content_collection_ambiguous', 'A document belongs to one collection.', ['status' => 400]);
    }
    foreach ($terms as $termId) {
        if (get_term_meta((int) $termId, SPACEFAST_CONTENT_SPACE_META, true) !== spacefast_content_space_id()) {
            return new WP_Error('content_collection_not_found', 'Collection not found.', ['status' => 404]);
        }
        $GLOBALS['SPACEFAST_CONTENT_WRITE_COLLECTION'] = (string) get_term_meta((int) $termId, '_zero_resource_id', true);
    }
    return $response;
}

function spacefast_content_unique_slug(mixed $override, string $slug, ?int $postId, string $status, string $postType): mixed
{
    if (spacefast_content_space_id() === '' || $slug === '' || !in_array($postType, ['post', 'page', 'attachment'], true)) {
        return $override;
    }
    $postId ??= 0;
    global $wpdb;
    $resourceId = spacefast_content_write_collection($postId);
    $resource = $resourceId === null ? null : spacefast_content_model_collection_projection($resourceId);
    $term = $resource['collection_term'] ?? null;
    $membership = "SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id=tt.term_id WHERE tr.object_id=p.ID AND tt.taxonomy=%s";
    $membership = $wpdb->prepare($membership, SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY);
    $collection = is_string($term)
        ? 'EXISTS (' . $membership . $wpdb->prepare(' AND t.slug=%s)', $term)
        : 'NOT EXISTS (' . $membership . ')';
    $suffix = 1;
    $candidate = $slug;
    do {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s AND pm.meta_value=%s WHERE p.post_name=%s AND p.post_type=%s AND p.ID<>%d AND {$collection} LIMIT 1",
            SPACEFAST_CONTENT_SPACE_META, spacefast_content_space_id(), $candidate, $postType, $postId
        ));
        if ($exists === null) {
            return $candidate;
        }
        $candidate = _truncate_post_slug($slug, 190) . '-' . ++$suffix;
    } while (true);
}

function spacefast_content_unique_draft_slug(array $data, array $post): array
{
    if (in_array($data['post_status'], ['draft', 'pending'], true)) {
        if ($data['post_name'] === '') {
            $data['post_name'] = sanitize_title($data['post_title']) ?: 'untitled';
        }
        $data['post_name'] = spacefast_content_unique_slug($data['post_name'], $data['post_name'], (int) ($post['ID'] ?? 0), $data['post_status'], $data['post_type']);
    }
    return $data;
}

function spacefast_content_remember_editor_slug(int $postId, mixed $post, mixed $before): void
{
    if (spacefast_content_post_belongs_to_space($postId) && $before->post_name !== '' && $before->post_name !== $post->post_name) {
        if (!in_array($before->post_name, get_post_meta($postId, '_wp_old_slug'), true)) {
            add_post_meta($postId, '_wp_old_slug', $before->post_name);
        }
    }
}

function spacefast_content_scope_term_query(mixed $query): void
{
    $taxonomies = (array) ($query->query_vars['taxonomy'] ?? []);
    if ($taxonomies !== [] && array_diff($taxonomies, ['wp_theme', 'wp_template_part_area', 'wp_pattern_category']) === []) {
        return;
    }
    if (spacefast_content_space_id() !== '') {
        $query->query_vars['meta_query'] = spacefast_content_scope_meta_query($query->query_vars['meta_query'] ?? []);
    }
}

function spacefast_content_scope_new_term(int $termId): void
{
    if (spacefast_content_space_id() !== '') {
        update_term_meta($termId, SPACEFAST_CONTENT_SPACE_META, spacefast_content_space_id());
        spacefast_content_public_routes_schedule();
    }
}

/** Core caches comment queries before applying the SQL scope and visibility clauses. */
function spacefast_content_scope_comment_cache(mixed $query): void
{
    if (spacefast_content_space_id() === '') return;
    $release = spacefast_content_model_active_release();
    $query->query_vars['cache_domain'] = 'spacefast:' . hash('sha256', json_encode([
        spacefast_content_space_id(), $release['revision'] ?? null, spacefast_content_may_read_private_resources(),
    ]));
}

function spacefast_content_scope_comment_clauses(array $clauses): array
{
    if (spacefast_content_space_id() === '') {
        return $clauses;
    }
    global $wpdb;
    $clauses['where'] .= $wpdb->prepare(" AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} sf_comment_scope WHERE sf_comment_scope.post_id={$wpdb->comments}.comment_post_ID AND sf_comment_scope.meta_key=%s AND sf_comment_scope.meta_value=%s)", SPACEFAST_CONTENT_SPACE_META, spacefast_content_space_id());
    if (!spacefast_content_may_read_private_resources()) {
        $clauses['where'] .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} sf_retired WHERE sf_retired.post_id={$wpdb->comments}.comment_post_ID AND sf_retired.meta_key=%s)", SPACEFAST_CONTENT_SOURCE_RETIRED_META);
        $selected = spacefast_content_selected_source_ids();
        $notSelected = $selected === [] ? '' : ' AND sf_source.meta_value NOT IN (' . implode(',', array_map(static fn (string $id): string => $wpdb->prepare('%s', $id), $selected)) . ')';
        $clauses['where'] .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} sf_source WHERE sf_source.post_id={$wpdb->comments}.comment_post_ID AND sf_source.meta_key=%s AND sf_source.meta_value LIKE %s", SPACEFAST_CONTENT_EXTERNAL_ID_META, $wpdb->esc_like(SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX) . '%') . $notSelected . ')';
    }
    $private = spacefast_content_private_collection_terms();
    if ($private !== null) {
        $privateWhere = $private === '' ? '' : ' AND sf_term.slug IN (' . implode(',', array_map(static fn (string $slug): string => $wpdb->prepare('%s', $slug), $private)) . ')';
        $clauses['where'] .= $wpdb->prepare(" AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} sf_relationship INNER JOIN {$wpdb->term_taxonomy} sf_taxonomy ON sf_taxonomy.term_taxonomy_id=sf_relationship.term_taxonomy_id INNER JOIN {$wpdb->terms} sf_term ON sf_term.term_id=sf_taxonomy.term_id WHERE sf_relationship.object_id={$wpdb->comments}.comment_post_ID AND sf_taxonomy.taxonomy=%s", SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY) . $privateWhere . ')';
    }
    return $clauses;
}

function spacefast_content_native_meta_cap(array $caps, string $cap, int $userId, array $args): array
{
    if (spacefast_content_space_id() === '') {
        return $caps;
    }
    if ($cap === 'customize' || ($cap === 'edit_theme_options' && spacefast_content_global_customization_request())) {
        return ['do_not_allow'];
    }
    if (!isset($args[0])) {
        return $caps;
    }
    if (in_array($cap, ['edit_term', 'delete_term', 'assign_term'], true)
        && get_term_meta((int) $args[0], SPACEFAST_CONTENT_SPACE_META, true) !== spacefast_content_space_id()) {
        return ['do_not_allow'];
    }
    if ($cap === 'edit_comment') {
        $comment = get_comment((int) $args[0]);
        if (!$comment || !spacefast_content_post_belongs_to_space((int) $comment->comment_post_ID)) {
            return ['do_not_allow'];
        }
    }
    return $caps;
}

function spacefast_content_native_rest_read(mixed $response, mixed $object): mixed
{
    $postId = isset($object->comment_post_ID) ? (int) $object->comment_post_ID : null;
    $allowed = $postId !== null
        ? spacefast_content_post_belongs_to_space($postId) && !spacefast_content_post_is_private($postId)
        : get_term_meta((int) $object->term_id, SPACEFAST_CONTENT_SPACE_META, true) === spacefast_content_space_id();
    return $allowed ? $response : new WP_Error('content_not_found', 'Content not found.', ['status' => 404]);
}

function spacefast_content_native_rest_hooks(): void
{
    foreach (get_taxonomies(['show_in_rest' => true]) as $taxonomy) {
        add_filter('rest_prepare_' . $taxonomy, 'spacefast_content_native_rest_read', PHP_INT_MAX, 2);
    }
}

function spacefast_content_assign_collection(int $postId, string $resourceId): void
{
    $collection = spacefast_content_model_collection_projection($resourceId);
    if (!is_string($collection['collection_term'] ?? null)) {
        return;
    }
    $term = get_term_by('slug', $collection['collection_term'], SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY);
    if (!$term) {
        throw new Spacefast_Content_Error(409, 'content_collection_not_found', 'The collection term is missing.');
    }
    $result = wp_set_object_terms($postId, [(int) $term->term_id], SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY);
    if (is_wp_error($result)) {
        throw new Spacefast_Content_Error(500, 'content_collection_write_failed', 'The document collection could not be saved.');
    }
}

function spacefast_content_global_customization_request(): bool
{
    $screen = $GLOBALS['pagenow'] ?? basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
    $action = (string) ($_REQUEST['action'] ?? '');
    return in_array($screen, ['customize.php', 'widgets.php', 'nav-menus.php'], true)
        || in_array($action, ['save-widget', 'widgets-order', 'delete-selected-widgets', 'menu-locations-save', 'menu-quick-search'], true)
        || str_starts_with($action, 'customize_');
}

function spacefast_content_guard_global_theme_rest(mixed $response, mixed $server, mixed $request): mixed
{
    if ($response === null && spacefast_content_space_id() !== ''
        && preg_match('#^/wp/v2/(widgets|sidebars|menu-locations)(?:/|$)#', $request->get_route())) {
        return new WP_Error('content_shared_theme_options', 'These theme settings belong to the shared WordPress installation. Edit this Space through Content, Templates, or Styles.', ['status' => 403]);
    }
    return $response;
}

function spacefast_content_guard_global_theme_admin(): void
{
    if (spacefast_content_space_id() !== '' && spacefast_content_global_customization_request()) {
        wp_die('These theme settings belong to the shared WordPress installation. Edit this Space through Content, Templates, or Styles.', 'Space theme settings', ['response' => 403]);
    }
}

/** Validate references before WordPress persists any part of a REST update. */
function spacefast_content_rest_validate_references(mixed $response, mixed $handler, mixed $request): mixed
{
    if ($response !== null || spacefast_content_space_id() === ''
        || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)
        || !preg_match('#^/wp/v2/(posts|pages|media)(?:/[0-9]+)?$#', $request->get_route())) {
        return $response;
    }
    $references = [];
    if (isset($request['featured_media'])) {
        $references['featured_media'] = [['type' => 'media'], $request['featured_media']];
    }
    $meta = $request['meta'] ?? [];
    foreach (spacefast_content_model_resources() as $resource) {
        foreach ($resource['fields'] as $field) {
            if (!empty($field['native']) || !in_array($field['definition']['type'], ['media', 'relation'], true)) {
                continue;
            }
            $key = $field['storageName'];
            if (array_key_exists($key, $meta) && $meta[$key] !== null) {
                $references['meta.' . $key] = [$field['definition'], $meta[$key]];
            }
        }
    }
    foreach ($references as $key => [$definition, $value]) {
        if (!spacefast_content_model_validate_reference_value($definition, $value)) {
            return new WP_Error('content_reference_invalid', "Choose content from this Space and the field's declared collection.", ['status' => 400, 'param' => $key]);
        }
    }
    return $response;
}

/** Source removal retires the shared WordPress identity before it may be destroyed. */
function spacefast_content_source_delete_blocked(int $postId): bool
{
    if (!spacefast_content_post_belongs_to_space($postId) || spacefast_content_source_is_retired($postId)) return false;
    $external = get_post_meta($postId, SPACEFAST_CONTENT_EXTERNAL_ID_META, true);
    return (is_string($external) && str_starts_with($external, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX))
        || spacefast_content_model_materialization(spacefast_content_collection_for_post($postId)['name'] ?? '') !== null;
}

/**
 * The one destruction gate, for posts and for attachments alike.
 *
 * `wp_delete_attachment()` deletes the row itself and never reaches
 * `pre_delete_post`, so media needs its own `pre_delete_attachment`
 * registration. Both filters pass (null, post, force) and both treat a non-null
 * return as "this deletion did not happen", so one function answers both.
 */
function spacefast_content_guard_source_delete(mixed $delete, object $post): mixed
{
    return spacefast_content_source_delete_blocked((int) $post->ID) ? false : $delete;
}

function spacefast_content_rest_guard_source_delete(mixed $response, mixed $handler, mixed $request): mixed
{
    if ($response === null && $request->get_method() === 'DELETE' && $request['force'] === true
        && preg_match('#\A/wp/v2/(?:posts|pages|media)/([1-9][0-9]*)/?\z#', $request->get_route(), $match)
        && spacefast_content_source_delete_blocked((int) $match[1])) {
        return new WP_Error('content_source_delete_required', 'Remove this document from source and publish before deleting it permanently. You can move it to Trash now.', ['status' => 409]);
    }
    return $response;
}

function spacefast_content_admin_guard_source_delete(): void
{
    $postId = isset($_REQUEST['post']) ? (int) $_REQUEST['post'] : 0;
    if ($postId > 0 && spacefast_content_source_delete_blocked($postId)) {
        wp_die('Remove this document from source and publish before deleting it permanently. You can move it to Trash now.', 'Document is owned by source', ['response' => 409]);
    }
}
