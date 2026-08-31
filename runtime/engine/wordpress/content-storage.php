<?php
declare(strict_types=1);

/**
 * The storage feature: a Space's files, reachable through WordPress's own
 * Abilities API.
 *
 * There is no Spacefast-side storage service and no third-party plugin. A file
 * here IS a WordPress attachment: the same `attachment` post the media library
 * shows, written by the same WP core calls (wp_handle_sideload,
 * wp_insert_attachment, wp_generate_attachment_metadata). What this file adds
 * is the three things the kernel was missing to call that a storage product —
 * a folder taxonomy, registered meta agents may write, and the abilities that
 * publish all of it — and nothing else.
 *
 * Bytes already land correctly without this file. content-kernel.php's
 * `upload_dir` filter rewrites the uploads root to
 * `.stattic/storage/spaces/<spaceId>/content-media`, and the runtime serves it
 * back under `/__spacefast/content-media/<spaceHash>/` with the Space's own
 * access rules applied. So an upload here is a plain WP upload that inherits
 * that scoping; this file never touches a path itself.
 *
 * Three rules this surface deliberately does not break:
 *
 *  - Authorization is not stored. Like the users feature, every check below is
 *    `current_user_can` against the role content-principals.php projects from
 *    this request's Grant decision — `upload_files`, `edit_post`,
 *    `delete_post`, exactly as WordPress applies them. No ability consults a
 *    role directly.
 *
 *  - Deleting trashes. The platform's soft-delete doctrine says durable user
 *    data is tombstoned, never hard-deleted — and WordPress already has that
 *    mechanism, so we use WordPress's rather than inventing a parallel one.
 *    `zero/storage-delete` calls wp_trash_post: the attachment leaves every
 *    listing, its bytes stay on disk, and WP's own untrash restores it. A
 *    permanent delete is not part of this surface; that is the platform's
 *    delayed purge to own, not a tenant-callable ability.
 *
 *  - Space scoping is not optional. One wp.cloud site hosts many Spaces, so
 *    every read and write is fenced by the `_spacefast_space_id` meta that
 *    content-kernel.php's `add_attachment` hook stamps, and folder terms are
 *    slug-namespaced per Space the way content-model collections already are.
 */

const SPACEFAST_CONTENT_STORAGE_ABILITY_CATEGORY = 'zero-storage';
const SPACEFAST_CONTENT_STORAGE_TAXONOMY = 'zero_folder';
const SPACEFAST_CONTENT_STORAGE_ALT_META = '_wp_attachment_image_alt';
const SPACEFAST_CONTENT_STORAGE_PAGE_SIZE_MAX = 100;
const SPACEFAST_CONTENT_STORAGE_DEFAULT_PAGE_SIZE = 20;
const SPACEFAST_CONTENT_STORAGE_FOLDER_DEPTH_MAX = 8;
const SPACEFAST_CONTENT_STORAGE_FOLDER_SEGMENT_MAX_BYTES = 96;
const SPACEFAST_CONTENT_STORAGE_FILENAME_MAX_BYTES = 255;
const SPACEFAST_CONTENT_STORAGE_TITLE_MAX_BYTES = 250;
const SPACEFAST_CONTENT_STORAGE_ALT_MAX_BYTES = 1000;
/**
 * The largest file an ability call may carry. Bytes arrive base64 in a JSON
 * ability input, so this is a deliberate ceiling on that transport, not on what
 * a Space may store — the dashboard's own upload path posts multipart and is
 * bounded elsewhere. Kept under the content endpoint's 4 MiB request bound so a
 * call that fits here cannot be refused by the transport underneath it.
 */
const SPACEFAST_CONTENT_STORAGE_UPLOAD_MAX_BYTES = 2621440;

if (function_exists('add_action')) {
    add_action('init', 'spacefast_content_storage_register_taxonomy', 5);
    add_action('init', 'spacefast_content_storage_register_meta', 6);
    // The Abilities API refuses registrations made anywhere else: both
    // wp_register_ability_category and wp_register_ability check doing_action()
    // for their own hook and return null otherwise.
    add_action('wp_abilities_api_categories_init', 'spacefast_content_storage_register_ability_category');
    add_action('wp_abilities_api_init', 'spacefast_content_storage_register_abilities');
}

/**
 * A hierarchical taxonomy on attachments — folders, in the one place WordPress
 * already knows how to model a tree. `zero_folder` is the WordPress-visible
 * name, matching the `zero_collection` the content-model kernel registers.
 */
function spacefast_content_storage_register_taxonomy(): void
{
    if (!function_exists('register_taxonomy')) {
        return;
    }
    register_taxonomy(SPACEFAST_CONTENT_STORAGE_TAXONOMY, ['attachment'], [
        'labels' => ['name' => 'Folders', 'singular_name' => 'Folder'],
        'public' => false,
        'show_ui' => false,
        'show_in_rest' => true,
        'hierarchical' => true,
        'rewrite' => false,
    ]);
}

/**
 * Alt text is the one attachment field agents genuinely need to write and the
 * only one WordPress keeps in post meta rather than on the post row. Registering
 * it is what makes it readable and writable through REST under the same
 * Space fence every other projected meta carries.
 */
function spacefast_content_storage_register_meta(): void
{
    if (!function_exists('register_post_meta')) {
        return;
    }
    register_post_meta('attachment', SPACEFAST_CONTENT_STORAGE_ALT_META, [
        'single' => true,
        'type' => 'string',
        'show_in_rest' => true,
        'auth_callback' => static fn (bool $allowed, string $key, int $postId): bool =>
            $allowed && spacefast_content_post_belongs_to_space($postId),
    ]);
}

function spacefast_content_storage_error(int $status, string $code, string $message): object
{
    return new WP_Error($code, $message, ['status' => $status]);
}

function spacefast_content_storage_input(mixed $input): array
{
    return is_array($input) ? $input : [];
}

/**
 * WordPress decides. current_user_can runs the projection content-principals.php
 * installs on user_has_cap, so the answer is this request's Grant-derived role
 * read through WordPress's own capability rules, never a parallel check.
 */
function spacefast_content_storage_may(string $capability, ...$arguments): bool
{
    return function_exists('current_user_can') && current_user_can($capability, ...$arguments);
}

/* -------------------------------------------------------------------------- */
/* Folders                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * The term slug one folder path segment takes in this Space.
 *
 * Terms are site-global, so the Space hash is what stops two Spaces' "photos"
 * from being one folder. Same construction the content-model kernel uses for
 * collection terms, and the depth prefix keeps `a/b` from colliding with `b`.
 */
function spacefast_content_storage_term_slug(string $spaceId, string $path): string
{
    return 'sf-' . substr(hash('sha256', $spaceId), 0, 16)
        . '-' . substr(hash('sha256', $path), 0, 24);
}

/**
 * Splits a folder path into its segments, or null when the path is not one this
 * surface will accept. Empty means the Space root, which is a valid target.
 *
 * @return list<string>|null
 */
function spacefast_content_storage_folder_segments(mixed $path): ?array
{
    if ($path === null || $path === '') {
        return [];
    }
    if (!is_string($path)) {
        return null;
    }
    $segments = [];
    foreach (explode('/', trim($path, '/')) as $segment) {
        $segment = trim($segment);
        if (
            $segment === ''
            || $segment === '.'
            || $segment === '..'
            || strlen($segment) > SPACEFAST_CONTENT_STORAGE_FOLDER_SEGMENT_MAX_BYTES
            // Control characters and backslashes never name a folder; they are
            // how a path turns into something else downstream.
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $segment) === 1
        ) {
            return null;
        }
        $segments[] = $segment;
    }
    return count($segments) > SPACEFAST_CONTENT_STORAGE_FOLDER_DEPTH_MAX ? null : $segments;
}

/**
 * Resolves a folder path to its term id in this Space, creating the terms when
 * asked. Returns 0 for the Space root and null when the path cannot be used.
 */
function spacefast_content_storage_folder_term(string $spaceId, mixed $path, bool $create): ?int
{
    $segments = spacefast_content_storage_folder_segments($path);
    if ($segments === null) {
        return null;
    }
    if ($segments === [] || !function_exists('get_term_by')) {
        return 0;
    }
    $parent = 0;
    $walked = '';
    foreach ($segments as $segment) {
        $walked = $walked === '' ? $segment : $walked . '/' . $segment;
        $slug = spacefast_content_storage_term_slug($spaceId, $walked);
        $term = get_term_by('slug', $slug, SPACEFAST_CONTENT_STORAGE_TAXONOMY);
        if (is_object($term)) {
            $parent = (int) ($term->term_id ?? 0);
            continue;
        }
        if (!$create || !function_exists('wp_insert_term')) {
            return null;
        }
        $inserted = wp_insert_term($segment, SPACEFAST_CONTENT_STORAGE_TAXONOMY, [
            'slug' => $slug,
            'parent' => $parent,
        ]);
        if (!is_array($inserted) || !isset($inserted['term_id'])) {
            return null;
        }
        $parent = (int) $inserted['term_id'];
    }
    return $parent > 0 ? $parent : null;
}

/** The folder path an attachment sits in, or '' for the Space root. */
function spacefast_content_storage_folder_path(int $attachmentId): string
{
    if (!function_exists('wp_get_object_terms')) {
        return '';
    }
    $terms = wp_get_object_terms($attachmentId, SPACEFAST_CONTENT_STORAGE_TAXONOMY);
    if (!is_array($terms) || $terms === []) {
        return '';
    }
    $term = $terms[0];
    if (!is_object($term)) {
        return '';
    }
    $names = [];
    $guard = 0;
    while (is_object($term) && $guard <= SPACEFAST_CONTENT_STORAGE_FOLDER_DEPTH_MAX) {
        array_unshift($names, (string) ($term->name ?? ''));
        $parentId = (int) ($term->parent ?? 0);
        $term = $parentId > 0 && function_exists('get_term')
            ? get_term($parentId, SPACEFAST_CONTENT_STORAGE_TAXONOMY)
            : null;
        $guard++;
    }
    return implode('/', $names);
}

/* -------------------------------------------------------------------------- */
/* Files                                                                       */
/* -------------------------------------------------------------------------- */

/** The wire shape of one file. */
function spacefast_content_storage_projection(int $attachmentId): array
{
    $post = function_exists('get_post') ? get_post($attachmentId) : null;
    $metadata = function_exists('wp_get_attachment_metadata')
        ? wp_get_attachment_metadata($attachmentId)
        : [];
    $metadata = is_array($metadata) ? $metadata : [];
    return [
        'id' => $attachmentId,
        'title' => is_object($post) ? (string) ($post->post_title ?? '') : '',
        'filename' => function_exists('wp_basename') && function_exists('get_attached_file')
            ? (string) wp_basename((string) get_attached_file($attachmentId))
            : '',
        'url' => function_exists('wp_get_attachment_url')
            ? (string) wp_get_attachment_url($attachmentId)
            : '',
        'mimeType' => is_object($post) ? (string) ($post->post_mime_type ?? '') : '',
        'alt' => function_exists('get_post_meta')
            ? (string) get_post_meta($attachmentId, SPACEFAST_CONTENT_STORAGE_ALT_META, true)
            : '',
        'folder' => spacefast_content_storage_folder_path($attachmentId),
        'width' => isset($metadata['width']) ? (int) $metadata['width'] : null,
        'height' => isset($metadata['height']) ? (int) $metadata['height'] : null,
        'filesize' => isset($metadata['filesize']) ? (int) $metadata['filesize'] : null,
        'uploadedAt' => is_object($post) ? (string) ($post->post_date_gmt ?? '') : '',
    ];
}

/**
 * One attachment, but only if it is this Space's and still live.
 *
 * `inherit` is the status a live attachment carries, so a trashed file resolves
 * to nothing here exactly as it is absent from a listing — one definition of
 * "gone" for reading it, moving it, and trashing it again.
 */
function spacefast_content_storage_resolve(mixed $input): int
{
    $attachmentId = (int) (spacefast_content_storage_input($input)['id'] ?? 0);
    if ($attachmentId < 1 || !function_exists('get_post')) {
        return 0;
    }
    $post = get_post($attachmentId);
    return is_object($post)
        && (string) ($post->post_type ?? '') === 'attachment'
        && (string) ($post->post_status ?? '') === 'inherit'
        && spacefast_content_post_belongs_to_space($attachmentId)
        ? $attachmentId
        : 0;
}

function spacefast_content_storage_list(mixed $input): mixed
{
    $input = spacefast_content_storage_input($input);
    $spaceId = spacefast_content_space_id();
    if ($spaceId === '' || !function_exists('get_posts')) {
        return spacefast_content_storage_error(503, 'zero_storage_unavailable', 'Space storage is unavailable.');
    }
    $page = (int) ($input['page'] ?? 1);
    $perPage = (int) ($input['perPage'] ?? SPACEFAST_CONTENT_STORAGE_DEFAULT_PAGE_SIZE);
    if ($page < 1 || $perPage < 1 || $perPage > SPACEFAST_CONTENT_STORAGE_PAGE_SIZE_MAX) {
        return spacefast_content_storage_error(400, 'zero_storage_page_invalid', 'The file page request is out of range.');
    }
    $query = [
        'post_type' => 'attachment',
        // Trashed files are gone from every listing; `inherit` is the status a
        // live attachment carries.
        'post_status' => 'inherit',
        'meta_query' => [spacefast_content_space_meta_clause()],
        'posts_per_page' => $perPage,
        'offset' => ($page - 1) * $perPage,
        'orderby' => 'date',
        'order' => 'DESC',
        'fields' => 'ids',
    ];
    if (array_key_exists('folder', $input)) {
        $termId = spacefast_content_storage_folder_term($spaceId, $input['folder'], false);
        if ($termId === null) {
            return spacefast_content_storage_error(404, 'zero_storage_folder_not_found', 'No such folder exists in this Space.');
        }
        $recursive = (bool) ($input['recursive'] ?? false);
        if ($termId > 0) {
            $query['tax_query'] = [[
                'taxonomy' => SPACEFAST_CONTENT_STORAGE_TAXONOMY,
                'field' => 'term_id',
                'terms' => $termId,
                'include_children' => $recursive,
            ]];
        } elseif (!$recursive) {
            // The Space root, shallow: only files that carry no folder term. A
            // recursive root listing filters nothing and returns every file.
            $query['tax_query'] = [[
                'taxonomy' => SPACEFAST_CONTENT_STORAGE_TAXONOMY,
                'operator' => 'NOT EXISTS',
            ]];
        }
    }
    $search = trim((string) ($input['search'] ?? ''));
    if ($search !== '') {
        $query['s'] = $search;
    }
    $mimeType = trim((string) ($input['mimeType'] ?? ''));
    if ($mimeType !== '') {
        $query['post_mime_type'] = $mimeType;
    }
    $ids = get_posts($query);
    return [
        'page' => $page,
        'perPage' => $perPage,
        'files' => array_values(array_map(
            'spacefast_content_storage_projection',
            array_map('intval', is_array($ids) ? $ids : [])
        )),
    ];
}

function spacefast_content_storage_get(mixed $input): mixed
{
    $attachmentId = spacefast_content_storage_resolve($input);
    return $attachmentId === 0
        ? spacefast_content_storage_error(404, 'zero_storage_not_found', 'No such file belongs to this Space.')
        : spacefast_content_storage_projection($attachmentId);
}

/**
 * Uploads one file into this Space.
 *
 * Every step is WP core's: wp_handle_sideload applies WordPress's own filename
 * sanitizing and allowed-mime-type rules, and it writes into the uploads root
 * content-kernel.php has already pointed at this Space. The `add_attachment`
 * hook there stamps the Space meta, so the file is fenced the moment it exists.
 */
function spacefast_content_storage_upload(mixed $input): mixed
{
    $input = spacefast_content_storage_input($input);
    $spaceId = spacefast_content_space_id();
    if (
        $spaceId === ''
        || !function_exists('wp_handle_sideload')
        || !function_exists('wp_insert_attachment')
        || !function_exists('wp_tempnam')
    ) {
        return spacefast_content_storage_error(503, 'zero_storage_unavailable', 'Space storage is unavailable.');
    }
    $filename = trim((string) ($input['filename'] ?? ''));
    if (
        $filename === ''
        || strlen($filename) > SPACEFAST_CONTENT_STORAGE_FILENAME_MAX_BYTES
        || str_contains($filename, '/')
        || str_contains($filename, '\\')
        || preg_match('/[\x00-\x1f\x7f]/', $filename) === 1
    ) {
        return spacefast_content_storage_error(400, 'zero_storage_filename_invalid', 'The file name is not usable.');
    }
    $encoded = (string) ($input['contentBase64'] ?? '');
    // strict: a padded, well-formed document or nothing. Silent truncation is
    // how a half-written file becomes a stored one.
    $bytes = $encoded === '' ? false : base64_decode($encoded, true);
    if (!is_string($bytes) || $bytes === '') {
        return spacefast_content_storage_error(400, 'zero_storage_content_invalid', 'The file content is not valid base64.');
    }
    if (strlen($bytes) > SPACEFAST_CONTENT_STORAGE_UPLOAD_MAX_BYTES) {
        return spacefast_content_storage_error(413, 'zero_storage_content_too_large', 'The file exceeds the ability upload limit.');
    }
    $title = trim((string) ($input['title'] ?? ''));
    if (strlen($title) > SPACEFAST_CONTENT_STORAGE_TITLE_MAX_BYTES) {
        return spacefast_content_storage_error(400, 'zero_storage_title_invalid', 'The file title is too long.');
    }
    $termId = spacefast_content_storage_folder_term($spaceId, $input['folder'] ?? null, true);
    if ($termId === null) {
        return spacefast_content_storage_error(400, 'zero_storage_folder_invalid', 'The folder path is not usable.');
    }
    $temporary = wp_tempnam($filename);
    if (!is_string($temporary) || $temporary === '' || file_put_contents($temporary, $bytes) === false) {
        return spacefast_content_storage_error(503, 'zero_storage_upload_failed', 'The file could not be staged.');
    }
    $sideloaded = wp_handle_sideload(
        ['name' => $filename, 'tmp_name' => $temporary, 'size' => strlen($bytes), 'error' => 0],
        ['test_form' => false]
    );
    if (!is_array($sideloaded) || isset($sideloaded['error']) || !isset($sideloaded['file'])) {
        if (file_exists($temporary)) {
            unlink($temporary);
        }
        // WordPress refuses the type, not us: the allowed-mime list is core's.
        return spacefast_content_storage_error(415, 'zero_storage_type_refused', 'WordPress does not accept this file type.');
    }
    $attachmentId = (int) wp_insert_attachment([
        'post_mime_type' => (string) ($sideloaded['type'] ?? ''),
        'post_title' => $title === '' ? $filename : $title,
        'post_content' => '',
        'post_status' => 'inherit',
    ], (string) $sideloaded['file']);
    if ($attachmentId < 1) {
        return spacefast_content_storage_error(503, 'zero_storage_upload_failed', 'The file could not be stored.');
    }
    if (function_exists('wp_generate_attachment_metadata') && function_exists('wp_update_attachment_metadata')) {
        wp_update_attachment_metadata(
            $attachmentId,
            wp_generate_attachment_metadata($attachmentId, (string) $sideloaded['file'])
        );
    }
    spacefast_content_storage_apply_alt($attachmentId, $input);
    if ($termId > 0 && function_exists('wp_set_object_terms')) {
        wp_set_object_terms($attachmentId, [$termId], SPACEFAST_CONTENT_STORAGE_TAXONOMY, false);
    }
    return spacefast_content_storage_projection($attachmentId);
}

function spacefast_content_storage_apply_alt(int $attachmentId, array $input): void
{
    $alt = trim((string) ($input['alt'] ?? ''));
    if ($alt !== '' && strlen($alt) <= SPACEFAST_CONTENT_STORAGE_ALT_MAX_BYTES && function_exists('update_post_meta')) {
        update_post_meta($attachmentId, SPACEFAST_CONTENT_STORAGE_ALT_META, $alt);
    }
}

/** Moves one file to a folder. An empty path moves it back to the Space root. */
function spacefast_content_storage_move(mixed $input): mixed
{
    $attachmentId = spacefast_content_storage_resolve($input);
    if ($attachmentId === 0) {
        return spacefast_content_storage_error(404, 'zero_storage_not_found', 'No such file belongs to this Space.');
    }
    $input = spacefast_content_storage_input($input);
    $termId = spacefast_content_storage_folder_term(
        spacefast_content_space_id(),
        $input['folder'] ?? null,
        true
    );
    if ($termId === null) {
        return spacefast_content_storage_error(400, 'zero_storage_folder_invalid', 'The folder path is not usable.');
    }
    if (!function_exists('wp_set_object_terms')) {
        return spacefast_content_storage_error(503, 'zero_storage_unavailable', 'Space storage is unavailable.');
    }
    wp_set_object_terms(
        $attachmentId,
        $termId > 0 ? [$termId] : [],
        SPACEFAST_CONTENT_STORAGE_TAXONOMY,
        false
    );
    return spacefast_content_storage_projection($attachmentId);
}

/**
 * Trashes one file.
 *
 * WordPress's own soft delete, not a second one: the attachment leaves every
 * listing and its bytes stay on disk, recoverable by WP's untrash. Nothing here
 * removes a file permanently.
 */
function spacefast_content_storage_delete(mixed $input): mixed
{
    $attachmentId = spacefast_content_storage_resolve($input);
    if ($attachmentId === 0) {
        return spacefast_content_storage_error(404, 'zero_storage_not_found', 'No such file belongs to this Space.');
    }
    if (!function_exists('wp_trash_post')) {
        return spacefast_content_storage_error(503, 'zero_storage_unavailable', 'Space storage is unavailable.');
    }
    $trashed = wp_trash_post($attachmentId);
    if (empty($trashed)) {
        return spacefast_content_storage_error(503, 'zero_storage_delete_failed', 'The file could not be trashed.');
    }
    return ['id' => $attachmentId, 'trashed' => true];
}

/* -------------------------------------------------------------------------- */
/* Abilities                                                                   */
/* -------------------------------------------------------------------------- */

function spacefast_content_storage_object_schema(array $properties, array $required = []): array
{
    return [
        'type' => 'object',
        'properties' => $properties,
        'required' => $required,
        'additionalProperties' => false,
    ];
}

function spacefast_content_storage_projection_schema(): array
{
    return spacefast_content_storage_object_schema([
        'id' => ['type' => 'integer'],
        'title' => ['type' => 'string'],
        'filename' => ['type' => 'string'],
        'url' => ['type' => 'string'],
        'mimeType' => ['type' => 'string'],
        'alt' => ['type' => 'string'],
        'folder' => ['type' => 'string'],
        'width' => ['type' => ['integer', 'null']],
        'height' => ['type' => ['integer', 'null']],
        'filesize' => ['type' => ['integer', 'null']],
        'uploadedAt' => ['type' => 'string'],
    ], ['id', 'title', 'filename', 'url', 'mimeType', 'alt', 'folder', 'uploadedAt']);
}

/**
 * The `zero/storage/*` set, keyed by the name the Abilities API publishes each
 * one under.
 *
 * WP_Abilities_Registry::register() accepts `^[a-z0-9-]+/[a-z0-9-]+$` — one
 * namespace, one slug, no further slashes — so the set's `zero/storage/…` name
 * is spelled `zero/storage-…` on the wire. The MCP adapter renames the
 * separator again, publishing them as `zero-storage-…` tools.
 */
function spacefast_content_storage_abilities(): array
{
    $file = spacefast_content_storage_projection_schema();
    $folder = [
        'type' => 'string',
        'description' => 'A slash-separated folder path, or empty for the Space root.',
    ];
    return [
        'zero/storage-list' => [
            'label' => 'List Space files',
            'description' => 'Lists the files stored in this Space, optionally within one folder.',
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            // Listing takes no required input, and the Abilities API validates a
            // bare call's null input against this schema before executing it.
            'input_schema' => [
                'default' => [],
                ...spacefast_content_storage_object_schema([
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'perPage' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => SPACEFAST_CONTENT_STORAGE_PAGE_SIZE_MAX,
                    ],
                    'folder' => $folder,
                    'recursive' => ['type' => 'boolean'],
                    'search' => ['type' => 'string'],
                    'mimeType' => ['type' => 'string'],
                ]),
            ],
            'output_schema' => spacefast_content_storage_object_schema([
                'page' => ['type' => 'integer'],
                'perPage' => ['type' => 'integer'],
                'files' => ['type' => 'array', 'items' => $file],
            ], ['page', 'perPage', 'files']),
            'permission_callback' => static fn (): bool => spacefast_content_storage_may('upload_files'),
            'execute_callback' => 'spacefast_content_storage_list',
        ],
        'zero/storage-get' => [
            'label' => 'Get a Space file',
            'description' => 'Reads one file stored in this Space.',
            'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            'input_schema' => spacefast_content_storage_object_schema([
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['id']),
            'output_schema' => $file,
            'permission_callback' => static fn (): bool => spacefast_content_storage_may('upload_files'),
            'execute_callback' => 'spacefast_content_storage_get',
        ],
        'zero/storage-upload' => [
            'label' => 'Upload a Space file',
            'description' =>
                'Stores a file in this Space from base64 content, optionally in a folder. '
                . 'WordPress decides which file types are accepted.',
            // Two uploads of one file are two files: WordPress uniquifies the
            // name rather than replacing what is there.
            'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            'input_schema' => spacefast_content_storage_object_schema([
                'filename' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => SPACEFAST_CONTENT_STORAGE_FILENAME_MAX_BYTES,
                ],
                'contentBase64' => ['type' => 'string', 'minLength' => 1],
                'title' => ['type' => 'string', 'maxLength' => SPACEFAST_CONTENT_STORAGE_TITLE_MAX_BYTES],
                'alt' => ['type' => 'string', 'maxLength' => SPACEFAST_CONTENT_STORAGE_ALT_MAX_BYTES],
                'folder' => $folder,
            ], ['filename', 'contentBase64']),
            'output_schema' => $file,
            'permission_callback' => static fn (): bool => spacefast_content_storage_may('upload_files'),
            'execute_callback' => 'spacefast_content_storage_upload',
        ],
        'zero/storage-move' => [
            'label' => 'Move a Space file',
            'description' => 'Moves one file into a folder, creating the folder path if needed.',
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'input_schema' => spacefast_content_storage_object_schema([
                'id' => ['type' => 'integer', 'minimum' => 1],
                'folder' => $folder,
            ], ['id', 'folder']),
            'output_schema' => $file,
            'permission_callback' => static fn (mixed $input): bool => spacefast_content_storage_may(
                'edit_post',
                (int) (spacefast_content_storage_input($input)['id'] ?? 0)
            ),
            'execute_callback' => 'spacefast_content_storage_move',
        ],
        'zero/storage-delete' => [
            'label' => 'Delete a Space file',
            'description' =>
                'Trashes one file in this Space. The file leaves every listing and stays recoverable; '
                . 'nothing here removes it permanently.',
            'annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            'input_schema' => spacefast_content_storage_object_schema([
                'id' => ['type' => 'integer', 'minimum' => 1],
            ], ['id']),
            'output_schema' => spacefast_content_storage_object_schema([
                'id' => ['type' => 'integer'],
                'trashed' => ['type' => 'boolean'],
            ], ['id', 'trashed']),
            'permission_callback' => static fn (mixed $input): bool => spacefast_content_storage_may(
                'delete_post',
                (int) (spacefast_content_storage_input($input)['id'] ?? 0)
            ),
            'execute_callback' => 'spacefast_content_storage_delete',
        ],
    ];
}

/**
 * The content endpoint's door onto the same abilities.
 *
 * Deliberately routed through the ability definitions rather than calling the
 * execute functions directly: the permission_callback is the authorization, so
 * reaching the same operation from a Zero handler has to run the same check the
 * Abilities API would. One definition, two dispatchers — the alternative is a
 * second capability list that drifts.
 */
function spacefast_content_storage_dispatch(string $operation, array $request): array
{
    $names = [
        'storage.list' => 'zero/storage-list',
        'storage.get' => 'zero/storage-get',
        'storage.delete' => 'zero/storage-delete',
    ];
    $ability = spacefast_content_storage_abilities()[$names[$operation] ?? ''] ?? null;
    if ($ability === null) {
        throw new Spacefast_Content_Error(400, 'content_operation_invalid', 'The content operation is not supported.');
    }
    unset($request['operation']);
    if (($ability['permission_callback'])($request) !== true) {
        throw new Spacefast_Content_Error(403, 'zero_storage_forbidden', 'This principal may not reach Space storage.');
    }
    $result = ($ability['execute_callback'])($request);
    if (function_exists('is_wp_error') && is_wp_error($result)) {
        throw new Spacefast_Content_Error(
            (int) ($result->data['status'] ?? 500),
            (string) $result->code,
            (string) $result->message
        );
    }
    return is_array($result) ? $result : [];
}

function spacefast_content_storage_register_ability_category(): void
{
    if (function_exists('wp_register_ability_category') && spacefast_content_space_id() !== '') {
        wp_register_ability_category(SPACEFAST_CONTENT_STORAGE_ABILITY_CATEGORY, [
            'label' => 'Space storage',
            'description' => "A Space's files.",
        ]);
    }
}

function spacefast_content_storage_register_abilities(): void
{
    if (!function_exists('wp_register_ability') || spacefast_content_space_id() === '') {
        return;
    }
    foreach (spacefast_content_storage_abilities() as $name => $ability) {
        wp_register_ability($name, [
            'label' => $ability['label'],
            'description' => $ability['description'],
            'category' => SPACEFAST_CONTENT_STORAGE_ABILITY_CATEGORY,
            'input_schema' => $ability['input_schema'],
            'output_schema' => $ability['output_schema'],
            'permission_callback' => $ability['permission_callback'],
            'execute_callback' => $ability['execute_callback'],
            // `public` is what carries these to clients — the REST surface and,
            // through the MCP adapter, agents. Agent parity is this one
            // registration rather than a second surface to keep in step.
            'meta' => ['public' => true, 'annotations' => $ability['annotations']],
        ]);
    }
}
