<?php
/**
 * Native projection of an immutable spacefast.zero-wordpress ContentModelRelease.
 *
 * The authored capsule and its compiled ContentModelRelease are the authority. This
 * file only projects that release into WordPress's native content, Tables,
 * Blocks, SCF and Abilities surfaces.
 */
declare(strict_types=1);

const SPACEFAST_CONTENT_MODEL_FORMAT = 'spacefast.zero-wordpress';
const SPACEFAST_CONTENT_MODEL_VERSION = 1;
const SPACEFAST_CONTENT_MODEL_PHP_FORMAT = 'spacefast.wordpress-content-model.php';
const SPACEFAST_CONTENT_MODEL_REVISION_PATTERN = '/^sha256:[a-f0-9]{64}$/';
// A staging ceiling, not the caching one. The fleet runs
// opcache.max_file_size = 1 MiB (see shared/pointers.php), so a content model past
// that is still correct but is re-parsed on every request that boots WordPress
// instead of being served from SHM. Content models are refused only well past the
// point where anything else would have broken first; the 1 MiB line is where
// the bytecode win quietly stops.
const SPACEFAST_CONTENT_MODEL_PHP_MAX_BYTES = 4194304;
const SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY = 'zero_collection';
// A Space publishes flat slugs, so WordPress's permalinks say the same thing.
const SPACEFAST_CONTENT_MODEL_PERMALINK_STRUCTURE = '/%postname%/';
// The theme the engine installs, and the only one a managed site renders through.
const SPACEFAST_CONTENT_MODEL_MANAGED_THEME = 'spacefast-managed';
const SPACEFAST_CONTENT_MODEL_PAGE_SOURCE_META = '_zero_page_source_key';

function spacefast_content_model_root(string $privateRoot, string $spaceId): string
{
    return rtrim($privateRoot, '/') . '/spaces/' . $spaceId . '/content-model';
}

function spacefast_content_model_revision_directory(string $revision): string
{
    return substr($revision, strlen('sha256:'));
}

function spacefast_content_model_is_stable_id(mixed $value): bool
{
    return is_string($value)
        && strlen($value) >= 1
        && strlen($value) <= 160
        && preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $value) === 1;
}

function spacefast_content_model_legacy_release_present(string $releaseRoot): bool
{
    return is_file($releaseRoot . '/schema.json')
        || is_file($releaseRoot . '/payloadwp.php')
        || is_file($releaseRoot . '/content-model.json');
}

function spacefast_content_model_republish_required(): never
{
    throw new Spacefast_Content_Error(
        409,
        'content_model_republish_required',
        'This content release uses the retired Payload format. Republish the Space to activate its WordPress content model.'
    );
}

/** @return array<string,mixed> */
function spacefast_content_model_read_release(string $releaseRoot, ?string $expectedRevision = null): array
{
    $contentModelFile = $releaseRoot . '/content-model.php';
    if (!is_file($contentModelFile)) {
        if (spacefast_content_model_legacy_release_present($releaseRoot)) {
            spacefast_content_model_republish_required();
        }
        throw new Spacefast_Content_Error(409, 'content_model_not_found', 'The content ContentModelRelease is incomplete.');
    }
    $artifactDigest = _stattic_private_tree_read_pointer($releaseRoot . '/content-model.sha256', 72);
    $bytes = filesize($contentModelFile);
    if (
        !is_int($bytes)
        || $bytes < 1
        || $bytes > SPACEFAST_CONTENT_MODEL_PHP_MAX_BYTES
        || $artifactDigest === null
        || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $artifactDigest) !== 1
    ) {
        throw new Spacefast_Content_Error(409, 'content_model_not_found', 'The content ContentModelRelease is incomplete.');
    }
    if (!hash_equals($artifactDigest, 'sha256:' . hash_file('sha256', $contentModelFile))) {
        throw new Spacefast_Content_Error(409, 'content_model_digest_mismatch', 'The ContentModelRelease artifact digest is invalid.');
    }
    $contentModel = require $contentModelFile;
    if (
        !is_array($contentModel)
        || ($contentModel['format'] ?? null) !== SPACEFAST_CONTENT_MODEL_PHP_FORMAT
        || ($contentModel['version'] ?? null) !== SPACEFAST_CONTENT_MODEL_VERSION
        || !is_string($contentModel['revision'] ?? null)
        || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $contentModel['revision']) !== 1
        || !is_array($contentModel['postTypes'] ?? null)
        || !is_array($contentModel['scfFieldGroups'] ?? null)
        // Custom Tables, Abilities and hooks are retired. A release still
        // carries the three fields, always empty; one that fills them is refused
        // rather than activated without them.
        || ($contentModel['tables'] ?? null) !== []
        || !is_array($contentModel['pages'] ?? null)
        || ($contentModel['abilities'] ?? null) !== []
        || ($contentModel['hooks'] ?? null) !== []
        || !is_array($contentModel['syncBindings'] ?? null)
    ) {
        throw new Spacefast_Content_Error(422, 'content_model_invalid', 'The generated PHP ContentModelRelease is invalid.');
    }
    if ($expectedRevision !== null && !hash_equals($expectedRevision, $contentModel['revision'])) {
        throw new Spacefast_Content_Error(409, 'content_model_digest_mismatch', 'The ContentModelRelease revision does not match its immutable location.');
    }
    return $contentModel;
}

/** @return array<string,mixed>|null */
function spacefast_content_model_active_release(): ?array
{
    static $cachedRoot = null;
    static $cachedRevision = null;
    static $cachedContentModel = null;
    $root = $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] ?? null;
    $revision = $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] ?? null;
    if (!is_string($root) || !is_string($revision)) {
        return null;
    }
    if ($cachedRoot === $root && $cachedRevision === $revision && is_array($cachedContentModel)) {
        return $cachedContentModel;
    }
    $cachedContentModel = spacefast_content_model_read_release($root, $revision);
    $cachedRoot = $root;
    $cachedRevision = $revision;
    return $cachedContentModel;
}

function spacefast_content_model_collection_term_slug(string $spaceId, string $resourceId): string
{
    return 'sf-' . substr(hash('sha256', $spaceId), 0, 16) . '-' . str_replace(['.', '_'], '-', $resourceId);
}

/** @return array<string,mixed>|null */
function spacefast_content_model_resource(string $resourceId): ?array
{
    foreach (spacefast_content_model_resources() as $resource) {
        if (is_array($resource) && ($resource['id'] ?? null) === $resourceId) {
            return $resource;
        }
    }
    return null;
}

function spacefast_content_model_resources(): array
{
    $contentModel = spacefast_content_model_active_release();
    return is_array($contentModel['postTypes'] ?? null) ? $contentModel['postTypes'] : [
        ['id' => 'posts', 'label' => 'Posts', 'kind' => 'builtin', 'postType' => 'post', 'publicRead' => true, 'fields' => []],
        ['id' => 'pages', 'label' => 'Pages', 'kind' => 'builtin', 'postType' => 'page', 'publicRead' => true, 'fields' => []],
        ['id' => 'media', 'label' => 'Media', 'kind' => 'media', 'postType' => 'attachment', 'publicRead' => true, 'fields' => []],
    ];
}

/** @return array{name:string,post_type:string,collection_term:?string}|null */
function spacefast_content_model_collection_projection(string $resourceId): ?array
{
    $resource = spacefast_content_model_resource($resourceId);
    if ($resource === null) {
        return null;
    }
    return [
        'name' => $resourceId,
        'post_type' => $resource['postType'],
        'collection_term' => $resource['kind'] === 'collection'
            ? spacefast_content_model_collection_term_slug(spacefast_content_require_space_id(), $resourceId)
            : null,
    ];
}

/** @return array{resourceId:string,fieldId:string,source:string,format:string,slug:string,post_type:string,field_storage:string}|null */
function spacefast_content_model_sync_binding(string $bindingId): ?array
{
    $contentModel = spacefast_content_model_active_release();
    foreach (is_array($contentModel['syncBindings'] ?? null) ? $contentModel['syncBindings'] : [] as $binding) {
        if (is_array($binding) && ($binding['id'] ?? null) === $bindingId) {
            return [
                'resourceId' => $binding['resourceId'],
                'fieldId' => $binding['fieldId'],
                'source' => $binding['source'],
                // A ContentModelRelease compiled before the HTML sync format
                // carried no `format`: the only serializer then was Markdown, so
                // a format-less binding is a Markdown one. Defaulting here keeps
                // an already-bound Markdown space reconciling across the deploy
                // instead of failing parse_reconcile as an unknown format.
                'format' => $binding['format'] ?? 'md',
                'slug' => $binding['slug'],
                'publicPath' => $binding['publicPath'] ?? null,
                'post_type' => $binding['postType'],
                'field_storage' => $binding['fieldStorage'],
                'compiled' => $binding['compiled'] ?? null,
                'componentSource' => $binding['componentSource'] ?? null,
                'documentSeed' => $binding['documentSeed'] ?? null,
            ];
        }
    }
    return null;
}

/**
 * Where a document this resource holds — one WordPress created, that no file
 * backs — puts its bytes when it becomes a source file.
 *
 * Read defensively, like a binding's `format`: a ContentModelRelease compiled
 * before materializations existed carries no such key, and a Space on one is
 * simply a Space where nothing materializes. That is a missing capability, never
 * a broken release.
 *
 * @return array{resourceId:string,fieldId:string,directory:string,suffix:string,format:string,post_type:string,field_storage:string}|null
 */
function spacefast_content_model_materialization(string $resourceId): ?array
{
    $contentModel = spacefast_content_model_active_release();
    $declared = is_array($contentModel['materializations'] ?? null) ? $contentModel['materializations'] : [];
    foreach ($declared as $materialization) {
        if (!is_array($materialization) || ($materialization['resourceId'] ?? null) !== $resourceId) {
            continue;
        }
        $resolved = [
            'resourceId' => $materialization['resourceId'] ?? null,
            'fieldId' => $materialization['fieldId'] ?? null,
            'directory' => $materialization['directory'] ?? null,
            'suffix' => $materialization['suffix'] ?? null,
            'format' => $materialization['format'] ?? null,
            'post_type' => $materialization['postType'] ?? null,
            'field_storage' => $materialization['fieldStorage'] ?? null,
        ];
        foreach ($resolved as $value) {
            if (!is_string($value) || $value === '') {
                return null;
            }
        }
        return $resolved;
    }
    return null;
}

function spacefast_content_model_register_wordpress_projection(): void
{
    if (function_exists('register_taxonomy')) {
        register_taxonomy(SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, ['post'], [
            'labels' => ['name' => 'Collections', 'singular_name' => 'Collection'],
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => true,
            'hierarchical' => false,
            'capabilities' => ['manage_terms' => 'do_not_allow', 'edit_terms' => 'do_not_allow', 'delete_terms' => 'do_not_allow', 'assign_terms' => 'edit_posts'],
            'rewrite' => false,
        ]);
    }
    if (function_exists('register_block_type')) {
        register_block_type('zero/component', [
            'api_version' => 3,
            'attributes' => [
                'sourceKey' => ['type' => 'string'],
                'componentId' => ['type' => 'string'],
                'props' => ['type' => 'object'],
                'lock' => ['type' => 'object'],
            ],
            'supports' => ['html' => false, 'reusable' => false],
            'render_callback' => 'spacefast_content_model_render_component_block',
        ]);
    }
    $contentModel = spacefast_content_model_active_release();
    if ($contentModel === null) {
        return;
    }
    spacefast_content_model_register_rest_meta($contentModel);
}

function spacefast_content_model_render_component_block(array $attributes): string
{
    $componentId = is_string($attributes['componentId'] ?? null) ? $attributes['componentId'] : '';
    $sourceKey = is_string($attributes['sourceKey'] ?? null) ? $attributes['sourceKey'] : '';
    if (!spacefast_content_model_is_stable_id($componentId) || $sourceKey === '') {
        return '';
    }
    $props = json_encode($attributes['props'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($props)) {
        return '';
    }
    return '<div data-zero-component="' . esc_attr($componentId) . '" data-zero-source="'
        . esc_attr($sourceKey) . '" data-zero-props="' . esc_attr($props) . '"></div>';
}

function spacefast_content_model_register_rest_meta(array $contentModel): void
{
    if (!function_exists('register_post_meta')) {
        return;
    }
    foreach ($contentModel['postTypes'] as $resource) {
        add_filter('rest_pre_insert_' . $resource['postType'], 'spacefast_content_model_preserve_absent_meta', 10, 2);
        add_filter('rest_pre_insert_' . $resource['postType'], 'spacefast_content_sync_prepare_editor_save', 20, 2);
        add_filter('rest_prepare_' . $resource['postType'], 'spacefast_content_model_project_meta', 10, 2);
        foreach ($resource['fields'] as $field) {
            if (!empty($field['native'])) {
                continue;
            }
            $schema = $field['restSchema'];
            register_post_meta($resource['postType'], $field['storageName'], [
                'single' => true,
                'type' => $schema['type'],
                'show_in_rest' => ['schema' => $schema],
                ...($schema['type'] === 'boolean' ? [
                    'sanitize_callback' => static fn (mixed $value): string => rest_sanitize_boolean($value) ? '1' : '0',
                ] : []),
                // Meta never passes core's kses save filters, whoever writes it.
                ...(spacefast_content_model_field_is_html($field) ? [
                    'sanitize_callback' => static fn (mixed $value): string => wp_kses_post((string) $value),
                ] : []),
                'auth_callback' => static fn (bool $allowed, string $key, int $postId): bool =>
                    spacefast_content_post_belongs_to_space($postId) && current_user_can('edit_post', $postId)
                    && (spacefast_content_collection_for_post($postId)['name'] ?? null) === $resource['id'],
            ]);
        }
    }
}

/** A rich-text field holds HTML that every editor of the document renders. */
function spacefast_content_model_field_is_html(array $field): bool
{
    return ($field['definition']['type'] ?? null) === 'richText';
}

/**
 * WordPress scalar defaults must not turn an absent optional value into zero,
 * and rich text is sanitized on read too, so a stored value that skipped the
 * write sanitizer still reads back clean.
 */
function spacefast_content_model_project_meta(mixed $response, mixed $post): mixed
{
    if (!($response instanceof WP_REST_Response)) {
        return $response;
    }
    $data = $response->get_data();
    if (!is_array($data) || !is_array($data['meta'] ?? null)) {
        return $response;
    }
    $resourceId = spacefast_content_collection_for_post((int) $post->ID)['name'] ?? null;
    foreach (spacefast_content_model_resources() as $resource) {
        if ($resource['id'] !== $resourceId) {
            continue;
        }
        foreach ($resource['fields'] as $field) {
            $key = $field['storageName'];
            if (!empty($field['native']) || !array_key_exists($key, $data['meta'])) {
                continue;
            }
            if (empty($field['definition']['required']) && !metadata_exists('post', (int) $post->ID, $key)) {
                $data['meta'][$key] = null;
            } elseif (spacefast_content_model_field_is_html($field) && is_string($data['meta'][$key])) {
                $data['meta'][$key] = wp_kses_post($data['meta'][$key]);
            }
        }
    }
    $response->set_data($data);
    return $response;
}

/** Gutenberg sends the full meta object; an unset optional value remains absent. */
function spacefast_content_model_preserve_absent_meta(mixed $post, mixed $request): mixed
{
    $meta = $request['meta'] ?? null;
    if (!is_array($meta)) {
        return $post;
    }
    $postId = (int) ($request['id'] ?? 0);
    foreach (spacefast_content_model_resources() as $resource) {
        foreach ($resource['fields'] as $field) {
            if (!empty($field['native'])) {
                continue;
            }
            $key = $field['storageName'];
            if (empty($field['definition']['required'])
                && array_key_exists($key, $meta) && $meta[$key] === null
                && ($postId === 0 || !metadata_exists('post', $postId, $key))) {
                unset($meta[$key]);
            }
        }
    }
    $request['meta'] = $meta;
    return $post;
}

function spacefast_content_model_validate_reference_value(array $definition, mixed $value): bool
{
    if (!function_exists('get_post')) {
        return false;
    }
    $resourceIds = ($definition['type'] ?? null) === 'media' ? ['media']
        : (is_array($definition['collections'] ?? null) ? $definition['collections'] : [$definition['collection'] ?? null]);
    $ids = is_array($value) ? $value : [$value];
    foreach ($ids as $id) {
        if ((int) $id < 1) continue;
        if (!spacefast_content_post_belongs_to_space((int) $id)
            || !in_array(spacefast_content_collection_for_post((int) $id)['name'] ?? null, $resourceIds, true)) {
            return false;
        }
    }
    return array_filter($resourceIds, 'is_string') !== [];
}

function spacefast_content_model_ensure_collection_terms(array $contentModel): void
{
    if (!function_exists('term_exists') || !function_exists('wp_insert_term')) {
        return;
    }
    $spaceId = spacefast_content_require_space_id();
    foreach ($contentModel['postTypes'] as $resource) {
        if (($resource['kind'] ?? null) !== 'collection') {
            continue;
        }
        $slug = spacefast_content_model_collection_term_slug($spaceId, $resource['id']);
        $term = term_exists($slug, SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY);
        if (!$term) {
            $term = wp_insert_term($resource['label'], SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, ['slug' => $slug]);
        }
        if (function_exists('is_wp_error') && is_wp_error($term)) {
            throw new Spacefast_Content_Error(500, 'content_model_projection_failed', 'A collection term could not be projected.');
        }
        $termId = is_array($term) ? (int) ($term['term_id'] ?? 0) : (int) $term;
        if ($termId > 0 && function_exists('update_term_meta')) {
            update_term_meta($termId, '_zero_resource_id', $resource['id']);
            update_term_meta($termId, SPACEFAST_CONTENT_SPACE_META, $spaceId);
        }
    }
}

function spacefast_content_model_register_scf_field_groups(): void
{
    if (!function_exists('acf_add_local_field_group')) {
        return;
    }
    $contentModel = spacefast_content_model_active_release();
    if ($contentModel === null) {
        return;
    }
    $spaceId = spacefast_content_require_space_id();
    foreach ($contentModel['scfFieldGroups'] as $group) {
        $fields = [];
        foreach ($group['fields'] as $field) {
            $definition = $field['definition'];
            $definition['name'] = $definition['storageName'];
            $fields[] = spacefast_content_scf_field('zero:' . $group['resourceId'], $field['name'], $definition);
        }
        acf_add_local_field_group([
            'key' => spacefast_content_scf_key('group', 'zero:' . $group['resourceId']),
            'title' => $group['title'],
            'fields' => $fields,
            'location' => [[[
                'param' => 'post_taxonomy',
                'operator' => '==',
                'value' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY . ':'
                    . spacefast_content_model_collection_term_slug($spaceId, $group['resourceId']),
            ]]],
            'show_in_rest' => 1,
            'allow_ai_access' => 0,
            'active' => true,
        ]);
    }
}

/** Stage verified generated PHP without changing the live Space pointer. */
function spacefast_content_model_stage_release(
    mixed $revision,
    mixed $contentModelPhp,
    mixed $artifactDigest
): array
{
    if (!is_string($revision) || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $revision) !== 1) {
        throw new Spacefast_Content_Error(400, 'content_model_revision_invalid', 'The ContentModelRelease revision is invalid.');
    }
    if (
        !is_string($contentModelPhp)
        || $contentModelPhp === ''
        || strlen($contentModelPhp) > SPACEFAST_CONTENT_MODEL_PHP_MAX_BYTES
        || !is_string($artifactDigest)
        || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $artifactDigest) !== 1
    ) {
        throw new Spacefast_Content_Error(400, 'content_model_digest_invalid', 'The ContentModelRelease artifact digest is invalid.');
    }
    if (!hash_equals($artifactDigest, 'sha256:' . hash('sha256', $contentModelPhp))) {
        throw new Spacefast_Content_Error(409, 'content_model_digest_mismatch', 'The ContentModelRelease digest is invalid.');
    }
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    if (!is_string($privateRoot) || $privateRoot === '') {
        throw new Spacefast_Content_Error(503, 'content_model_storage_unavailable', 'ContentModelRelease storage is unavailable.');
    }
    $contentModelRoot = spacefast_content_model_root($privateRoot, spacefast_content_require_space_id());
    $releasesRoot = $contentModelRoot . '/releases';
    if (!is_dir($releasesRoot) && !_sf_mkdir_racing($releasesRoot, 0750)) {
        throw new Spacefast_Content_Error(503, 'content_model_storage_unavailable', 'ContentModelRelease storage is unavailable.');
    }
    $releaseRoot = $releasesRoot . '/' . spacefast_content_model_revision_directory($revision);
    if (is_dir($releaseRoot)) {
        spacefast_content_model_read_release($releaseRoot, $revision);
        return ['revision' => $revision, 'artifactDigest' => $artifactDigest, 'staged' => false];
    }
    $stageRoot = $contentModelRoot . '/stage-' . bin2hex(random_bytes(12));
    if (!mkdir($stageRoot, 0750, true)) {
        throw new Spacefast_Content_Error(503, 'content_model_storage_unavailable', 'ContentModelRelease staging is unavailable.');
    }
    try {
        if (file_put_contents($stageRoot . '/content-model.php', $contentModelPhp, LOCK_EX) !== strlen($contentModelPhp)
            || !chmod($stageRoot . '/content-model.php', 0640)
            || !_stattic_private_tree_write_pointer($stageRoot . '/content-model.sha256', $artifactDigest)) {
            throw new Spacefast_Content_Error(500, 'content_model_stage_failed', 'The ContentModelRelease could not be staged.');
        }
        spacefast_content_model_read_release($stageRoot, $revision);
        if (!rename($stageRoot, $releaseRoot)) {
            if (!is_dir($releaseRoot)) {
                throw new Spacefast_Content_Error(500, 'content_model_stage_failed', 'The ContentModelRelease could not be published.');
            }
            spacefast_content_model_read_release($releaseRoot, $revision);
        }
    } finally {
        _stattic_private_tree_remove($stageRoot);
    }
    return ['revision' => $revision, 'artifactDigest' => $artifactDigest, 'staged' => true];
}

function spacefast_content_model_page_link(string $link, int $postId, bool $sample = false): string
{
    if (!function_exists('get_post_meta') || !function_exists('home_url')
        || !spacefast_content_post_belongs_to_space($postId)) {
        return $link;
    }
    $bindingId = spacefast_content_source_journal_binding_id($postId);
    if ($bindingId === null) {
        return $link;
    }
    $binding = spacefast_content_model_sync_binding($bindingId);
    $path = $binding['publicPath'] ?? null;
    return ($binding['post_type'] ?? null) === 'page' && is_string($path)
        ? home_url($sample ? spacefast_content_permalink_template($path, '%pagename%') : $path)
        : $link;
}

/** Activate only after immutable verification and every local projection succeeds. */
/**
 * WordPress's own permalinks, agreeing with the URLs a Space actually publishes.
 *
 * A Space serves its content at a flat `/<slug>`: that is the path the serving
 * lane resolves, the path the CLI prints, and the path a reader is given. A
 * managed site nevertheless comes up with the provider's default dated
 * structure, so WordPress computed a different canonical URL for the same post
 * and 301'd every bare slug to `/<year>/<month>/<day>/<slug>/`. Making the
 * structure flat is what stops those two answers from disagreeing at the source,
 * rather than papering over one of them.
 *
 * Idempotent, and only ever a write when the two disagree: activation re-runs on
 * every publish, promote and rollback.
 */
function spacefast_content_model_ensure_flat_permalinks(): void
{
    if (!function_exists('get_option') || !function_exists('update_option')) {
        return;
    }
    if ((string) get_option('permalink_structure') === SPACEFAST_CONTENT_MODEL_PERMALINK_STRUCTURE) {
        return;
    }
    $rewrite = $GLOBALS['wp_rewrite'] ?? null;
    if (is_object($rewrite) && method_exists($rewrite, 'set_permalink_structure')) {
        $rewrite->set_permalink_structure(SPACEFAST_CONTENT_MODEL_PERMALINK_STRUCTURE);
    } else {
        update_option('permalink_structure', SPACEFAST_CONTENT_MODEL_PERMALINK_STRUCTURE);
    }
    // Setting the structure only re-reads it. The rules WordPress actually
    // routes requests with are a separate, derived `rewrite_rules` option, and
    // until they are rebuilt they still describe the dated structure — which
    // matches no flat slug, so every published post 404s. A soft flush is the
    // whole job: there is no .htaccess on this stack to rewrite.
    if (is_object($rewrite) && method_exists($rewrite, 'flush_rules')) {
        $rewrite->flush_rules(false);
    }
}

/**
 * The theme a managed site renders through, actually active.
 *
 * A managed site comes up pointing at the provider's default theme, which is not
 * installed on the box — so WordPress resolved every template to nothing and
 * rendered a 200 with an empty body. The engine ships `spacefast-managed` and
 * the rest of the system already assumes it: the templates a release implies are
 * supplied against it, and the page lane falls back to its `templates/index.html`.
 * Activation is where that assumption becomes true.
 *
 * Idempotent, and never a switch away from a theme that is already the managed
 * one: activation re-runs on every publish, promote and rollback.
 */
function spacefast_content_model_ensure_managed_theme(): void
{
    if (
        !function_exists('get_option')
        || !function_exists('switch_theme')
        || !function_exists('wp_get_theme')
    ) {
        return;
    }
    if ((string) get_option('stylesheet') === SPACEFAST_CONTENT_MODEL_MANAGED_THEME) {
        return;
    }
    $theme = wp_get_theme(SPACEFAST_CONTENT_MODEL_MANAGED_THEME);
    // Only ever a switch TO something that is there. A box mid-engine-install
    // must not be left pointing at a theme it does not yet have — that is the
    // failure this exists to end, not one to reproduce.
    if (!is_object($theme) || !method_exists($theme, 'exists') || !$theme->exists()) {
        return;
    }
    switch_theme(SPACEFAST_CONTENT_MODEL_MANAGED_THEME);
}

/**
 * A page the compiler owns one-to-one with a source route, not a collection
 * entry. The compiler gives exactly those bindings a public path, and the key
 * reads the same on a raw release binding and a resolved one.
 */
function spacefast_content_model_is_canonical_page(array $binding): bool
{
    return is_string($binding['publicPath'] ?? null);
}

/** The verified release carries seed bytes, so activation never guesses a live version root. */
function spacefast_content_model_reconcile_documents(array $contentModel, array $previousStorage = []): array
{
    $documents = [];
    foreach ($contentModel['syncBindings'] as $binding) {
        $canonicalPage = spacefast_content_model_is_canonical_page($binding);
        $collectionPost = isset($binding['documentSeed']);
        if (!$canonicalPage && !$collectionPost) {
            continue;
        }
        $seed = $binding['documentSeed'] ?? null;
        $text = is_array($seed) ? ($seed['text'] ?? null) : null;
        $digest = is_array($seed) ? ($seed['sha256'] ?? null) : null;
        $format = $binding['format'] ?? null;
        if (!is_string($text) || strlen($text) > SPACEFAST_CONTENT_SYNC_MAX_TEXT_BYTES
            || !is_string($digest) || !hash_equals(spacefast_content_sync_digest_text($text), $digest)
            || !in_array($format, ['md', 'html', 'blocks', 'tsx'], true)
            || !is_string($binding['fieldStorage'] ?? null)
            || ($format === 'tsx' && ($binding['compiled']['sha256'] ?? null) !== $digest)) {
            throw new Spacefast_Content_Error(422, 'content_document_seed_invalid', 'A canonical document needs digest-verified seed bytes from its release.');
        }
        $documents[] = spacefast_content_sync_publish_document([
            'bindingId' => $binding['id'],
            'source' => $binding['source'],
            'format' => $format,
            'text' => $text,
            'observedSourceRevision' => $digest,
            'operationId' => 'op_publish' . hash('sha256', $contentModel['revision'] . $binding['id']),
            'binding' => [
                'resourceId' => $binding['resourceId'],
                'fieldId' => $binding['fieldId'],
                'source' => $binding['source'],
                'format' => $format,
                'slug' => $binding['slug'],
                'publicPath' => $binding['publicPath'] ?? null,
                'post_type' => $binding['postType'],
                'field_storage' => $binding['fieldStorage'],
            ],
            'previousFieldStorage' => $previousStorage[$binding['id']] ?? null,
        ]);
    }
    return $documents;
}

function spacefast_content_model_current_release(string $contentModelRoot): ?array
{
    $revision = _stattic_private_tree_read_pointer($contentModelRoot . '/active-release', 128);
    return $revision === null ? null : spacefast_content_model_read_release(
        $contentModelRoot . '/releases/' . spacefast_content_model_revision_directory($revision), $revision
    );
}

function spacefast_content_model_retirement_path(string $root, ?string $revision): string
{
    return $root . '/retirement-' . ($revision === null ? 'none' : spacefast_content_model_revision_directory($revision)) . '.json';
}

function spacefast_content_model_retirement_read(string $root, ?string $revision): array
{
    $path = spacefast_content_model_retirement_path($root, $revision);
    if (!is_file($path)) return ['syncBindings' => []];
    $bytes = _stattic_private_tree_read_pointer($path, SPACEFAST_CONTENT_MODEL_PHP_MAX_BYTES);
    $pending = is_string($bytes) ? json_decode($bytes, true) : null;
    if (!is_array($pending) || !is_array($pending['syncBindings'] ?? null)) {
        throw new Spacefast_Content_Error(503, 'content_retirement_unavailable', 'The pending source retirement inventory could not be read.');
    }
    return $pending;
}

/** Carry removed ownership through interrupted and superseding preparations. */
function spacefast_content_model_prepare_retirement(string $root, ?array $previous, ?array $next): array
{
    $bindings = [];
    foreach ([spacefast_content_model_retirement_read($root, $next['revision'] ?? null),
        spacefast_content_model_retirement_read($root, $previous['revision'] ?? null), $previous] as $inventory) {
        foreach ($inventory['syncBindings'] ?? [] as $binding) $bindings[$binding['id']] = $binding;
    }
    $pending = ['syncBindings' => array_values($bindings)];
    spacefast_content_sync_retire_documents($pending, $next, false);
    if ($bindings !== []) {
        if (!_stattic_private_tree_write_pointer(spacefast_content_model_retirement_path($root, $next['revision'] ?? null), json_encode($pending, JSON_THROW_ON_ERROR))) {
            throw new Spacefast_Content_Error(503, 'content_retirement_unavailable', 'The pending source retirement inventory could not be saved.');
        }
    }
    return $pending;
}

/** Cleanup follows the proven serving switch, never candidate preparation. */
function spacefast_content_model_commit_release(array $request): array
{
    $revision = $request['revision'] ?? null;
    $versionId = $request['versionId'] ?? null;
    if (($revision !== null && (!is_string($revision) || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $revision) !== 1))
        || !is_string($versionId) || preg_match('/\Aver_[A-Za-z0-9]+\z/D', $versionId) !== 1) {
        throw new Spacefast_Content_Error(400, 'content_model_revision_invalid', 'The selected content version is invalid.');
    }
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    if (!is_string($privateRoot) || $privateRoot === '') throw new Spacefast_Content_Error(503, 'content_model_storage_unavailable', 'ContentModelRelease storage is unavailable.');
    $spaceId = spacefast_content_require_space_id();
    require_once dirname(__DIR__) . '/shared/lock.php';
    require_once dirname(__DIR__) . '/shared/content-access.php';
    require_once dirname(__DIR__) . '/runtime/serve.php';
    return _stattic_space_write_lock_with($privateRoot, $spaceId, STATTIC_LOCK_WAIT,
        static fn () => throw new Spacefast_Content_Error(503, 'content_sync_busy', 'The serving version could not be locked.'),
        static fn (): array => spacefast_content_sync_locked(static function () use ($privateRoot, $spaceId, $revision, $versionId): array {
            $routeBytes = _stattic_private_tree_read_pointer(_stattic_route_pointer_path($privateRoot, $spaceId, 'production'), SPACEFAST_CONTENT_MODEL_PHP_MAX_BYTES);
            $route = is_string($routeBytes) ? json_decode($routeBytes, true) : null;
            if (($route['version_id'] ?? null) !== $versionId
                || _stattic_content_version_model_revision($privateRoot, $spaceId, $versionId) !== $revision) {
                throw new Spacefast_Content_Error(409, 'content_model_commit_stale', 'This content version is not the selected production version.');
            }
            $root = spacefast_content_model_root($privateRoot, $spaceId);
            $pending = spacefast_content_model_retirement_read($root, $revision);
            $model = $revision === null ? null : spacefast_content_model_read_release($root . '/releases/' . spacefast_content_model_revision_directory($revision), $revision);
            $retired = spacefast_content_sync_without_journal(static fn (): array => spacefast_content_sync_with_transaction(
                static fn (): array => spacefast_content_sync_retire_documents($pending, $model, true)
            ));
            $pointer = $root . '/active-release';
            if ($revision === null) {
                if (is_file($pointer) && !_stattic_private_tree_remove($pointer)) throw new Spacefast_Content_Error(503, 'content_model_pointer_failed', 'The ContentModelRelease pointer could not be cleared.');
                unset($GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'], $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION']);
            } else {
                if (!_stattic_private_tree_write_pointer($pointer, $revision)) throw new Spacefast_Content_Error(503, 'content_model_pointer_failed', 'The ContentModelRelease pointer could not be switched.');
                $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $root . '/releases/' . spacefast_content_model_revision_directory($revision);
                $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $revision;
            }
            $path = spacefast_content_model_retirement_path($root, $revision);
            if (is_file($path) && !_stattic_private_tree_remove($path)) throw new Spacefast_Content_Error(503, 'content_retirement_unavailable', 'The completed source retirement inventory could not be cleared.');
            spacefast_content_public_routes_refresh();
            foreach ($model['syncBindings'] ?? [] as $declaration) {
                $binding = spacefast_content_model_sync_binding($declaration['id']);
                $post = spacefast_content_sync_find_post($declaration['id'], $binding, false);
                if (is_object($post)) spacefast_content_source_journal_record_save((int) $post->ID);
            }
            return ['revision' => $revision, 'versionId' => $versionId, 'retired' => count($retired)];
        })
    );
}

function spacefast_content_model_activate_release(mixed $revision): array
{
    if ($revision !== null && (!is_string($revision) || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $revision) !== 1)) {
        throw new Spacefast_Content_Error(400, 'content_model_revision_invalid', 'The ContentModelRelease revision is invalid.');
    }
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    if (!is_string($privateRoot) || $privateRoot === '') {
        throw new Spacefast_Content_Error(503, 'content_model_storage_unavailable', 'ContentModelRelease storage is unavailable.');
    }
    $contentModelRoot = spacefast_content_model_root($privateRoot, spacefast_content_require_space_id());
    if ($revision === null) {
        return spacefast_content_sync_locked(static function () use ($contentModelRoot): array {
            $previous = spacefast_content_model_current_release($contentModelRoot);
            spacefast_content_sync_without_journal(static fn (): array => spacefast_content_sync_with_transaction(
                static fn (): array => spacefast_content_model_prepare_retirement($contentModelRoot, $previous, null)
            ));
            unset($GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'], $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION']);
            spacefast_content_public_routes_refresh();
            return ['revision' => null, 'tables' => 0, 'pages' => 0];
        });
    }
    $releaseRoot = $contentModelRoot . '/releases/' . spacefast_content_model_revision_directory($revision);
    $contentModel = spacefast_content_model_read_release($releaseRoot, $revision);
    $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $releaseRoot;
    $GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $revision;
    spacefast_content_model_register_wordpress_projection();
    spacefast_content_model_ensure_flat_permalinks();
    spacefast_content_model_ensure_managed_theme();
    spacefast_content_model_ensure_collection_terms($contentModel);
    return spacefast_content_sync_locked(static function () use ($contentModel, $contentModelRoot, $revision): array {
        $previous = spacefast_content_model_current_release($contentModelRoot);
        $previousPaths = [];
        $previousStorage = [];
        foreach ($previous['syncBindings'] ?? [] as $binding) {
            if (isset($binding['publicPath'])) $previousPaths[$binding['id']] = $binding['publicPath'];
            if (isset($binding['fieldStorage'])) $previousStorage[$binding['id']] = $binding['fieldStorage'];
        }
        $documents = spacefast_content_sync_without_journal(
            static fn (): array => spacefast_content_sync_with_transaction(static function () use ($previous, $contentModel, $contentModelRoot, $previousStorage): array {
                spacefast_content_model_prepare_retirement($contentModelRoot, $previous, $contentModel);
                return spacefast_content_model_reconcile_documents($contentModel, $previousStorage);
            })
        );
        spacefast_content_public_routes_refresh();
        foreach ($contentModel['syncBindings'] as $binding) {
            $previousPath = $previousPaths[$binding['id']] ?? null;
            $nextPath = $binding['publicPath'] ?? null;
            if (is_string($previousPath) && is_string($nextPath) && $previousPath !== $nextPath) {
                $redirect = spacefast_content_redirect_save(['source' => $previousPath, 'destination' => $nextPath, 'status' => 301, 'requiresPublishedDestination' => true]);
                if (is_wp_error($redirect)) {
                    throw new Spacefast_Content_Error(503, 'content_redirect_unavailable', $redirect->get_error_message());
                }
            }
        }
        return ['revision' => $revision, 'tables' => 0, 'pages' => count($documents)];
    });
}
