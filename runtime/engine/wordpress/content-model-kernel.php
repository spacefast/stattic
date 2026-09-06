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
const SPACEFAST_CONTENT_MODEL_SPACE_META = '_spacefast_space_id';
const SPACEFAST_CONTENT_MODEL_PAGE_SOURCE_META = '_zero_page_source_key';
const SPACEFAST_CONTENT_MODEL_PAGE_PATH_META = '_zero_page_path';
// Ability category slugs admit `^[a-z0-9]+(?:-[a-z0-9]+)*$`, and every
// WordPress-visible identifier this kernel mints is Zero-branded.
const SPACEFAST_CONTENT_MODEL_ABILITY_CATEGORY = 'zero-content';

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
        || !is_array($contentModel['tables'] ?? null)
        || !is_array($contentModel['pages'] ?? null)
        || !is_array($contentModel['abilities'] ?? null)
        || !is_array($contentModel['hooks'] ?? null)
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
    $contentModel = spacefast_content_model_active_release();
    foreach (is_array($contentModel['postTypes'] ?? null) ? $contentModel['postTypes'] : [] as $resource) {
        if (is_array($resource) && ($resource['id'] ?? null) === $resourceId) {
            return $resource;
        }
    }
    return null;
}

function spacefast_content_model_collection_projection(string $resourceId): ?array
{
    $resource = spacefast_content_model_resource($resourceId);
    if ($resource === null) {
        return null;
    }
    $fields = [];
    $nativeProperties = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt'];
    foreach ($resource['fields'] as $field) {
        $definition = $field['definition'];
        if (isset($nativeProperties[$field['name']])) {
            $definition['storageProperty'] = $nativeProperties[$field['name']];
            unset($definition['storageName']);
        }
        $fields[$field['name']] = $definition;
    }
    return [
        'name' => $resourceId,
        'post_type' => $resource['postType'],
        'public' => $resource['publicRead'],
        'fields' => $fields,
        'builtin' => $resource['kind'] !== 'collection',
        'media' => $resource['kind'] === 'media',
        'compiled' => false,
        'scoped' => true,
        'contentModel' => true,
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
                'post_type' => $binding['postType'],
                'field_storage' => $binding['fieldStorage'],
                'compiled' => $binding['compiled'] ?? null,
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
    // Abilities are NOT registered here. The Abilities API only accepts
    // registrations during `wp_abilities_api_init`; see
    // spacefast_content_model_register_active_abilities().
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
        foreach ($resource['fields'] as $field) {
            if (!empty($field['native'])) {
                continue;
            }
            $schema = $field['restSchema'];
            register_post_meta($resource['postType'], $field['storageName'], [
                'single' => ($schema['type'] ?? null) !== 'array',
                'type' => $schema['type'],
                'show_in_rest' => ['schema' => $schema],
                'auth_callback' => static fn (bool $allowed, string $key, int $postId): bool =>
                    $allowed && spacefast_content_model_authorize_post($postId),
            ]);
        }
    }
}

function spacefast_content_model_authorize_post(int $postId): bool
{
    if ($postId < 1 || !function_exists('get_post_meta')) {
        return false;
    }
    return hash_equals(
        spacefast_content_require_space_id(),
        (string) get_post_meta($postId, SPACEFAST_CONTENT_MODEL_SPACE_META, true)
    );
}

function spacefast_content_model_validate_reference_value(array $definition, mixed $value): bool
{
    if (!function_exists('get_post')) {
        return false;
    }
    $resourceIds = is_array($definition['collections'] ?? null)
        ? $definition['collections']
        : [$definition['collection'] ?? null];
    $postTypes = [];
    foreach ($resourceIds as $resourceId) {
        if (!is_string($resourceId)) {
            continue;
        }
        $projection = spacefast_content_model_collection_projection($resourceId);
        if ($projection !== null) {
            $postTypes[] = $projection['post_type'];
        }
    }
    $ids = is_array($value) ? $value : [$value];
    foreach ($ids as $id) {
        if ((int) $id < 1) {
            continue;
        }
        $post = get_post((int) $id);
        if (!is_object($post)
            || !in_array((string) ($post->post_type ?? ''), $postTypes, true)
            || !spacefast_content_model_authorize_post((int) $id)) {
            return false;
        }
    }
    return $postTypes !== [];
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
            update_term_meta($termId, SPACEFAST_CONTENT_MODEL_SPACE_META, $spaceId);
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

function spacefast_content_model_sql_identifier(string $identifier): string
{
    $normalized = str_replace(['.', '-'], '_', $identifier);
    // 58 characters leaves room for the `zero_` index prefix inside MySQL's
    // 64-character identifier ceiling.
    if (preg_match('/^[a-z][a-z0-9_]{0,57}$/', $normalized) !== 1) {
        throw new Spacefast_Content_Error(422, 'content_model_invalid', 'A Table SQL identifier is invalid.');
    }
    return $normalized;
}

/**
 * Tables are per Space, like every other projection here. One wp.cloud site
 * hosts many Spaces, and two of them may both declare a `reactions` table, so
 * the physical name carries the Space: an unscoped name would hand Space B
 * Space A's rows. The migration ledger is scoped the same way, because each
 * Space activates its own revision.
 */
function spacefast_content_model_table_name(object $wpdb, string $name): string
{
    $prefix = (string) ($wpdb->prefix ?? '');
    if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
        throw new Spacefast_Content_Error(503, 'content_tables_unavailable', 'The WordPress Table prefix is invalid.');
    }
    $scoped = $prefix . 'zero_'
        . substr(hash('sha256', spacefast_content_require_space_id()), 0, 16)
        . '_' . spacefast_content_model_sql_identifier($name);
    // MySQL's hard ceiling. The Space digest and prefix are fixed width, so this
    // only ever rejects a content model that authored an unusably long Table name.
    if (strlen($scoped) > 64) {
        throw new Spacefast_Content_Error(422, 'content_model_invalid', 'A Table name is too long for MySQL.');
    }
    return $scoped;
}

function spacefast_content_model_column_sql(array $column): string
{
    $type = match ($column['value']['kind']) {
        'string' => isset($column['value']['maxLength']) && is_int($column['value']['maxLength'])
            && $column['value']['maxLength'] > 0 && $column['value']['maxLength'] <= 16_383
                ? 'VARCHAR(' . $column['value']['maxLength'] . ')'
                : 'LONGTEXT',
        'number' => 'DOUBLE',
        'boolean' => 'TINYINT(1)',
        'datetime' => 'DATETIME(6)',
        'json' => 'LONGTEXT',
        default => throw new Spacefast_Content_Error(422, 'content_model_invalid', 'A Table column type is invalid.'),
    };
    return '`' . spacefast_content_model_sql_identifier($column['name']) . '` ' . $type
        . ($column['nullable'] ? ' NULL' : ' NOT NULL');
}

function spacefast_content_model_apply_tables(array $contentModel): void
{
    global $wpdb;
    if (!is_object($wpdb) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query') || !method_exists($wpdb, 'get_var')) {
        throw new Spacefast_Content_Error(503, 'content_tables_unavailable', 'WordPress Tables are unavailable.');
    }
    $ledger = spacefast_content_model_table_name($wpdb, 'migrations');
    $ledgerSql = "CREATE TABLE `{$ledger}` (revision VARCHAR(71) NOT NULL PRIMARY KEY, applied_at DATETIME(6) NOT NULL)";
    spacefast_content_model_apply_ddl($ledgerSql);
    $revision = $contentModel['revision'];
    $present = $wpdb->get_var($wpdb->prepare("SELECT revision FROM `{$ledger}` WHERE revision = %s", $revision));
    if ($present === $revision) {
        return;
    }
    foreach ($contentModel['tables'] as $table) {
        $tableName = spacefast_content_model_table_name($wpdb, $table['name']);
        $columns = ['`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'];
        $columnNames = [];
        foreach ($table['columns'] as $column) {
            $columns[] = spacefast_content_model_column_sql($column);
            $columnNames[$column['id']] = spacefast_content_model_sql_identifier($column['name']);
        }
        $columns[] = 'PRIMARY KEY (`id`)';
        foreach ($table['indexes'] as $index) {
            $indexed = array_map(static fn (string $field): string => '`' . $columnNames[$field] . '`', $index['fields']);
            $columns[] = (!empty($index['unique']) ? 'UNIQUE ' : '') . 'KEY `zero_'
                . spacefast_content_model_sql_identifier($index['id']) . '` (' . implode(', ', $indexed) . ')';
        }
        spacefast_content_model_apply_ddl("CREATE TABLE `{$tableName}` (" . implode(', ', $columns) . ')');
    }
    $inserted = $wpdb->query($wpdb->prepare(
        "INSERT INTO `{$ledger}` (revision, applied_at) VALUES (%s, UTC_TIMESTAMP(6))",
        $revision
    ));
    if ($inserted === false) {
        throw new Spacefast_Content_Error(500, 'content_table_migration_failed', 'The Table migration ledger could not be advanced.');
    }
}

/**
 * Activation re-runs on every publish, promote and rollback, so every statement
 * here has to survive meeting its own tables again.
 *
 * dbDelta is the tool that does that AND carries a column change from one
 * revision to the next, but it does not exist in an ordinary request: WordPress
 * only defines it once wp-admin/includes/upgrade.php is loaded. Loading it is
 * the whole reason this used to fail on the second activation and reach the
 * raw fallback, which cannot re-create an existing table.
 */
function spacefast_content_model_apply_ddl(string $sql): void
{
    global $wpdb;
    if (!function_exists('dbDelta') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/upgrade.php')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
    if (function_exists('dbDelta')) {
        $result = dbDelta($sql);
        if ($result === false) {
            throw new Spacefast_Content_Error(500, 'content_table_migration_failed', 'A Table migration failed.');
        }
        return;
    }
    // Without dbDelta the best this can promise is creation, not evolution, so
    // it must at least be repeatable. dbDelta itself cannot parse IF NOT EXISTS.
    $result = $wpdb->query(preg_replace('/^CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1));
    if ($result === false) {
        throw new Spacefast_Content_Error(500, 'content_table_migration_failed', 'A Table migration failed.');
    }
}

function spacefast_content_model_admit_ability(array $ability): bool
{
    $granted = $GLOBALS['SPACEFAST_CONTENT_GRANTED_CAPABILITIES'] ?? [];
    if (!is_array($granted)) {
        return false;
    }
    foreach ($ability['permits'] as $capability) {
        if (!is_string($capability) || !in_array($capability, $granted, true)) {
            return false;
        }
    }
    return true;
}

function spacefast_content_model_authorize_ability(array $ability, mixed $input): bool
{
    if (!spacefast_content_model_admit_ability($ability)) {
        return false;
    }
    $authorizer = $GLOBALS['SPACEFAST_CONTENT_ABILITY_AUTHORIZER'] ?? null;
    return !is_callable($authorizer) || $authorizer($ability, $input, spacefast_content_require_space_id()) === true;
}

function spacefast_content_model_dispatch_ability(string $abilityId, mixed $input): mixed
{
    $contentModel = spacefast_content_model_active_release();
    foreach (is_array($contentModel['abilities'] ?? null) ? $contentModel['abilities'] : [] as $ability) {
        if (!is_array($ability) || ($ability['id'] ?? null) !== $abilityId) {
            continue;
        }
        if (!spacefast_content_model_authorize_ability($ability, $input)) {
            throw new Spacefast_Content_Error(403, 'content_ability_denied', 'The content Ability denied this request.');
        }
        $dispatcher = $GLOBALS['SPACEFAST_CONTENT_ABILITY_DISPATCHER'] ?? null;
        if (!is_callable($dispatcher)) {
            throw new Spacefast_Content_Error(503, 'content_ability_unavailable', 'The content Ability dispatcher is unavailable.');
        }
        return $dispatcher($ability, $input);
    }
    throw new Spacefast_Content_Error(404, 'content_ability_not_found', 'The content Ability was not found.');
}

/**
 * The name the WordPress Abilities API publishes a compiled Ability under.
 *
 * A content model id is the compiler's vocabulary — `content.projects.list`,
 * `mutation.react` — and stays exactly that everywhere Spacefast addresses an
 * Ability: route contributions, SDK descriptors, executables, decisions,
 * receipts, invalidations. Only the WordPress registry needs another spelling,
 * because WP_Abilities_Registry::register() admits `^[a-z0-9-]+/[a-z0-9-]+$`:
 * one namespace, one slug, and no dots or underscores. A name outside it is
 * refused with `_doing_it_wrong` and registers nothing.
 *
 * So the id flattens onto a single Zero-branded slug — `content.projects.list`
 * becomes `zero/content-projects-list` — which the MCP adapter in turn
 * publishes to agents as `zero-content-projects-list`.
 *
 * Flattening is many-to-one: `a.b` and `a_b` reach the same name. The contentModel
 * contract rejects that collision at compile
 * (`contentModelReleaseV1Schema`'s "ability WordPress names must be unique" in
 * packages/common/src/contracts/content-model.ts), and this derivation and
 * that one must change together.
 */
function spacefast_content_model_ability_name(string $id): string
{
    return 'zero/' . str_replace(['.', '_'], '-', $id);
}

function spacefast_content_model_register_ability_category(): void
{
    // wp_register_ability_category() checks doing_action() for its own hook and
    // returns null anywhere else, so this only ever runs from that action.
    if (function_exists('wp_register_ability_category')) {
        wp_register_ability_category(SPACEFAST_CONTENT_MODEL_ABILITY_CATEGORY, [
            'label' => 'Zero content',
            'description' => 'Abilities projected from the active Spacefast ContentModelRelease.',
        ]);
    }
}

/**
 * The `wp_abilities_api_init` entry. wp_register_ability() refuses every call
 * made outside that action, so registration cannot ride along with the rest of
 * the WordPress projection on `init` — it has to be its own hook.
 *
 * A Space with no active release registers nothing, and a release the kernel
 * cannot project leaves the site serving: a broken content model costs its Abilities,
 * never the page.
 */
function spacefast_content_model_register_active_abilities(): void
{
    $contentModel = spacefast_content_model_active_release();
    if ($contentModel === null) {
        return;
    }
    try {
        spacefast_content_model_register_abilities($contentModel);
    } catch (Spacefast_Content_Error $error) {
        error_log('spacefast content model abilities unregistered code=' . $error->codeName);
    }
}

function spacefast_content_model_register_abilities(array $contentModel): void
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    $names = [];
    foreach ($contentModel['abilities'] as $ability) {
        $id = $ability['id'];
        $inputSchema = $ability['inputSchema'] ?? null;
        $outputSchema = $ability['outputSchema'] ?? null;
        if ($inputSchema === null || $outputSchema === null) {
            throw new Spacefast_Content_Error(422, 'content_model_invalid', 'An Ability schema reference is invalid.');
        }
        $name = spacefast_content_model_ability_name($id);
        // The registry answers a repeat name with _doing_it_wrong and keeps the
        // first registration, which would silently drop the second Ability.
        if (isset($names[$name])) {
            throw new Spacefast_Content_Error(422, 'content_model_ability_name_conflict', 'Two Abilities compile to one WordPress Ability name.');
        }
        $names[$name] = true;
        wp_register_ability($name, [
            'label' => $id,
            'description' => 'Generated from the active Spacefast ContentModelRelease.',
            'category' => SPACEFAST_CONTENT_MODEL_ABILITY_CATEGORY,
            'input_schema' => $inputSchema,
            'output_schema' => $outputSchema,
            'permission_callback' => static fn (): bool => spacefast_content_model_admit_ability($ability),
            'execute_callback' => static fn (mixed $input): mixed => spacefast_content_model_dispatch_ability($id, $input),
            // `public` is what carries a compiled Ability to clients — the REST
            // surface and, through the MCP adapter, agents. It is the same
            // registration the Space's users Abilities use, so the generated
            // content model SDK and an agent's tool list name the same set. Admission
            // stays with permission_callback; publishing is not permitting.
            'meta' => [
                'public' => true,
                'kind' => $ability['kind'],
                'mode' => $ability['mode'],
                'reads' => $ability['reads'],
                'writes' => $ability['writes'],
                'annotations' => [
                    'readonly' => $ability['mode'] === 'read',
                    'destructive' => $ability['mode'] === 'write',
                    'idempotent' => $ability['mode'] === 'read',
                ],
            ],
        ]);
    }
}

/** Stage verified generated PHP without changing the live Space pointer. */
function spacefast_content_model_stage_release(
    mixed $revision,
    mixed $contentModelPhp,
    mixed $artifactDigest,
    bool $managed
): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content model staging requires Spacefast authorization.');
    }
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
    if ((!is_dir($releasesRoot) && !mkdir($releasesRoot, 0750, true)) || !is_dir($releasesRoot)) {
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

/** The verified release carries seed bytes, so activation never guesses a live version root. */
function spacefast_content_model_reconcile_documents(array $contentModel): array
{
    $documents = [];
    foreach ($contentModel['syncBindings'] as $binding) {
        $canonicalPage = ($binding['postType'] ?? null) === 'page'
            && preg_match('/\Async\.pages\.[a-f0-9]{32}\z/D', (string) ($binding['id'] ?? '')) === 1;
        $collectionPost = ($binding['postType'] ?? null) === 'post'
            && ($binding['resourceId'] ?? null) === 'posts'
            && ($binding['fieldId'] ?? null) === 'posts-content'
            && isset($binding['documentSeed']);
        if (!$canonicalPage && !$collectionPost) {
            continue;
        }
        $seed = $binding['documentSeed'] ?? null;
        $text = is_array($seed) ? ($seed['text'] ?? null) : null;
        $digest = is_array($seed) ? ($seed['sha256'] ?? null) : null;
        $format = $binding['format'] ?? null;
        if (!is_string($text) || strlen($text) > SPACEFAST_CONTENT_SYNC_MAX_TEXT_BYTES
            || !is_string($digest) || !hash_equals(spacefast_content_sync_digest_text($text), $digest)
            || !in_array($format, ['md', 'html', 'tsx'], true)
            || ($binding['fieldStorage'] ?? null) !== 'post_content'
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
                'post_type' => $binding['postType'],
                'field_storage' => 'post_content',
            ],
        ]);
    }
    return $documents;
}

function spacefast_content_model_activate_release(mixed $revision, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content model activation requires Spacefast authorization.');
    }
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
            $pointer = $contentModelRoot . '/active-release';
            if (is_file($pointer) && !_stattic_private_tree_remove($pointer)) {
                throw new Spacefast_Content_Error(500, 'content_model_pointer_failed', 'The ContentModelRelease pointer could not be cleared.');
            }
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
    spacefast_content_model_apply_tables($contentModel);
    return spacefast_content_sync_locked(static function () use ($contentModel, $contentModelRoot, $revision): array {
        $documents = spacefast_content_sync_without_journal(
            static fn (): array => spacefast_content_sync_with_transaction(
                static fn (): array => spacefast_content_model_reconcile_documents($contentModel)
            )
        );
        if (!_stattic_private_tree_write_pointer($contentModelRoot . '/active-release', $revision)) {
            throw new Spacefast_Content_Error(500, 'content_model_pointer_failed', 'The ContentModelRelease pointer could not be switched.');
        }
        return ['revision' => $revision, 'tables' => count($contentModel['tables']), 'pages' => count($documents)];
    });
}
