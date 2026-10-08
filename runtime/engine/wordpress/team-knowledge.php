<?php
/** Spacefast Team/Space authority and raw context over WordPress Knowledge. */
declare(strict_types=1);

const SPACEFAST_KNOWLEDGE_META = [
    '_sf_team_id' => 'string', '_sf_target_space' => 'string', '_sf_id' => 'string',
    '_sf_kind' => 'string', '_sf_category' => 'string', '_sf_pinned' => 'boolean',
    '_sf_archived_at' => 'string', '_sf_skill_key' => 'string', '_sf_enabled' => 'boolean',
    '_sf_created_at' => 'string', '_sf_updated_at' => 'string',
    '_sf_created_by' => 'object', '_sf_updated_by' => 'object',
];

if (function_exists('add_action') && class_exists('WP_REST_Posts_Controller')) {
    add_action('init', 'spacefast_knowledge_register', 100);
    add_filter('rest_wp_knowledge_collection_params', 'spacefast_knowledge_collection_params');
    add_filter('rest_prepare_wp_knowledge', 'spacefast_knowledge_response', 10, 3);
    add_filter('user_has_cap', 'spacefast_knowledge_capabilities', 50, 4);
    add_filter('map_meta_cap', 'spacefast_knowledge_meta_cap', 50, 4);
    add_filter('rest_wp_knowledge_query', 'spacefast_knowledge_query', 10, 2);
    add_filter('rest_pre_insert_wp_knowledge', 'spacefast_knowledge_prepare', 10, 2);
    add_filter('rest_request_before_callbacks', 'spacefast_knowledge_rest_guard', 5, 3);
    add_filter('posts_where', 'spacefast_knowledge_cursor_where', 10, 2);
}

function spacefast_knowledge_register(): void
{
    // Metadata is a Spacefast concern; leave the native controller intact.
    add_post_type_support('wp_knowledge', 'custom-fields');
    foreach (SPACEFAST_KNOWLEDGE_META as $key => $type) {
        $schema = ['type' => $type];
        if ($type === 'object') {
            $schema['properties'] = [
                'actorType' => ['type' => 'string'], 'actorId' => ['type' => 'string'],
                'userId' => ['type' => ['string', 'null']],
            ];
            $schema['required'] = ['actorType', 'actorId', 'userId'];
            $schema['additionalProperties'] = false;
        }
        register_post_meta('wp_knowledge', $key, [
            'single' => true, 'type' => $type, 'show_in_rest' => ['schema' => $schema],
            'revisions_enabled' => true,
            'auth_callback' => static fn ($allowed, $metaKey, $id) => current_user_can('edit_post', $id),
        ]);
    }
}

/** Only a dedicated management-JWT call can establish this authority. */
function spacefast_knowledge_authority(): ?array
{
    $value = $GLOBALS['SPACEFAST_KNOWLEDGE_AUTHORITY'] ?? null;
    if (!is_array($value) || !is_string($value['teamId'] ?? null) || $value['teamId'] === ''
        || !is_bool($value['admin'] ?? null) || !is_bool($value['write'] ?? null)) return null;
    foreach (['readSpaceIds', 'writeSpaceIds', 'liveSpaceIds'] as $key) {
        if (!is_array($value[$key] ?? null)) return null;
        foreach ($value[$key] as $id) if (!is_string($id) || $id === '') return null;
    }
    return $value;
}

function spacefast_knowledge_capabilities(array $caps, array $required, array $args, mixed $user): array
{
    if ((int) ($user->ID ?? 0) !== get_current_user_id()) return $caps;
    $authority = spacefast_knowledge_authority();
    foreach (['read_knowledge_items', 'read_private_knowledge_items', 'edit_knowledge_items',
        'edit_others_knowledge_items', 'edit_private_knowledge_items', 'edit_published_knowledge_items',
        'publish_knowledge_items', 'delete_knowledge_items', 'delete_others_knowledge_items',
        'delete_private_knowledge_items', 'delete_published_knowledge_items', 'manage_knowledge_items'] as $cap) {
        $caps[$cap] = $authority !== null && (
            str_starts_with($cap, 'read_') || ($authority['write'] && (
                !str_starts_with($cap, 'delete_') && $cap !== 'manage_knowledge_items' || $authority['admin']
            ))
        );
    }
    return $caps;
}

function spacefast_knowledge_post_allowed(int $id, bool $write = false): bool
{
    $authority = spacefast_knowledge_authority();
    $post = get_post($id);
    if ($post && $post->post_type === 'revision') $post = get_post($post->post_parent);
    if ($authority === null || !$post || $post->post_type !== 'wp_knowledge'
        || get_post_meta($post->ID, '_spacefast_space_id', true) !== spacefast_content_space_id()
        || get_post_meta($post->ID, '_sf_team_id', true) !== $authority['teamId']) return false;
    $target = get_post_meta($post->ID, '_sf_target_space', true);
    if ($write && (!$authority['write'] || (get_post_meta($post->ID, '_sf_kind', true) === 'skill' && !$authority['admin']))) return false;
    return $target === '' || in_array($target, $authority[$write ? 'writeSpaceIds' : 'readSpaceIds'], true);
}

function spacefast_knowledge_meta_cap(array $caps, string $cap, int $userId, array $args): array
{
    $post = get_post((int) ($args[0] ?? 0));
    if ($post && $post->post_type === 'revision') $post = get_post($post->post_parent);
    if (!$post || $post->post_type !== 'wp_knowledge' || !in_array($cap, [
        'read_post', 'edit_post', 'delete_post', 'read_knowledge_item', 'edit_knowledge_item', 'delete_knowledge_item',
    ], true)) return $caps;
    $write = !str_starts_with($cap, 'read_');
    $delete = str_starts_with($cap, 'delete_');
    if ($userId !== get_current_user_id() || !spacefast_knowledge_post_allowed($post->ID, $write)
        || ($write && get_post_meta($post->ID, '_sf_kind', true) === 'design-system')
        || ($delete && !(spacefast_knowledge_authority()['admin'] ?? false))) return ['do_not_allow'];
    return [$write ? 'edit_knowledge_items' : 'read_knowledge_items'];
}

function spacefast_knowledge_collection_params(array $params): array
{
    foreach (['kind', 'memory_id', 'skill_key', 'target_space', 'after_archive', 'after_id'] as $key) $params[$key] = ['type' => 'string'];
    $params['archived'] = ['type' => 'boolean'];
    return $params;
}

/** Authorized Team readers need exact prose without gaining edit permission. */
function spacefast_knowledge_response(mixed $response, mixed $post, mixed $request): mixed
{
    if (($request['context'] ?? 'view') !== 'view' || !spacefast_knowledge_post_allowed($post->ID)) return $response;
    $data = $response->get_data();
    foreach (['title' => 'post_title', 'content' => 'post_content'] as $field => $property) {
        if (isset($data[$field])) $data[$field]['raw'] = $post->$property;
    }
    $response->set_data($data);
    return $response;
}

/** Ordinary content roles cannot open this private native namespace. */
function spacefast_knowledge_rest_guard(mixed $response, mixed $handler, mixed $request): mixed
{
    if (!preg_match('#\A/wp/v2/(?:knowledge|wp_knowledge_type)(?:/|$)#', $request->get_route())) return $response;
    if (spacefast_knowledge_authority() === null || get_current_user_id() === 0) {
        return new WP_Error('rest_forbidden', 'Team context access is required.', ['status' => 403]);
    }
    if (preg_match('#\A/wp/v2/knowledge/([1-9][0-9]*)(?:/|$)#', $request->get_route(), $match)
        && !spacefast_knowledge_post_allowed((int) $match[1])) {
        return new WP_Error('rest_post_invalid_id', 'Knowledge not found.', ['status' => 404]);
    }
    return $response;
}

function spacefast_knowledge_query(array $query, mixed $request): array
{
    $authority = spacefast_knowledge_authority();
    $query['post_status'] = 'private';
    $targets = ['key' => '_sf_target_space', 'value' => ''];
    if (($authority['readSpaceIds'] ?? []) !== []) {
        $targets = ['relation' => 'OR', $targets,
            ['key' => '_sf_target_space', 'value' => $authority['readSpaceIds'], 'compare' => 'IN']];
    }
    // WordPress drops an empty IN value; never let that widen this query.
    $query['meta_query'] = [
        ['key' => '_sf_team_id', 'value' => $authority['teamId'] ?? ''], $targets,
    ];
    foreach (['kind' => '_sf_kind', 'memory_id' => '_sf_id', 'skill_key' => '_sf_skill_key'] as $param => $key) {
        if (isset($request[$param])) $query['meta_query'][] = ['key' => $key, 'value' => $request[$param]];
    }
    if (isset($request['target_space'])) $query['meta_query'][] = ['relation' => 'OR',
        ['key' => '_sf_target_space', 'value' => ''], ['key' => '_sf_target_space', 'value' => $request['target_space']],
    ];
    if (isset($request['archived'])) {
        $query['meta_query'][] = ['key' => '_sf_archived_at', 'value' => '', 'compare' => $request['archived'] ? '!=' : '='];
        if ($request['archived']) {
            $query['meta_key'] = '_sf_archived_at';
            $query['orderby'] = ['meta_value' => 'DESC', 'ID' => 'DESC'];
            $query['sf_after_archive'] = $request['after_archive'];
            $query['sf_after_id'] = $request['after_id'];
        }
    }
    return $query;
}

function spacefast_knowledge_cursor_where(string $where, mixed $query): string
{
    $date = $query->get('sf_after_archive');
    $id = $query->get('sf_after_id');
    if (!is_string($date) || $date === '' || !is_string($id) || !ctype_digit($id) || (int) $id < 1) return $where;
    global $wpdb;
    $value = "(SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id={$wpdb->posts}.ID AND meta_key='_sf_archived_at' LIMIT 1)";
    return $where . $wpdb->prepare(" AND ($value < %s OR ($value = %s AND {$wpdb->posts}.ID < %d))", $date, $date, (int) $id);
}

/** Recheck invariants under the dispatch's per-team MySQL lock. */
function spacefast_knowledge_prepare(mixed $post, mixed $request): mixed
{
    if (is_wp_error($post)) return $post;
    $authority = spacefast_knowledge_authority();
    if ($authority === null || !$authority['write']) return new WP_Error('rest_forbidden', 'Team context write access is required.', ['status' => 403]);
    $id = (int) ($request['id'] ?? 0);
    $old = $id > 0 ? array_map(static fn ($values) => maybe_unserialize($values[0]), get_post_meta($id)) : [];
    $meta = array_merge($old, (array) ($request['meta'] ?? []));
    $kind = $meta['_sf_kind'] ?? null;
    if ($id > 0) {
        foreach (['_sf_id', '_sf_team_id', '_sf_target_space', '_sf_kind', '_sf_skill_key', '_sf_created_by', '_sf_created_at'] as $key) {
            if (($meta[$key] ?? null) !== ($old[$key] ?? null)) return new WP_Error('rest_invalid_param', 'Knowledge identity and scope are immutable.', ['status' => 400]);
        }
    }
    if (($meta['_sf_team_id'] ?? null) !== $authority['teamId'] || !in_array($kind, ['memory', 'skill'], true)
        || !is_string($meta['_sf_target_space'] ?? null)) return new WP_Error('rest_invalid_param', 'Invalid Knowledge scope.', ['status' => 400]);
    $target = $meta['_sf_target_space'];
    if (($target !== '' && !in_array($target, $authority['writeSpaceIds'], true)) || ($kind === 'skill' && !$authority['admin'])) return new WP_Error('rest_forbidden', 'Knowledge scope is not writable.', ['status' => 403]);
    $post->post_status = 'private';
    // Seed identity before REST checks per-field capabilities on the new post.
    if ($id === 0) $post->meta_input = $meta;
    // Type is purpose; category stays independent metadata.
    $term = term_exists($kind, 'wp_knowledge_type');
    if (!$term) $term = wp_insert_term(ucfirst($kind), 'wp_knowledge_type', ['slug' => $kind]);
    if (is_wp_error($term)) return $term;
    $request->set_param('wp_knowledge_type', [(int) $term['term_id']]);
    if ($kind === 'skill') {
        $twins = get_posts(['post_type' => 'wp_knowledge', 'post_status' => 'private', 'numberposts' => 1,
            'meta_query' => [['key' => '_sf_team_id', 'value' => $authority['teamId']], ['key' => '_sf_skill_key', 'value' => $meta['_sf_skill_key']], ['key' => '_sf_kind', 'value' => 'skill']]]);
        if ($twins && $twins[0]->ID !== $id) return new WP_Error('team_knowledge_exists', 'Skill setting already exists.', ['status' => 409, 'knowledgeId' => $twins[0]->ID]);
        return $post;
    }
    if (($meta['_sf_archived_at'] ?? '') !== '') return $post;
    $active = get_posts(['post_type' => 'wp_knowledge', 'post_status' => 'private', 'numberposts' => -1,
        'meta_query' => [['key' => '_sf_team_id', 'value' => $authority['teamId']], ['key' => '_sf_kind', 'value' => 'memory'], ['key' => '_sf_archived_at', 'value' => ''],
            ['relation' => 'OR', ['key' => '_sf_target_space', 'value' => ''], ['key' => '_sf_target_space', 'value' => $authority['liveSpaceIds'], 'compare' => 'IN']]]]);
    foreach ($active as $twin) {
        if ($twin->ID !== $id && get_post_meta($twin->ID, '_sf_target_space', true) === $target && mb_strtolower($twin->post_title) === mb_strtolower($post->post_title ?? get_post($id)->post_title)) {
            return new WP_Error('team_memory_exists', 'An active memory with this title already exists.', ['status' => 409, 'memoryId' => get_post_meta($twin->ID, '_sf_id', true)]);
        }
    }
    if (($id === 0 || ($old['_sf_archived_at'] ?? '') !== '') && count($active) >= 200) return new WP_Error('team_memory_limit_reached', 'Archive outdated memories before saving another.', ['status' => 409]);
    return $post;
}

function spacefast_knowledge_dispatch(array $request): array
{
    $authority = spacefast_knowledge_authority();
    if ($authority === null || !preg_match('#\A/wp/v2/knowledge(?:/[1-9][0-9]*(?:/revisions(?:/[1-9][0-9]*)?)?)?(?:\?|$)#', (string) ($request['path'] ?? ''))) {
        throw new Spacefast_Content_Error(403, 'knowledge_authority_invalid', 'A team Knowledge request is required.');
    }
    global $wpdb;
    $lock = 'sf-knowledge-' . substr(hash('sha256', spacefast_content_space_id() . ':' . $authority['teamId']), 0, 48);
    $write = ($request['method'] ?? '') !== 'GET';
    if ($write && (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) throw new Spacefast_Content_Error(503, 'knowledge_busy', 'Team context is busy. Retry the write.');
    try {
        // Headless context stores raw prose/Markdown, never public HTML.
        if ($write) kses_remove_filters();
        return spacefast_content_rest_dispatch($request);
    } finally {
        if ($write) { kses_init_filters(); $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
}
