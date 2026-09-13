<?php
declare(strict_types=1);

function spacefast_content_admin_option(string $name): string
{
    return 'spacefast_' . spacefast_content_require_space_id() . '_' . $name;
}

function spacefast_content_preferred_dashboard(): string
{
    return get_option(spacefast_content_admin_option('preferred_dashboard'), 'zero') === 'classic' ? 'classic' : 'zero';
}

function spacefast_content_admin_permission(): bool
{
    return spacefast_content_space_id() !== '' && current_user_can('edit_posts');
}

function spacefast_content_admin_manage_permission(): bool
{
    return spacefast_content_space_id() !== '' && current_user_can('spacefast_manage_content');
}

function spacefast_content_admin_register_rest_routes(): void
{
    spacefast_content_register_rest_route('spacefast/v1', '/admin/sync', [
        'methods' => 'GET', 'permission_callback' => 'spacefast_content_admin_permission',
        'callback' => 'spacefast_content_source_journal_status',
    ]);
    spacefast_content_register_rest_route('spacefast/v1', '/admin/sync/retry', [
        'methods' => 'POST', 'permission_callback' => 'spacefast_content_admin_permission',
        'args' => ['operationId' => ['type' => 'string', 'required' => true]],
        'callback' => static fn ($request) => spacefast_content_source_journal_retry($request['operationId']),
    ]);
    spacefast_content_register_rest_route('spacefast/v1', '/admin/bootstrap', [
        'methods' => 'GET',
        'permission_callback' => 'spacefast_content_admin_permission',
        'callback' => 'spacefast_content_admin_bootstrap',
    ]);
    spacefast_content_register_rest_route('spacefast/v1', '/admin/settings', [
        [
            'methods' => 'GET',
            'permission_callback' => 'spacefast_content_admin_permission',
            'callback' => static fn () => ['preferredDashboard' => spacefast_content_preferred_dashboard()],
        ],
        [
            'methods' => 'POST',
            'permission_callback' => 'spacefast_content_admin_manage_permission',
            'args' => ['preferredDashboard' => ['required' => true, 'enum' => ['zero', 'classic']]],
            'callback' => static function ($request) {
                update_option(spacefast_content_admin_option('preferred_dashboard'), $request['preferredDashboard'], false);
                return ['preferredDashboard' => spacefast_content_preferred_dashboard()];
            },
        ],
    ]);
    spacefast_content_register_rest_route('spacefast/v1', '/admin/resolve', [
        'methods' => 'GET',
        'permission_callback' => 'spacefast_content_admin_permission',
        'args' => ['collection' => ['required' => true, 'type' => 'string'], 'slug' => ['required' => true, 'type' => 'string']],
        'callback' => 'spacefast_content_admin_resolve',
    ]);
    spacefast_content_register_rest_route('spacefast/v1', '/redirects', [
        [
            'methods' => 'GET',
            'permission_callback' => 'spacefast_content_admin_permission',
            'callback' => static fn () => ['items' => array_values(spacefast_content_redirect_records())],
        ],
        [
            'methods' => 'POST',
            'permission_callback' => 'spacefast_content_admin_manage_permission',
            'callback' => 'spacefast_content_redirect_save',
        ],
    ]);
    spacefast_content_register_rest_route('spacefast/v1', '/redirects/(?P<id>[a-f0-9]{64})', [
        'methods' => 'DELETE',
        'permission_callback' => 'spacefast_content_admin_manage_permission',
        'callback' => static function ($request) {
            $records = spacefast_content_redirect_records();
            $record = $records[$request['id']] ?? null;
            if ($record !== null) {
                $item = Red_Item::get_by_id($record['pluginId']);
                if ($item !== false && $item->get_group_id() === spacefast_content_redirect_group()) {
                    $item->delete();
                }
            }
            return spacefast_content_redirect_publish(spacefast_content_redirect_records());
        },
    ]);
}

function spacefast_content_admin_collections(): array
{
    $collections = [];
    foreach (spacefast_content_model_resources() as $resource) {
        $term = ($resource['kind'] ?? '') === 'collection'
            ? get_term_by('slug', spacefast_content_model_collection_term_slug(spacefast_content_require_space_id(), $resource['id']), SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY)
            : null;
        $collections[] = [
            'slug' => $resource['id'],
            'label' => $resource['label'],
            'postType' => $resource['postType'],
            'taxonomy' => $term ? SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY : null,
            'termId' => $term ? (int) $term->term_id : null,
            'publicRead' => $resource['publicRead'],
            'fields' => $resource['fields'],
        ];
    }
    return $collections;
}

function spacefast_content_admin_bootstrap(): array|WP_Error
{
    if (!function_exists('next_admin_get_admin_routes')) {
        return new WP_Error('content_admin_unavailable', 'The Content dashboard is not installed.', ['status' => 503]);
    }
    if (!did_action('next_admin_init')) {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        if (!class_exists('WP_Admin_Bar')) {
            require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
        }
        global $wp_admin_bar;
        if (!($wp_admin_bar instanceof WP_Admin_Bar)) {
            $wp_admin_bar = new WP_Admin_Bar();
            $wp_admin_bar->initialize();
            do_action_ref_array('admin_bar_menu', [&$wp_admin_bar]);
        }
        do_action('next_admin_init');
    }
    $dashboard = function_exists('_stattic_dashboard_origin') ? _stattic_dashboard_origin() : null;
    $spacefast = is_string($dashboard) ? rtrim($dashboard, '/') . '/spaces/' . rawurlencode(spacefast_content_require_space_id()) : null;
    return [
        'routes' => next_admin_get_admin_routes(),
        'menuItems' => next_admin_get_admin_menu_items(),
        'metaMenuItems' => next_admin_get_admin_meta_menu_items(),
        'dashboardWidgetTypes' => next_admin_get_dashboard_widgets(),
        'initModules' => next_admin_get_init_modules(),
        'dashboardContext' => next_admin_get_dashboard_context(),
        'dashboardDefaultLayout' => apply_filters('next_admin_dashboard_widgets', []),
        'collections' => spacefast_content_admin_collections(),
        'settings' => ['preferredDashboard' => spacefast_content_preferred_dashboard()],
        'links' => ['admin' => site_url('/zero-admin'), 'classicAdmin' => admin_url('edit.php'), 'restRoot' => rest_url(), 'spacefast' => $spacefast === null ? null : $spacefast . '?screen=content', 'spacefastSource' => $spacefast === null ? null : $spacefast . '?screen=source'],
        'capabilities' => ['editPosts' => current_user_can('edit_posts'), 'manageOptions' => current_user_can('spacefast_manage_content'), 'uploadFiles' => current_user_can('upload_files')],
    ];
}

function spacefast_content_admin_resolve($request): array|WP_Error
{
    $collection = spacefast_content_model_collection_projection($request['collection']);
    if ($collection === null) {
        return new WP_Error('content_collection_not_found', 'Collection not found.', ['status' => 404]);
    }
    $args = [
        'post_type' => $collection['post_type'], 'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash', 'inherit'], 'posts_per_page' => 1,
        'post_name__in' => [$request['slug']],
        'meta_query' => [['key' => SPACEFAST_CONTENT_SPACE_META, 'value' => spacefast_content_require_space_id()]],
        'suppress_filters' => false,
    ];
    if ($collection['collection_term'] !== null) {
        $args['tax_query'] = [['taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, 'field' => 'slug', 'terms' => [$collection['collection_term']]]];
    } else {
        $args['tax_query'] = [['taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, 'operator' => 'NOT EXISTS']];
    }
    $posts = get_posts($args);
    $redirected = false;
    if ($posts === []) {
        unset($args['post_name__in']);
        $args['meta_query'][] = ['key' => '_wp_old_slug', 'value' => $request['slug']];
        $posts = get_posts($args);
        $redirected = true;
    }
    $post = $posts[0] ?? null;
    if ($post === null || !current_user_can('edit_post', $post->ID)) {
        return new WP_Error('content_document_not_found', 'Document not found.', ['status' => 404]);
    }
    return [
        'id' => (int) $post->ID, 'slug' => $post->post_name, 'collection' => $request['collection'],
        'path' => '/collections/' . rawurlencode($request['collection']) . '/' . rawurlencode($post->post_name),
        'redirected' => $redirected,
        'ownership' => spacefast_content_admin_document_ownership((int) $post->ID),
    ];
}

function spacefast_content_rest_dispatch(array $request, bool $managed): array
{
    $method = $request['method'] ?? null;
    $path = $request['path'] ?? null;
    if (!$managed || spacefast_content_principal_role() === null) {
        throw new Spacefast_Content_Error(403, 'content_rest_forbidden', 'A content principal is required.');
    }
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
        || !is_string($path) || strlen($path) > 8192 || !str_starts_with($path, '/')
        || str_starts_with($path, '//') || preg_match('/[\x00-\x20\\\\#]/', $path)) {
        throw new Spacefast_Content_Error(400, 'content_rest_request_invalid', 'Use a relative WordPress REST path and a supported method.');
    }
    [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
    parse_str($query, $params);
    $restRequest = new WP_REST_Request($method, $route);
    $restRequest->set_query_params($params);
    if (isset($request['body'])) {
        if (!is_array($request['body'])) {
            throw new Spacefast_Content_Error(400, 'content_rest_request_invalid', 'The REST body must be a JSON object.');
        }
        $restRequest->set_header('Content-Type', 'application/json');
        $restRequest->set_body(wp_json_encode($request['body']));
    }
    if (isset($request['upload'])) {
        $upload = $request['upload'];
        $bytes = is_array($upload) && is_string($upload['base64'] ?? null)
            ? base64_decode($upload['base64'], true) : false;
        $filename = is_array($upload) && is_string($upload['filename'] ?? null)
            ? sanitize_file_name($upload['filename']) : '';
        $contentType = is_array($upload) ? ($upload['contentType'] ?? null) : null;
        if ($method !== 'POST' || $route !== '/wp/v2/media' || !is_string($bytes)
            || strlen($bytes) > 16777216 || $filename === '' || !is_string($contentType)
            || !preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $contentType)) {
            throw new Spacefast_Content_Error(400, 'content_rest_upload_invalid', 'Upload one file of at most 16 MiB to the media endpoint.');
        }
        $restRequest->set_header('Content-Type', $contentType);
        $restRequest->set_header('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $filename) . '"');
        $restRequest->set_body($bytes);
        $restRequest->set_body_params($request['body'] ?? []);
    }
    spacefast_content_principal_establish_user();
    $response = rest_do_request($restRequest);
    $headers = [];
    foreach ($response->get_headers() as $name => $value) {
        $headers[$name] = is_array($value) ? implode(', ', $value) : (string) $value;
    }
    return ['status' => $response->get_status(), 'headers' => (object) $headers, 'body' => $response->get_data()];
}

function spacefast_content_redirect_group(bool $create = false): int
{
    if (!class_exists('Red_Group') || !class_exists('Red_Item')) {
        throw new Spacefast_Content_Error(503, 'content_redirect_unavailable', 'The Redirection plugin is unavailable.');
    }
    $option = spacefast_content_admin_option('redirection_group');
    $id = (int) get_option($option, 0);
    if ($id > 0 && Red_Group::get($id) !== false) {
        return $id;
    }
    if (!$create) {
        return 0;
    }
    $group = Red_Group::create('Spacefast ' . substr(hash('sha256', spacefast_content_require_space_id()), 0, 32), 1);
    if ($group === false) {
        throw new Spacefast_Content_Error(503, 'content_redirect_unavailable', 'A Space redirect group could not be created.');
    }
    update_option($option, $group->get_id(), false);
    return $group->get_id();
}

function spacefast_content_redirect_records(): array
{
    $group = spacefast_content_redirect_group();
    if ($group === 0) {
        return [];
    }
    $records = [];
    $publication = get_option(spacefast_content_admin_option('redirect_publication'), []);
    $page = 0;
    do {
        $result = Red_Item::get_filtered(['filterBy' => ['group' => $group], 'page' => $page++, 'per_page' => 200, 'orderby' => 'position', 'direction' => 'asc']);
        foreach ($result['items'] as $item) {
            $records[hash('sha256', $item['url'])] = [
                'id' => hash('sha256', $item['url']), 'pluginId' => $item['id'],
                'source' => $item['url'], 'destination' => $item['action_data']['url'] ?? '',
                'status' => $item['action_code'], 'enabled' => $item['enabled'],
                'requiresPublishedDestination' => !empty($publication[$item['id']]),
                'supported' => !$item['regex'] && $item['match_type'] === 'url' && $item['action_type'] === 'url',
            ];
        }
    } while ($page * 200 < $result['total']);
    return $records;
}

function spacefast_content_redirect_save($request): array|WP_Error
{
    $source = $request['source'];
    $destination = $request['destination'];
    $status = $request['status'] ?? 301;
    if (!is_string($source) || !is_string($destination)
        || !str_starts_with($source, '/') || str_starts_with($source, '//')
        || str_contains($source, '?') || str_contains($source, '#')
        || preg_match('/[\x00-\x20\\\\]/', $source . $destination)
        || (!str_starts_with($destination, '/') && !preg_match('#^https?://[^/]+(?:/|$)#', $destination))
        || str_starts_with($destination, '//') || $destination === $source
        || !in_array($status, [301, 302, 303, 307, 308], true)
        || str_starts_with($source, '/__spacefast') || str_starts_with($source, '/__zero')) {
        return new WP_Error('content_redirect_invalid', 'Provide a source path, destination URL and redirect status.', ['status' => 400]);
    }
    $records = spacefast_content_redirect_records();
    $id = hash('sha256', $source);
    $details = ['url' => $source, 'action_data' => ['url' => $destination], 'action_code' => $status,
        'action_type' => 'url', 'match_type' => 'url', 'regex' => false, 'group_id' => spacefast_content_redirect_group(true),
        'match_data' => ['source' => ['flag_case' => false, 'flag_trailing' => true, 'flag_regex' => false, 'flag_query' => 'ignore']]];
    $existing = isset($records[$id]) ? Red_Item::get_by_id($records[$id]['pluginId']) : false;
    $GLOBALS['SPACEFAST_CONTENT_REDIRECT_MUTATING'] = true;
    try {
        $saved = $existing === false ? Red_Item::create($details) : $existing->update($details);
    } finally {
        $GLOBALS['SPACEFAST_CONTENT_REDIRECT_MUTATING'] = false;
    }
    if (is_wp_error($saved)) {
        return $saved;
    }
    $publication = get_option(spacefast_content_admin_option('redirect_publication'), []);
    $pluginId = $existing === false ? $saved->get_id() : $existing->get_id();
    if (!empty($request['requiresPublishedDestination'])) {
        $publication[$pluginId] = true;
    } else {
        unset($publication[$pluginId]);
    }
    update_option(spacefast_content_admin_option('redirect_publication'), $publication, false);
    return spacefast_content_redirect_publish(spacefast_content_redirect_records());
}

function spacefast_content_redirect_publish(array $records): array|WP_Error
{
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '';
    if (!is_string($privateRoot) || $privateRoot === '') {
        return new WP_Error('content_redirect_unavailable', 'Redirect serving is unavailable.', ['status' => 503]);
    }
    $root = $privateRoot . '/spaces/' . spacefast_content_require_space_id();
    $rules = ['exact' => [], 'pattern' => []];
    foreach ($records as $record) {
        if (!$record['enabled'] || !$record['supported']) {
            continue;
        }
        $rules['exact'][$record['source']] = [['destination' => $record['destination'], 'status' => $record['status'], 'action' => 'redirect', 'order' => 0, 'requiresPublishedDestination' => $record['requiresPublishedDestination']]];
    }
    $json = json_encode($rules, JSON_THROW_ON_ERROR);
    $temporary = tempnam($root, '.redirects-');
    if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
        return new WP_Error('content_redirect_unavailable', 'Redirects could not be applied.', ['status' => 503]);
    }
    // Redirection owns records; the artifact is its scoped serving projection.
    if (!rename($temporary, $root . '/content-redirects.json')) {
        unlink($temporary);
        return new WP_Error('content_redirect_unavailable', 'Redirects were saved but could not be applied.', ['status' => 503]);
    }
    require_once __DIR__ . '/../shared/purge.php';
    $hosts = _stattic_runtime_space_sweep_hostnames($root);
    $purged = _stattic_runtime_purge_dispatch($privateRoot, $hosts, 'content_redirects');
    $conflicts = [];
    $host = parse_url(spacefast_content_public_origin(), PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        require_once __DIR__ . '/../shared/content-access.php';
        foreach ($records as $record) {
            if ($record['enabled'] && _stattic_content_deployment_claims_path($privateRoot, $host, $record['source'], 'GET')) {
                $conflicts[] = ['source' => $record['source'], 'owner' => 'deployment'];
            }
        }
    }
    return ['items' => array_values($records), 'state' => $purged ? 'applied' : 'pending', 'revision' => hash('sha256', $json), 'conflicts' => $conflicts];
}

function spacefast_content_public_routes_refresh_locked(): void
{
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '';
    if (!is_string($privateRoot) || $privateRoot === '' || spacefast_content_space_id() === '') {
        return;
    }
    $root = $privateRoot . '/spaces/' . spacefast_content_require_space_id();
    if (!is_dir($root)) {
        return;
    }
    $posts = get_posts([
        'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => -1,
        'meta_query' => [['key' => SPACEFAST_CONTENT_SPACE_META, 'value' => spacefast_content_require_space_id()]],
        'suppress_filters' => false,
    ]);
    $paths = [];
    foreach ($posts as $post) {
        if ($post->post_type === 'attachment' || spacefast_content_source_is_retired((int) $post->ID) || spacefast_content_source_is_unselected((int) $post->ID)) {
            continue;
        }
        $path = parse_url(get_permalink($post), PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $paths[rtrim($path, '/') ?: '/'] = ['postId' => (int) $post->ID, 'postType' => $post->post_type];
        }
    }
    if ($paths !== []) {
        $paths['/feed'] = ['feed' => true];
    }
    $rules = [];
    foreach (spacefast_content_model_resources() as $resource) {
        if (($resource['kind'] ?? '') !== 'collection' || !($resource['publicRead'] ?? true)) {
            continue;
        }
        // A published document already standing at this path owns it. The
        // archive a collection implies is derived, so it never displaces an
        // explicit route owner — that would make the document unreachable.
        if (isset($paths['/' . $resource['id']])) {
            continue;
        }
        $query = ['post_type' => $resource['postType'], 'tax_query' => [[
            'taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, 'field' => 'slug',
            'terms' => [spacefast_content_model_collection_term_slug(spacefast_content_require_space_id(), $resource['id'])],
        ]]];
        $paths['/' . $resource['id']] = ['query' => $query];
        $rules[] = ['pattern' => preg_quote($resource['id'], '~') . '/page/([0-9]+)/?$', 'query' => $query, 'pagedMatch' => 1];
    }
    $rewrite = $GLOBALS['wp_rewrite'] ?? null;
    foreach (is_object($rewrite) ? $rewrite->wp_rewrite_rules() : [] as $pattern => $target) {
        // Literal prefixes cover core archives, sitemaps and plugin registrations.
        // WordPress's generic post/page patterns must never capture an unknown app route.
        if (preg_match('/^\^?[a-zA-Z]/', $pattern) || str_starts_with($pattern, '([0-9]')) {
            $rules[] = ['pattern' => $pattern, 'target' => $target];
        }
    }
    $paths['__rewrites'] = $rules;
    $json = json_encode($paths, JSON_THROW_ON_ERROR);
    $temporary = tempnam($root, '.wordpress-routes-');
    if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
        || !rename($temporary, $root . '/wordpress-routes.json')) {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        throw new Spacefast_Content_Error(503, 'content_routes_unavailable', 'Content routes could not be applied.');
    }
}

function spacefast_content_slug_redirect(int $postId, mixed $post, mixed $before): void
{
    if (!spacefast_content_post_belongs_to_space($postId)
        || $before->post_status !== 'publish' || $post->post_status !== 'publish'
        || $before->post_name === '' || $before->post_name === $post->post_name
        || !in_array($post->post_type, ['post', 'page'], true)) {
        return;
    }
    $source = rtrim((string) wp_parse_url(get_permalink($before), PHP_URL_PATH), '/') ?: '/';
    $destination = rtrim((string) parse_url(get_permalink($post), PHP_URL_PATH), '/') ?: '/';
    if ($source === $destination) {
        return;
    }
    $result = spacefast_content_redirect_save(['source' => $source, 'destination' => $destination, 'status' => 301]);
    if (is_wp_error($result)) {
        throw new Spacefast_Content_Error(503, 'content_redirect_unavailable', $result->get_error_message());
    }
}

function spacefast_content_admin_document_ownership(int $postId): array
{
    $bindingId = spacefast_content_source_journal_binding_id($postId);
    $binding = $bindingId === null ? null : spacefast_content_model_sync_binding($bindingId);
    if ($binding === null) {
        return ['kind' => 'document'];
    }
    $release = spacefast_content_model_active_release();
    return [
        'kind' => $binding['format'] === 'tsx' ? 'component' : 'document',
        'bindingId' => $bindingId, 'sourcePath' => $binding['source'],
        'format' => $binding['format'], 'modelRevision' => $release['revision'],
        'componentSource' => $binding['componentSource'] ?? null,
    ];
}

function spacefast_content_collection_permalink(string $url, mixed $post, bool $leaveName = false): string
{
    if (!is_object($post) || spacefast_content_space_id() === '' || !spacefast_content_post_belongs_to_space((int) $post->ID)) {
        return $url;
    }
    $bindingId = spacefast_content_source_journal_binding_id((int) $post->ID);
    $binding = $bindingId === null ? null : spacefast_content_model_sync_binding($bindingId);
    if (is_string($binding['publicPath'] ?? null)) {
        $path = $binding['publicPath'];
        return home_url($leaveName ? spacefast_content_permalink_template($path, '%postname%') : $path);
    }
    $release = spacefast_content_model_active_release();
    foreach (spacefast_content_model_resources() as $resource) {
        if (($resource['kind'] ?? '') !== 'collection') {
            continue;
        }
        $term = spacefast_content_model_collection_term_slug(spacefast_content_require_space_id(), $resource['id']);
        if (has_term($term, SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, (int) $post->ID)) {
            return home_url('/' . rawurlencode($resource['id']) . '/' . ($leaveName ? '%postname%' : $post->post_name));
        }
    }
    return $url;
}

function spacefast_content_collection_for_post(int $postId): ?array
{
    if (!spacefast_content_post_belongs_to_space($postId)) {
        return null;
    }
    $release = spacefast_content_model_active_release();
    foreach (spacefast_content_model_resources() as $resource) {
        if (($resource['kind'] ?? '') === 'collection'
            && has_term(spacefast_content_model_collection_term_slug(spacefast_content_require_space_id(), $resource['id']), SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, $postId)) {
            return spacefast_content_model_collection_projection($resource['id']);
        }
    }
    $post = get_post($postId);
    return is_object($post) ? spacefast_content_collection_for_post_type($post->post_type) : null;
}

function spacefast_content_admin_media_read(array $request, bool $managed): array
{
    spacefast_content_principal_establish_user();
    $postId = $request['attachmentId'] ?? null;
    if (!$managed || !is_int($postId) || $postId < 1 || !spacefast_content_post_belongs_to_space($postId)
        || get_post_type($postId) !== 'attachment' || !current_user_can('read_post', $postId)) {
        throw new Spacefast_Content_Error(404, 'content_media_not_found', 'Media not found.');
    }
    $file = get_attached_file($postId);
    $size = $request['size'] ?? null;
    $metadata = wp_get_attachment_metadata($postId);
    if (is_string($size) && isset($metadata['sizes'][$size]['file'])) {
        $file = dirname($file) . '/' . basename($metadata['sizes'][$size]['file']);
    }
    $path = is_string($file) ? realpath($file) : false;
    $uploads = wp_upload_dir();
    $root = realpath($uploads['basedir']);
    if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
        || !is_file($path) || filesize($path) > 16777216) {
        throw new Spacefast_Content_Error(404, 'content_media_not_found', 'Media not found.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new Spacefast_Content_Error(503, 'content_media_unavailable', 'Media could not be read.');
    }
    return ['base64' => base64_encode($bytes), 'contentType' => mime_content_type($path) ?: 'application/octet-stream', 'filename' => basename($path)];
}

function spacefast_content_admin_menu_items(array $items): array
{
    $items = array_values(array_filter($items, static fn (array $item): bool => !str_starts_with($item['to'] ?? '', '/types/')));
    foreach (spacefast_content_admin_collections() as $collection) {
        $items[] = ['id' => 'collection-' . $collection['slug'], 'to' => '/collections/' . rawurlencode($collection['slug']), 'label' => $collection['label']];
    }
    $items[] = ['id' => 'redirects', 'to' => '/redirects', 'label' => 'Redirects'];
    $items[] = ['id' => 'content-settings', 'to' => '/content-settings', 'label' => 'Settings'];
    $items[] = ['id' => 'content-activity', 'to' => '/activity', 'label' => 'Activity'];
    return $items;
}

/** The installed Redirection UI and our app use the same Space-owned plugin group. */
function spacefast_content_redirection_rest(mixed $response, mixed $server, mixed $request): mixed
{
    $route = $request->get_route();
    if ($response !== null || !str_starts_with($route, '/redirection/v1/')) {
        return $response;
    }
    if (!spacefast_content_admin_manage_permission()) {
        return new WP_Error('rest_forbidden', 'Redirect access requires Space administration.', ['status' => 403]);
    }
    $groupId = spacefast_content_redirect_group(true);
    $method = $request->get_method();
    if ($method === 'GET' && in_array($route, ['/redirection/v1/group', '/redirection/v1/group/dropdown'], true)) {
        $group = Red_Group::get($groupId);
        return rest_ensure_response(['items' => [$route === '/redirection/v1/group/dropdown' ? $group->to_dropdown_json() : $group->to_json()], 'total' => 1]);
    }
    if ($method === 'GET' && $route === '/redirection/v1/redirect') {
        $params = $request->get_params();
        $params['filterBy'] = ['group' => $groupId];
        return rest_ensure_response(Red_Item::get_filtered($params));
    }
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && preg_match('#^/redirection/v1/redirect(?:/(\d+))?$#', $route, $match)) {
        $existing = isset($match[1]) ? Red_Item::get_by_id((int) $match[1]) : false;
        if (isset($match[1]) && ($existing === false || $existing->get_group_id() !== $groupId)) {
            return new WP_Error('rest_forbidden', 'This redirect belongs to another Space.', ['status' => 403]);
        }
        if (!empty($request['regex']) || ($request['match_type'] ?? 'url') !== 'url' || ($request['action_type'] ?? 'url') !== 'url') {
            return new WP_Error('content_redirect_unsupported', 'Space redirects currently support exact URL redirects.', ['status' => 400]);
        }
        $result = spacefast_content_redirect_save(['source' => $request['url'], 'destination' => $request['action_data']['url'] ?? '', 'status' => $request['action_code'] ?? 301]);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($existing !== false && $existing->get_url() !== $request['url']) {
            $existing->delete();
            spacefast_content_redirect_publish(spacefast_content_redirect_records());
        }
        return rest_ensure_response(Red_Item::get_filtered(['filterBy' => ['group' => $groupId]]));
    }
    if ($method === 'POST' && preg_match('#^/redirection/v1/bulk/redirect/(delete|enable|disable|reset)$#', $route, $match)) {
        $items = [];
        foreach (is_array($request['items']) ? $request['items'] : [] as $id) {
            $item = Red_Item::get_by_id((int) $id);
            if ($item === false || $item->get_group_id() !== $groupId) {
                return new WP_Error('rest_forbidden', 'This redirect belongs to another Space.', ['status' => 403]);
            }
            $items[] = $item;
        }
        foreach ($items as $item) {
            $item->{$match[1]}();
        }
        spacefast_content_redirect_publish(spacefast_content_redirect_records());
        return rest_ensure_response(Red_Item::get_filtered(['filterBy' => ['group' => $groupId]]));
    }
    return new WP_Error('rest_forbidden', 'This plugin endpoint manages the shared installation.', ['status' => 403]);
}

function spacefast_content_redirection_updated(int $id, mixed $item = null): void
{
    if (!empty($GLOBALS['SPACEFAST_CONTENT_REDIRECT_MUTATING'])) {
        return;
    }
    $item ??= Red_Item::get_by_id($id);
    if (spacefast_content_space_id() !== '' && $item !== false && $item->get_group_id() === spacefast_content_redirect_group()) {
        spacefast_content_redirect_publish(spacefast_content_redirect_records());
    }
}

function spacefast_content_register_rest_route(string $namespace, string $route, array $endpoints): void
{
    $endpoints = isset($endpoints['methods']) ? [$endpoints] : $endpoints;
    foreach ($endpoints as &$endpoint) {
        $callback = $endpoint['callback'];
        $endpoint['callback'] = static function ($request) use ($callback) {
            try {
                return $callback($request);
            } catch (Spacefast_Content_Error $error) {
                return new WP_Error($error->codeName, $error->getMessage(), ['status' => $error->status]);
            }
        };
    }
    unset($endpoint);
    register_rest_route($namespace, $route, $endpoints);
}

function spacefast_content_scope_user_query(mixed $query): void
{
    if (spacefast_content_space_id() !== '') {
        $query->set('meta_query', spacefast_content_scope_meta_query($query->get('meta_query')));
    }
}

function spacefast_content_public_routes_schedule(): void
{
    if (empty($GLOBALS['SPACEFAST_CONTENT_ROUTES_SCHEDULED'])) {
        $GLOBALS['SPACEFAST_CONTENT_ROUTES_SCHEDULED'] = true;
        add_action('shutdown', 'spacefast_content_public_routes_refresh');
    }
}

function spacefast_content_public_routes_refresh(): void
{
    $root = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? '';
    if (!is_string($root) || $root === '' || spacefast_content_space_id() === '') {
        return;
    }
    $directory = $root . '/spaces/' . spacefast_content_require_space_id();
    if (!is_dir($directory)) {
        return;
    }
    $lock = fopen($directory . '/wordpress-routes.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new Spacefast_Content_Error(503, 'content_routes_unavailable', 'Content routes could not be locked.');
    }
    try {
        spacefast_content_public_routes_refresh_locked();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function spacefast_content_permalink_template(string $path, string $placeholder): string
{
    return $path === '/' ? '/' . $placeholder : preg_replace('#[^/]+/?$#', $placeholder, $path);
}
