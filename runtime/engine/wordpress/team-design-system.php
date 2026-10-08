<?php
/** Atomic, team-authorized writes over native Knowledge and Media Library records. */
declare(strict_types=1);

require_once __DIR__ . '/../shared/design-upload.generated.php';

const SPACEFAST_DESIGN_TYPES = ['color', 'typography', 'layout', 'tone', 'rule', 'asset'];
const SPACEFAST_DESIGN_ASSET_MAX_BYTES = 25000000;
const SPACEFAST_DESIGN_METADATA_MAX_BYTES = 262144;

if (function_exists('add_filter')) {
    add_action('pre_get_posts', 'spacefast_design_media_query', 50);
    add_filter('map_meta_cap', 'spacefast_design_media_meta_cap', 50, 4);
    add_filter('rest_request_before_callbacks', 'spacefast_design_media_rest_guard', 5, 3);
    add_filter('pre_delete_attachment', 'spacefast_design_media_delete_guard', 50, 2);
    add_filter('pre_trash_post', 'spacefast_design_media_delete_guard', 50, 2);
    add_filter('wp_get_attachment_url', static fn ($url, $id) => spacefast_design_media_private($id) ? false : $url, 50, 2);
}

function spacefast_design_media_private(int $id): bool
{
    return get_post_type($id) === 'attachment' && metadata_exists('post', $id, '_sf_design_team_id');
}

function spacefast_design_media_allowed(int $id, string $cap = 'read_post'): bool
{
    $authority = spacefast_knowledge_authority();
    return $authority !== null
        && get_post_meta($id, '_sf_design_team_id', true) === $authority['teamId']
        && get_post_meta($id, '_spacefast_space_id', true) === spacefast_content_space_id()
        && ($cap === 'read_post' || ($authority['write'] && ($cap !== 'delete_post' || $authority['admin'])));
}

/** Native lists include references only when the request carries their team's authority. */
function spacefast_design_media_query(mixed $query): void
{
    $visible = [['key' => '_sf_design_team_id', 'compare' => 'NOT EXISTS']];
    $authority = spacefast_knowledge_authority();
    if ($authority !== null && spacefast_content_space_id() !== '') {
        $visible[] = ['key' => '_sf_design_team_id', 'value' => $authority['teamId']];
    }
    $query->set('meta_query', ['relation' => 'AND', (array) $query->get('meta_query'), ['relation' => 'OR', ...$visible]]);
}

function spacefast_design_media_meta_cap(array $caps, string $cap, int $userId, array $args): array
{
    $id = (int) ($args[0] ?? 0);
    return in_array($cap, ['read_post', 'edit_post', 'delete_post'], true)
        && spacefast_design_media_private($id)
        && ($userId !== get_current_user_id() || !spacefast_design_media_allowed($id, $cap))
        ? ['do_not_allow'] : $caps;
}

/** Core's public attachment controller does not always ask read_post for inherited media. */
function spacefast_design_media_rest_guard(mixed $response, mixed $handler, mixed $request): mixed
{
    if ($response !== null || !preg_match('#\A/wp/v2/media/([1-9][0-9]*)(?:/|$)#', $request->get_route(), $match)
        || !spacefast_design_media_private((int) $match[1])) return $response;
    $cap = match ($request->get_method()) { 'GET', 'HEAD' => 'read_post', 'DELETE' => 'delete_post', default => 'edit_post' };
    return spacefast_design_media_allowed((int) $match[1], $cap)
        ? $response : new WP_Error('content_media_not_found', 'Media not found.', ['status' => 404]);
}

/** wp_delete_attachment/wp_trash_post can also be called without core's capability checks. */
function spacefast_design_media_delete_guard(mixed $delete, mixed $post): mixed
{
    return spacefast_design_media_private((int) $post->ID) && !spacefast_design_media_allowed((int) $post->ID, 'delete_post')
        ? false : $delete;
}

function spacefast_design_media_root(): string
{
    $root = spacefast_content_upload_private_root();
    $spaceId = spacefast_content_space_id();
    if ($root === '' || $spaceId === '') spacefast_design_error(503, 'team_design_system_storage_invalid', 'Private team media storage is unavailable.');
    return $root . '/spaces/' . $spaceId . '/team-design-media';
}

/** This directory has no public serving lane, even when its storage Space is published. */
function spacefast_design_upload_dir(array $uploads): array
{
    $uploads['basedir'] = spacefast_design_media_root();
    $uploads['baseurl'] = '';
    $uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
    $uploads['url'] = $uploads['subdir'];
    return $uploads;
}

function spacefast_design_media_read(WP_Post $attachment): array
{
    $file = get_attached_file($attachment->ID);
    $path = is_string($file) ? realpath($file) : false;
    $root = realpath(spacefast_design_media_root());
    if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
        || !is_file($path) || filesize($path) > SPACEFAST_DESIGN_ASSET_MAX_BYTES) {
        spacefast_design_error(404, 'team_design_system_asset_not_found', 'The private attachment is unavailable.');
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) spacefast_design_error(503, 'team_design_system_asset_unavailable', 'The private attachment could not be read.');
    return ['base64' => base64_encode($bytes), 'contentType' => $attachment->post_mime_type, 'filename' => basename($path)];
}

function spacefast_design_error(int $status, string $code, string $message): never
{
    throw new Spacefast_Content_Error($status, $code, $message);
}

function spacefast_design_json(mixed $value): string
{
    if (is_array($value)) {
        if (!array_is_list($value)) ksort($value);
        foreach ($value as $key => $child) $value[$key] = json_decode(spacefast_design_json($child), true);
    }
    $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) spacefast_design_error(400, 'team_design_system_invalid', 'Design-system data is not valid JSON.');
    return $json;
}

/** Every lookup includes both team and physical storage Space, including receipts. */
function spacefast_design_posts(array $extra = []): array
{
    return get_posts([
        'post_type' => 'wp_knowledge', 'post_status' => 'private', 'numberposts' => -1,
        'orderby' => 'ID', 'order' => 'ASC',
        'meta_query' => array_merge([
            ['key' => '_spacefast_space_id', 'value' => spacefast_content_space_id()],
            ['key' => '_sf_team_id', 'value' => spacefast_knowledge_authority()['teamId']],
            ['key' => '_sf_kind', 'value' => 'design-system'],
        ], $extra),
    ]);
}

function spacefast_design_find(string $id): ?WP_Post
{
    return spacefast_design_posts([['key' => '_sf_id', 'value' => $id]])[0] ?? null;
}

function spacefast_design_value(WP_Post $post): mixed
{
    $content = json_decode($post->post_content, true);
    if (!is_array($content) || ($content['schemaVersion'] ?? null) !== 1 || !array_key_exists('value', $content)) {
        spacefast_design_error(503, 'team_design_system_storage_invalid', 'A design-system record cannot be decoded.');
    }
    return $content['value'];
}

function spacefast_design_snapshot(WP_Post $post): array
{
    return [
        'id' => get_post_meta($post->ID, '_sf_id', true),
        'type' => get_post_meta($post->ID, '_sf_category', true),
        'value' => spacefast_design_value($post),
        'archivedAt' => get_post_meta($post->ID, '_sf_archived_at', true),
    ];
}

/** Native revisions retain content and the registered revisioned attribution metadata. */
function spacefast_design_write(string $id, string $type, mixed $value, array $writer, string $archivedAt = ''): int
{
    $old = spacefast_design_find($id);
    $now = gmdate('Y-m-d\TH:i:s\Z');
    $meta = [
        '_sf_team_id' => spacefast_knowledge_authority()['teamId'], '_sf_target_space' => '',
        '_sf_id' => $id, '_sf_kind' => 'design-system', '_sf_category' => $type,
        '_sf_pinned' => false, '_sf_archived_at' => $archivedAt, '_sf_skill_key' => '', '_sf_enabled' => false,
        '_sf_updated_at' => $now, '_sf_updated_by' => $writer,
    ];
    if ($old === null) $meta = array_merge($meta, ['_sf_created_at' => $now, '_sf_created_by' => $writer]);
    $postId = wp_insert_post(wp_slash([
        'ID' => $old?->ID ?? 0, 'post_type' => 'wp_knowledge', 'post_status' => 'private',
        'post_title' => $type . ': ' . $id,
        'post_content' => spacefast_design_json(['schemaVersion' => 1, 'type' => $type, 'value' => $value]),
        'post_author' => $old?->post_author ?? get_current_user_id(),
        'meta_input' => $meta,
    ]), true);
    global $wpdb;
    if (is_wp_error($postId) || $postId < 1 || $wpdb->last_error !== '') {
        spacefast_design_error(503, 'team_design_system_write_failed', 'The design-system change could not be stored.');
    }
    $GLOBALS['SPACEFAST_DESIGN_TOUCHED'][] = $postId;
    $term = term_exists('design-system', 'wp_knowledge_type');
    if (!$term) $term = wp_insert_term('Design system', 'wp_knowledge_type', ['slug' => 'design-system']);
    if (is_wp_error($term) || is_wp_error(wp_set_object_terms($postId, [(int) $term['term_id']], 'wp_knowledge_type'))) {
        spacefast_design_error(503, 'team_design_system_write_failed', 'The design-system type could not be stored.');
    }
    $revisionId = wp_save_post_revision($postId);
    if (is_int($revisionId) && $revisionId > 0) $GLOBALS['SPACEFAST_DESIGN_TOUCHED'][] = $revisionId;
    if ($wpdb->last_error !== '') spacefast_design_error(503, 'team_design_system_write_failed', 'The design-system revision could not be stored.');
    return $postId;
}

function spacefast_design_attachment(int $id, bool $required = true): ?WP_Post
{
    $post = get_post($id);
    if (!$post || $post->post_type !== 'attachment' || $post->post_status === 'trash'
        || get_post_meta($id, '_spacefast_space_id', true) !== spacefast_content_space_id()
        || get_post_meta($id, '_sf_design_team_id', true) !== spacefast_knowledge_authority()['teamId']) {
        if ($required) spacefast_design_error(404, 'team_design_system_asset_not_found', 'The attachment does not belong to this team\'s design-system media.');
        return null;
    }
    return $post;
}

/** Build the complete next state before the first durable value is changed. */
function spacefast_design_changes(array $changes, array $records): array
{
    // Put requested entries first, in request order, rather than their stored post-ID order.
    $next = [];
    foreach (['colors' => 'color', 'rules' => 'rule', 'assets' => 'asset'] as $collection => $type) {
        $edits = $collection === 'rules' ? ($changes['voice']['rules'] ?? []) : ($changes[$collection] ?? []);
        foreach ($edits['upsert'] ?? [] as $patch) {
            $id = $patch['id'] ?? wp_generate_uuid4();
            $old = $records[$id] ?? null;
            if (isset($patch['id']) && ($old === null || $old['type'] !== $type || $old['archivedAt'] !== '')) {
                spacefast_design_error(404, 'team_design_system_entry_not_found', 'The design-system entry was not found.');
            }
            unset($patch['id']);
            $value = array_merge($old['value'] ?? [], $patch);
            $required = match ($type) { 'color' => ['name', 'hex'], 'rule' => ['text'], 'asset' => ['attachmentId'] };
            foreach ($required as $key) if (!isset($value[$key])) spacefast_design_error(400, 'team_design_system_invalid', 'A new entry is missing a required value.');
            if ($type === 'asset' && ($old === null || array_key_exists('attachmentId', $patch))) {
                spacefast_design_attachment((int) $value['attachmentId']);
            }
            $next[$id] = ['id' => $id, 'type' => $type, 'value' => $value, 'archivedAt' => ''];
        }
        foreach ($edits['remove'] ?? [] as $id) {
            if (!isset($records[$id]) || $records[$id]['type'] !== $type || $records[$id]['archivedAt'] !== '') {
                spacefast_design_error(404, 'team_design_system_entry_not_found', 'The design-system entry was not found.');
            }
            $next[$id] = $records[$id];
            $next[$id]['archivedAt'] = gmdate('Y-m-d\TH:i:s\Z');
        }
    }
    foreach (['typography', 'layout'] as $type) {
        if (isset($changes[$type]) && $changes[$type] !== []) $next[$type] = [
            'id' => $type, 'type' => $type,
            'value' => array_merge($records[$type]['value'] ?? [], $changes[$type]), 'archivedAt' => '',
        ];
    }
    if (array_key_exists('tone', $changes['voice'] ?? [])) {
        $tone = $changes['voice']['tone'];
        // Clearing a field that has never existed is a no-op.
        if ($tone !== null || isset($records['tone'])) $next['tone'] = [
            'id' => 'tone', 'type' => 'tone', 'value' => $tone,
            'archivedAt' => $tone === null ? gmdate('Y-m-d\TH:i:s\Z') : '',
        ];
    }
    return $next + $records;
}

function spacefast_design_budget(array $records): void
{
    $bytes = 0;
    $counts = [];
    foreach ($records as $record) {
        if ($record['archivedAt'] !== '') continue;
        $bytes += strlen(spacefast_design_json($record));
        $type = $record['type'];
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }
    if ($bytes > SPACEFAST_DESIGN_METADATA_MAX_BYTES || max($counts ?: [0]) > 200) {
        spacefast_design_error(409, 'team_design_system_limit_reached', 'Keep active design metadata within 256 KiB and 200 entries per type.');
    }
}

function spacefast_design_save(array $request, array $writer): array
{
    $head = spacefast_design_find('state');
    $state = $head === null ? ['revision' => 0, 'lastActionId' => null] : spacefast_design_value($head);
    if (($request['expectedRevision'] ?? null) !== $state['revision']) {
        spacefast_design_error(409, 'team_design_system_revision_conflict', 'The design system changed. Refresh its revision before saving.');
    }
    $records = [];
    foreach (spacefast_design_posts([['key' => '_sf_category', 'value' => SPACEFAST_DESIGN_TYPES, 'compare' => 'IN']]) as $post) {
        $snapshot = spacefast_design_snapshot($post);
        $records[$snapshot['id']] = $snapshot;
    }
    $next = $records;
    if ($request['action'] === 'save') {
        $next = spacefast_design_changes($request['changes'] ?? [], $records);
    } else {
        if (($request['actionId'] ?? null) !== $state['lastActionId'] || $state['lastActionId'] === null) {
            spacefast_design_error(409, 'team_design_system_undo_conflict', 'Only the last design-system action can be undone.');
        }
        $action = spacefast_design_find($state['lastActionId']);
        if ($action === null || get_post_meta($action->ID, '_sf_category', true) !== 'action') {
            spacefast_design_error(503, 'team_design_system_storage_invalid', 'The last design-system action is unavailable.');
        }
        $next = [];
        foreach (spacefast_design_value($action)['entries'] as $entry) {
            $before = $entry['before'];
            $id = $entry['after']['id'];
            if ($before === null) $next[$id] = array_merge($records[$id], ['archivedAt' => gmdate('Y-m-d\TH:i:s\Z')]);
            else $next[$id] = $before;
        }
        $next += $records;
    }
    spacefast_design_budget($next);
    $entries = [];
    $result = [];
    foreach ($next as $id => $record) {
        $before = $records[$id] ?? null;
        if (spacefast_design_json($before) === spacefast_design_json($record)) continue;
        // Repeated explicit clears do not create a new action just for a timestamp.
        if ($before !== null && $before['archivedAt'] !== '' && $record['archivedAt'] !== ''
            && spacefast_design_json($before['value']) === spacefast_design_json($record['value'])) continue;
        $entries[] = ['before' => $before, 'after' => $record];
        $result[] = ['id' => $id, 'type' => $record['type'], 'action' => $record['archivedAt'] !== '' ? 'removed' : ($before === null ? 'created' : ($before['archivedAt'] !== '' ? 'restored' : 'updated'))];
    }
    if ($entries === []) return ['revision' => $state['revision'], 'actionId' => $state['lastActionId'], 'unchanged' => true, 'changes' => []];
    foreach ($entries as $entry) {
        $record = $entry['after'];
        spacefast_design_write($record['id'], $record['type'], $record['value'], $writer, $record['archivedAt']);
    }
    $actionId = wp_generate_uuid4();
    $revision = $state['revision'] + 1;
    spacefast_design_write($actionId, 'action', [
        'action' => $request['action'], 'operationId' => $request['operationId'],
        'revision' => $revision, 'entries' => $entries,
    ], $writer);
    spacefast_design_write('state', 'state', ['revision' => $revision, 'lastActionId' => $actionId], $writer);
    return ['revision' => $revision, 'actionId' => $actionId, 'unchanged' => false, 'changes' => $result];
}

/** SVG acceptance is restricted to this sanitized upload, never a global MIME grant. */
function spacefast_design_svg(string $bytes): string
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadXML($bytes, LIBXML_NONET);
        $root = $document->documentElement;
        if (!$loaded || $document->doctype !== null || $root === null || $root->localName !== 'svg'
            || !in_array($root->namespaceURI, [null, '', 'http://www.w3.org/2000/svg'], true)) {
            spacefast_design_error(415, 'team_design_system_svg_invalid', 'Upload an SVG without entities or external content.');
        }
        $elements = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'use', 'symbol', 'title', 'desc', 'text', 'tspan'];
        $attributes = ['id', 'xmlns', 'xmlns:xlink', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'gradientTransform', 'gradientUnits', 'offset', 'stop-color', 'stop-opacity', 'spreadMethod', 'clip-path', 'clip-rule', 'mask', 'maskUnits', 'maskContentUnits', 'preserveAspectRatio', 'href', 'xlink:href', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dx', 'dy', 'role', 'aria-label'];
        foreach ($document->getElementsByTagName('*') as $element) {
            if (!in_array($element->localName, $elements, true) || $element->namespaceURI !== $root->namespaceURI) {
                spacefast_design_error(415, 'team_design_system_svg_invalid', 'The SVG contains an unsupported element.');
            }
            foreach ($element->attributes as $attribute) {
                $value = $attribute->value;
                if (!in_array($attribute->nodeName, $attributes, true)
                    || (in_array($attribute->localName, ['href'], true) && !preg_match('/\A#[A-Za-z_][A-Za-z0-9_.:-]*\z/', $value))
                    || (stripos($value, 'url') !== false && !preg_match('/\Aurl\(#[A-Za-z_][A-Za-z0-9_.:-]*\)\z/', $value))
                    || preg_match('/(?:javascript|data|expression)\s*:/i', $value)) {
                    spacefast_design_error(415, 'team_design_system_svg_invalid', 'The SVG contains an unsafe attribute or external reference.');
                }
            }
        }
        $xpath = new DOMXPath($document);
        if ($xpath->query('//processing-instruction()')->length > 0) spacefast_design_error(415, 'team_design_system_svg_invalid', 'SVG processing instructions are not supported.');
        return $document->saveXML($root);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

/** Validate retained references before allowing their MIME through native media intake. */
function spacefast_design_reference_valid(string $bytes, string $type, string $file): bool
{
    switch ($type) {
        case 'font/woff': return strlen($bytes) >= 44 && str_starts_with($bytes, 'wOFF');
        case 'font/woff2': return strlen($bytes) >= 48 && str_starts_with($bytes, 'wOF2');
        case 'font/ttf': return strlen($bytes) >= 12 && str_starts_with($bytes, "\x00\x01\x00\x00");
        // OpenType supports both TrueType and CFF outlines in an .otf file.
        case 'font/otf': return strlen($bytes) >= 12
            && (str_starts_with($bytes, 'OTTO') || str_starts_with($bytes, "\x00\x01\x00\x00"));
        case 'application/vnd.ms-fontobject': return strlen($bytes) >= 82 && substr($bytes, 34, 2) === "\x4c\x50";
        case 'application/vnd.ms-powerpoint':
            return str_starts_with($bytes, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")
                && str_contains($bytes, "P\0o\0w\0e\0r\0P\0o\0i\0n\0t\0 \0D\0o\0c\0u\0m\0e\0n\0t\0");
        case 'application/zip':
        case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
            // Inspect the original container without extracting or executing its entries.
            $zip = new ZipArchive();
            if ($zip->open($file, ZipArchive::CHECKCONS) !== true) return false;
            try {
                if ($type === 'application/zip') return true;
                $contentTypes = $zip->getFromName('[Content_Types].xml', 1_048_576);
                return $zip->locateName('ppt/presentation.xml') !== false
                    && is_string($contentTypes)
                    && str_contains($contentTypes, 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml')
                    && !str_contains($contentTypes, 'macroEnabled')
                    && $zip->locateName('ppt/vbaProject.bin') === false;
            } finally {
                $zip->close();
            }
        default:
            // Source text is retained verbatim, including HTML and component code.
            if (preg_match('//u', $bytes) !== 1 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $bytes)) return false;
            $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file);
            if (!is_string($detected) || (!str_starts_with($detected, 'text/')
                && !in_array($detected, ['application/json', 'application/javascript', 'application/xml', 'application/xhtml+xml'], true))) return false;
            if ($type === 'application/json') {
                try { json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
                catch (JsonException) { return false; }
            }
            return true;
    }
}

function spacefast_design_upload(array $request): array
{
    // Match native media requests before decoding the base64 envelope or inspecting a large image.
    wp_raise_memory_limit('image');
    $bytes = base64_decode((string) ($request['contentBase64'] ?? ''), true);
    $filename = (string) ($request['filename'] ?? '');
    $type = $request['contentType'] ?? '';
    $types = SPACEFAST_DESIGN_ASSET_MIMES;
    if (!is_string($bytes) || $bytes === '' || strlen($bytes) > SPACEFAST_DESIGN_ASSET_MAX_BYTES
        || !isset($types[$type]) || $filename === '' || preg_match('/[\/\\\\\x00-\x1f\x7f]/', $filename)) {
        spacefast_design_error(400, 'team_design_system_upload_invalid', 'Upload one supported file of at most 25 MB.');
    }
    if ($type === 'image/svg+xml') $bytes = spacefast_design_svg($bytes);
    spacefast_content_storage_load_media_admin();
    $allowed = [$types[$type] => $type];
    $expected = wp_check_filetype($filename, $allowed);
    if ($expected['type'] !== $type) spacefast_design_error(415, 'team_design_system_asset_type_invalid', 'The filename extension must match the content type.');
    $temporary = wp_tempnam($filename);
    if (!is_string($temporary) || file_put_contents($temporary, $bytes) === false) spacefast_design_error(503, 'team_design_system_upload_failed', 'The file could not be staged.');
    $mimes = static fn () => [$types[$type] => $type];
    $reference = !in_array($type, ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml', 'application/pdf'], true);
    // Only this exact, validated staged reference can bypass fileinfo's ambiguous
    // text/font detections. Core continues to validate existing image/PDF formats.
    $filetype = static function (array $checked, string $file, string $name) use ($temporary, $filename, $expected, $reference): array {
        return $reference && $file === $temporary && $name === $filename
            ? ['ext' => $expected['ext'], 'type' => $expected['type'], 'proper_filename' => false]
            : $checked;
    };
    $uploaded = null;
    add_filter('upload_mimes', $mimes, PHP_INT_MAX);
    add_filter('upload_dir', 'spacefast_design_upload_dir', PHP_INT_MAX);
    try {
        if ($reference && !spacefast_design_reference_valid($bytes, $type, $temporary)) {
            spacefast_design_error(415, 'team_design_system_asset_type_invalid', 'The file bytes do not match a supported reference format.');
        }
        add_filter('wp_check_filetype_and_ext', $filetype, PHP_INT_MAX, 3);
        $file = ['name' => $filename, 'tmp_name' => $temporary, 'size' => strlen($bytes), 'error' => 0];
        $uploaded = wp_handle_sideload($file, ['test_form' => false, 'mimes' => $allowed]);
    } finally {
        remove_filter('wp_check_filetype_and_ext', $filetype, PHP_INT_MAX);
        remove_filter('upload_mimes', $mimes, PHP_INT_MAX);
        remove_filter('upload_dir', 'spacefast_design_upload_dir', PHP_INT_MAX);
        if (is_file($temporary)) unlink($temporary);
    }
    if (!is_array($uploaded) || isset($uploaded['error']) || ($uploaded['type'] ?? '') !== $type) {
        if (isset($uploaded['file']) && is_file($uploaded['file'])) unlink($uploaded['file']);
        spacefast_design_error(415, 'team_design_system_asset_type_invalid', 'The filename, content type, and file bytes must agree.');
    }
    $GLOBALS['SPACEFAST_DESIGN_UPLOAD_FILE'] = $uploaded['file'];
    $attachmentId = wp_insert_attachment(['post_mime_type' => $type, 'post_title' => $filename, 'post_status' => 'inherit'], $uploaded['file'], 0, true);
    if (is_wp_error($attachmentId) || $attachmentId < 1) spacefast_design_error(503, 'team_design_system_upload_failed', 'The attachment could not be stored.');
    $GLOBALS['SPACEFAST_DESIGN_UPLOAD_ID'] = $attachmentId;
    $GLOBALS['SPACEFAST_DESIGN_TOUCHED'][] = $attachmentId;
    if (update_post_meta($attachmentId, '_sf_design_team_id', spacefast_knowledge_authority()['teamId']) === false) {
        spacefast_design_error(503, 'team_design_system_upload_failed', 'The attachment team could not be stored.');
    }
    wp_update_attachment_metadata($attachmentId, wp_generate_attachment_metadata($attachmentId, $uploaded['file']));
    return ['attachmentId' => $attachmentId, 'filename' => basename($uploaded['file']), 'contentType' => $type, 'size' => strlen($bytes)];
}

function spacefast_design_system_mutate(array $request): array
{
    $authority = spacefast_knowledge_authority();
    if ($authority === null || !$authority['write'] || !in_array($request['action'] ?? null, ['save', 'undo', 'upload'], true)
        || !is_string($request['operationId'] ?? null) || $request['operationId'] === '' || strlen($request['operationId']) > 128
        || !is_array($request['writer'] ?? null)) spacefast_design_error(403, 'knowledge_authority_invalid', 'A team design-system write is required.');
    spacefast_content_principal_establish_user();
    $writer = $request['writer'];
    $payload = $request;
    unset($payload['principal'], $payload['knowledgeAuthority'], $payload['writer'], $payload['operation']);
    // Hash the large encoded file once; do not duplicate it through JSON canonicalization.
    if (isset($payload['contentBase64'])) $payload['contentBase64'] = 'sha256:' . hash('sha256', $payload['contentBase64']);
    $hash = hash('sha256', spacefast_design_json($payload));
    $receiptId = 'receipt:' . hash('sha256', $writer['actorType'] . ':' . $writer['actorId'] . ':' . $request['operationId']);
    global $wpdb;
    $lock = 'sf-knowledge-' . substr(hash('sha256', spacefast_content_space_id() . ':' . $authority['teamId']), 0, 48);
    if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $lock)) !== 1) spacefast_design_error(503, 'knowledge_busy', 'Team context is busy. Retry the write.');
    $GLOBALS['SPACEFAST_DESIGN_TOUCHED'] = [];
    $GLOBALS['SPACEFAST_DESIGN_UPLOAD_FILE'] = null;
    $GLOBALS['SPACEFAST_DESIGN_UPLOAD_ID'] = null;
    try {
        $receipt = spacefast_design_find($receiptId);
        if ($receipt !== null) {
            $saved = spacefast_design_value($receipt);
            if ($saved['hash'] !== $hash) spacefast_design_error(409, 'team_design_system_operation_conflict', 'This operation ID already names different input.');
            return ['status' => 200, 'headers' => (object) [], 'body' => $saved['response']];
        }
        if ($wpdb->query('START TRANSACTION') === false) spacefast_design_error(503, 'team_design_system_write_failed', 'The design-system transaction could not start.');
        kses_remove_filters();
        try {
            $response = $request['action'] === 'upload' ? spacefast_design_upload($request) : spacefast_design_save($request, $writer);
            spacefast_design_write($receiptId, 'receipt', ['hash' => $hash, 'response' => $response], $writer);
            if ($wpdb->query('COMMIT') === false) spacefast_design_error(503, 'team_design_system_write_failed', 'The design-system transaction could not commit.');
            // A concurrent native read may have cached the old committed row during the transaction.
            foreach ($GLOBALS['SPACEFAST_DESIGN_TOUCHED'] as $id) clean_post_cache($id);
            return ['status' => 200, 'headers' => (object) [], 'body' => $response];
        } catch (Throwable $error) {
            $uploadId = $GLOBALS['SPACEFAST_DESIGN_UPLOAD_ID'];
            $metadata = is_int($uploadId) ? wp_get_attachment_metadata($uploadId) : [];
            $wpdb->query('ROLLBACK');
            foreach ($GLOBALS['SPACEFAST_DESIGN_TOUCHED'] as $id) clean_post_cache($id);
            $file = $GLOBALS['SPACEFAST_DESIGN_UPLOAD_FILE'];
            if (is_int($uploadId) && is_string($file)) wp_delete_attachment_files($uploadId, $metadata ?: [], [], $file);
            if (is_string($file) && is_file($file)) unlink($file);
            throw $error;
        } finally {
            kses_init_filters();
        }
    } finally {
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
}

/** One InnoDB statement snapshot keeps values and revision consistent without the write lock. */
function spacefast_design_system_read(): array
{
    $authority = spacefast_knowledge_authority();
    if ($authority === null) spacefast_design_error(403, 'knowledge_authority_invalid', 'Team context access is required.');
    spacefast_content_principal_establish_user();
    global $wpdb;
    // Read native rows directly: post/query/meta caches must not mix different committed revisions.
    $types = implode(',', array_fill(0, count(SPACEFAST_DESIGN_TYPES), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID, p.post_content, entry.meta_value AS entry_id, category.meta_value AS entry_type,
                updated.meta_value AS updated_at
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} entry ON entry.post_id = p.ID AND entry.meta_key = '_sf_id'
         JOIN {$wpdb->postmeta} category ON category.post_id = p.ID AND category.meta_key = '_sf_category'
         JOIN {$wpdb->postmeta} space ON space.post_id = p.ID AND space.meta_key = '_spacefast_space_id'
         JOIN {$wpdb->postmeta} team ON team.post_id = p.ID AND team.meta_key = '_sf_team_id'
         JOIN {$wpdb->postmeta} kind ON kind.post_id = p.ID AND kind.meta_key = '_sf_kind'
         LEFT JOIN {$wpdb->postmeta} archived ON archived.post_id = p.ID AND archived.meta_key = '_sf_archived_at'
         LEFT JOIN {$wpdb->postmeta} updated ON updated.post_id = p.ID AND updated.meta_key = '_sf_updated_at'
         WHERE p.post_type = 'wp_knowledge' AND p.post_status = 'private'
           AND space.meta_value = %s AND team.meta_value = %s AND kind.meta_value = 'design-system'
           AND ((category.meta_value = 'state' AND entry.meta_value = 'state')
                OR (category.meta_value IN ($types) AND archived.meta_value = ''))
         ORDER BY p.ID",
        spacefast_content_space_id(), $authority['teamId'], ...SPACEFAST_DESIGN_TYPES,
    ));
    if ($rows === null || $wpdb->last_error !== '') spacefast_design_error(503, 'team_design_system_storage_invalid', 'The design-system snapshot could not be read.');
    $state = ['revision' => 0, 'lastActionId' => null];
    $updatedAt = null;
    $hasValues = false;
    $document = ['colors' => [], 'typography' => (object) [], 'layout' => (object) [],
        'voice' => ['tone' => null, 'rules' => []], 'assets' => []];
    foreach ($rows as $row) {
        $value = spacefast_design_value(new WP_Post($row));
        if ($row->entry_type === 'state') {
            $state = $value;
            $updatedAt = $row->updated_at;
            continue;
        }
        $hasValues = true;
        switch ($row->entry_type) {
            case 'color': $document['colors'][] = ['id' => $row->entry_id] + $value; break;
            case 'rule': $document['voice']['rules'][] = ['id' => $row->entry_id] + $value; break;
            case 'tone': $document['voice']['tone'] = $value; break;
            case 'typography': case 'layout': $document[$row->entry_type] = (object) $value; break;
            case 'asset':
                $attachment = spacefast_design_attachment((int) $value['attachmentId'], false);
                // The control plane supplies the authenticated download URL.
                $document['assets'][] = ['id' => $row->entry_id, 'available' => $attachment !== null, 'url' => null] + $value;
                break;
        }
    }
    return ['revision' => $state['revision'], 'actionId' => $state['lastActionId'],
        'document' => $hasValues ? $document : null, 'updatedAt' => $updatedAt];
}

/** Keep domain failures in the same typed REST envelope as native Knowledge. */
function spacefast_design_system_dispatch(array $request): array
{
    try {
        if (($request['action'] ?? null) === 'read') {
            if (spacefast_knowledge_authority() === null) spacefast_design_error(403, 'knowledge_authority_invalid', 'Team context access is required.');
            if (isset($request['attachmentId'])) {
                $attachment = spacefast_design_attachment((int) $request['attachmentId']);
                $body = spacefast_design_media_read($attachment);
            } else $body = spacefast_design_system_read();
            return ['status' => 200, 'headers' => (object) [], 'body' => $body];
        }
        return spacefast_design_system_mutate($request);
    } catch (Spacefast_Content_Error $error) {
        return ['status' => $error->status, 'headers' => (object) [],
            'body' => ['code' => $error->codeName, 'message' => $error->getMessage(), 'data' => ['status' => $error->status]]];
    }
}
