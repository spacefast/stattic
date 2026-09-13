<?php
/**
 * WordPress saves content and appends source intent on the same database connection.
 * A rolled-back save therefore rolls back its journal row. Install the table at
 * init, outside saves, because MySQL DDL implicitly commits a transaction.
 *
 * Bound document body or metadata changes name their common base. New documents
 * name the collection that declares their source directory. Component conversion
 * is an explicit operation; saving a TSX-backed page never converts its owner.
 * The runtime serializes documents and the control plane commits their source.
 */
declare(strict_types=1);

require_once __DIR__ . '/../shared/content-source-journal.php';

const SPACEFAST_CONTENT_SOURCE_JOURNAL_OPTION = 'spacefast_content_source_journal_v1';
const SPACEFAST_CONTENT_SOURCE_JOURNAL_FALLBACK_AUTHOR = 'Spacefast';
const SPACEFAST_CONTENT_SOURCE_JOURNAL_FALLBACK_EMAIL = 'content@spacefast.com';

/**
 * Create the journal table once per site, at `init`, where no transaction is
 * open. Only sites whose ContentModelRelease actually declares a sync binding pay
 * for it, and the option makes it a single autoloaded read on every later
 * request.
 */
function spacefast_content_source_journal_install(): void
{
    global $wpdb;
    if (
        !function_exists('get_option') || !function_exists('update_option')
        || !is_object($wpdb) || !method_exists($wpdb, 'query')
        || get_option(SPACEFAST_CONTENT_SOURCE_JOURNAL_OPTION) === '1'
    ) {
        return;
    }
    $release = spacefast_content_model_active_release();
    if (($release['syncBindings'] ?? []) === [] && ($release['materializations'] ?? []) === []) {
        return;
    }
    if ($wpdb->query(_stattic_content_source_journal_ddl()) === false) {
        error_log('spacefast content source journal install failed');
        return;
    }
    update_option(SPACEFAST_CONTENT_SOURCE_JOURNAL_OPTION, '1', true);
}

/** A trusted cron event selects its scope from stored post ownership. */
function spacefast_content_source_journal_publish_scheduled(int $postId): void
{
    $spaceId = get_post_meta($postId, SPACEFAST_CONTENT_SPACE_META, true);
    if (!is_string($spaceId) || preg_match('/\Aspc_[A-Za-z0-9_-]+\z/D', $spaceId) !== 1) {
        check_and_publish_future_post($postId);
        return;
    }
    $releaseRoot = $GLOBALS['SPACEFAST_RUNTIME_ACTIVE_RELEASE_ROOT'] ?? null;
    if (!is_string($releaseRoot)) {
        throw new Spacefast_Content_Error(503, 'content_runtime_unavailable', 'Scheduled publication needs the installed runtime.');
    }
    $privateRoot = dirname($releaseRoot, 2) . '/storage';
    $modelRoot = spacefast_content_model_root($privateRoot, $spaceId);
    $revision = _stattic_private_tree_read_pointer($modelRoot . '/active-release', 128);
    $context = [
        'SPACEFAST_CONTENT_SPACE_ID' => $spaceId,
        'SPACEFAST_CONTENT_PRIVATE_ROOT' => $privateRoot,
        'SPACEFAST_CONTENT_MODEL_RELEASE_ROOT' => null,
        'SPACEFAST_CONTENT_MODEL_REVISION' => null,
        'SPACEFAST_CONTENT_PINNED_MODEL_REVISION' => null,
        'SPACEFAST_CONTENT_WRITE_COLLECTION' => null,
        'SPACEFAST_CONTENT_ROUTES_SCHEDULED' => true,
    ];
    if ($revision !== null) {
        if (preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $revision) !== 1) {
            throw new Spacefast_Content_Error(503, 'content_model_revision_invalid', 'The scheduled document content model is unavailable.');
        }
        $modelRelease = $modelRoot . '/releases/' . spacefast_content_model_revision_directory($revision);
        spacefast_content_model_read_release($modelRelease, $revision);
        $context['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $modelRelease;
        $context['SPACEFAST_CONTENT_MODEL_REVISION'] = $revision;
    }
    $previous = [];
    foreach ($context as $key => $value) {
        $previous[$key] = ['exists' => array_key_exists($key, $GLOBALS), 'value' => $GLOBALS[$key] ?? null];
        $GLOBALS[$key] = $value;
    }
    try {
        spacefast_content_source_journal_install();
        check_and_publish_future_post($postId);
        spacefast_content_public_routes_refresh();
    } finally {
        foreach ($previous as $key => $saved) {
            if ($saved['exists']) {
                $GLOBALS[$key] = $saved['value'];
            } else {
                unset($GLOBALS[$key]);
            }
        }
    }
}

/** The binding this post is the WordPress side of, or null. */
function spacefast_content_source_journal_binding_id(int $postId): ?string
{
    if (!function_exists('get_post_meta')) {
        return null;
    }
    $externalId = get_post_meta($postId, SPACEFAST_CONTENT_EXTERNAL_ID_META, true);
    if (!is_string($externalId) || !str_starts_with($externalId, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX)) {
        return null;
    }
    $bindingId = substr($externalId, strlen(SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX));
    return $bindingId !== '' && spacefast_content_model_is_stable_id($bindingId) ? $bindingId : null;
}

/**
 * The acting principal's display identity, for the commit this becomes.
 *
 * WordPress users on a managed site are the projection of Spacefast principals
 * (content-principals.php), so the current user's display name and address are
 * the person who made the edit. A save with no user behind it — WP-CLI, cron —
 * is attributed to the platform rather than to nobody.
 *
 * @return array{name:string,email:string}
 */
function spacefast_content_source_journal_author(): array
{
    $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $name = is_object($user) ? trim((string) ($user->display_name ?? '')) : '';
    $email = is_object($user) ? trim((string) ($user->user_email ?? '')) : '';
    $validEmail = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    return [
        'name' => $name !== '' && preg_match('/[\r\n\0]/', $name) !== 1
            ? substr($name, 0, 200)
            : SPACEFAST_CONTENT_SOURCE_JOURNAL_FALLBACK_AUTHOR,
        'email' => $validEmail ? $email : SPACEFAST_CONTENT_SOURCE_JOURNAL_FALLBACK_EMAIL,
    ];
}

/**
 * The `save_post` seam.
 *
 * WordPress fires this for autosaves and for the revision rows it cuts as well
 * as for the save itself; only the parent save is a change to reconcile, so the
 * other two return early and let the revision they produced be read back as the
 * revision this entry names.
 */
function spacefast_content_source_journal_record_save(int $postId, mixed $post = null): void
{
    if (!empty($GLOBALS['SPACEFAST_CONTENT_SYNC_RECONCILING'])) {
        return;
    }
    if (
        (function_exists('wp_is_post_revision') && wp_is_post_revision($postId) !== false)
        || (function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId) !== false)
    ) {
        return;
    }
    if (!is_object($post) && function_exists('get_post')) {
        $post = get_post($postId);
    }
    if (!is_object($post) || (string) ($post->post_status ?? '') === 'auto-draft' || spacefast_content_source_is_retired($postId)) {
        return;
    }
    try {
        spacefast_content_source_journal_append($postId, $post);
    } catch (Throwable $error) {
        // A save that cannot record its intent is still a real save: the next
        // edit, or the next reconcile from any transport, catches the file up.
        // Raising here would turn a sync-lane fault into a failed edit.
        error_log('spacefast content source journal append failed: ' . get_debug_type($error));
    }
}

/** REST and SCF write bound block meta after save_post. */
function spacefast_content_source_journal_record_meta(mixed $metaId, int $postId, string $metaKey): void
{
    $bindingId = spacefast_content_source_journal_binding_id($postId);
    if ($bindingId === null) {
        return;
    }
    $binding = spacefast_content_model_sync_binding($bindingId);
    if (is_array($binding) && ($binding['field_storage'] ?? null) === $metaKey) {
        spacefast_content_source_journal_record_save($postId);
    }
}

/**
 * The compile-class binding this post is the WordPress side of, or null.
 *
 * `source.reconcile` refuses a compile-class binding, so its post never gets the
 * sync external id every two-way binding's post carries. The release's own
 * declaration is the only thing that connects them, and it connects them by the
 * post type and slug the compiler assigned.
 */
function spacefast_content_source_journal_compile_binding_id(object $post): ?string
{
    $release = spacefast_content_model_active_release();
    $postType = (string) ($post->post_type ?? '');
    $slug = (string) ($post->post_name ?? '');
    if ($slug === '') {
        return null;
    }
    foreach (is_array($release['syncBindings'] ?? null) ? $release['syncBindings'] : [] as $binding) {
        if (
            is_array($binding)
            && isset(SPACEFAST_CONTENT_SYNC_TAKEOVER_FORMATS[(string) ($binding['format'] ?? '')])
            && ($binding['postType'] ?? null) === $postType
            && ($binding['slug'] ?? null) === $slug
            && spacefast_content_model_is_stable_id($binding['id'] ?? null)
        ) {
            return (string) $binding['id'];
        }
    }
    return null;
}

/** @return array{bindingId:string,payload:array<string,mixed>} */
function spacefast_content_source_journal_binding_entry(
    string $bindingId,
    array $binding,
    int $postId,
    string $baseRevision
): array {
    return [
        'bindingId' => $bindingId,
        'payload' => [
            'bindingId' => $bindingId,
            'source' => (string) $binding['source'],
            'postId' => $postId,
            'wordpressRevisionId' => spacefast_content_sync_current_revision_id($postId),
            'baseRevision' => $baseRevision,
            'author' => spacefast_content_source_journal_author(),
        ],
    ];
}

/** The synthetic entry for a document its collection says where to put. */
function spacefast_content_source_journal_materialize_entry(int $postId, object $post): ?array
{
    if (is_array(get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_PREPARED_META, true))) {
        return null;
    }
    $collection = spacefast_content_collection_for_post($postId);
    $resourceId = is_array($collection) ? (string) $collection['name'] : '';
    if ($resourceId === '' || spacefast_content_model_materialization($resourceId) === null) {
        return null;
    }
    // A Page the content model projected from the capsule's own client source
    // belongs to the compiler: activation rewrites it on every publish, and
    // materializing it would hand the drain a file the capsule never asked for.
    if (
        function_exists('get_post_meta')
        && (string) get_post_meta($postId, SPACEFAST_CONTENT_MODEL_PAGE_SOURCE_META, true) !== ''
    ) {
        return null;
    }
    return [
        'bindingId' => 'materialize.' . $postId,
        'payload' => [
            'resourceId' => $resourceId,
            'postId' => $postId,
            'wordpressRevisionId' => spacefast_content_sync_current_revision_id($postId),
            'author' => spacefast_content_source_journal_author(),
        ],
    ];
}

/**
 * The source intent associated with this document save.
 *
 * @return array{bindingId:string,payload:array<string,mixed>}|null
 */
function spacefast_content_source_journal_entry(int $postId, object $post): ?array
{
    $bindingId = spacefast_content_source_journal_binding_id($postId)
        ?? spacefast_content_source_journal_compile_binding_id($post);
    if ($bindingId === null) {
        return spacefast_content_source_journal_materialize_entry($postId, $post);
    }
    $binding = spacefast_content_model_sync_binding($bindingId);
    if (!is_array($binding) || isset(SPACEFAST_CONTENT_SYNC_TAKEOVER_FORMATS[(string) $binding['format']])) {
        return null;
    }
    $blocks = spacefast_content_sync_read_blocks($post, $binding);
    $ledger = spacefast_content_sync_ledger($postId);
    if (!is_array($ledger) || ($ledger['bindingId'] ?? null) !== $bindingId) {
        return null;
    }
    if (!spacefast_content_sync_document_changed($ledger, $post, $binding)) {
        return null;
    }
    return spacefast_content_source_journal_binding_entry(
        $bindingId,
        $binding,
        $postId,
        (string) $ledger['revision']
    );
}

function spacefast_content_source_journal_append(int $postId, object $post): void
{
    global $wpdb;
    $spaceId = spacefast_content_space_id();
    if ($spaceId === '' || !is_object($wpdb) || !method_exists($wpdb, 'query')) {
        return;
    }
    $entry = spacefast_content_source_journal_entry($postId, $post);
    if ($entry === null) {
        return;
    }
    spacefast_content_source_journal_write($entry, 'op_' . bin2hex(random_bytes(16)), true);
}

function spacefast_content_source_journal_write(array $entry, string $operationId, bool $coalesce): void
{
    global $wpdb;
    $spaceId = spacefast_content_require_space_id();
    $bindingId = $entry['bindingId'];
    $payload = $entry['payload'];
    $entryId = $spaceId . ':' . $operationId . ':0';
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    // A burst of saves on one binding folds onto the single pending entry
    // through uniq_open_binding: the newest revision wins, and the backoff of a
    // lane that is currently failing is deliberately left alone.
    $statement = $wpdb->prepare(
        'INSERT INTO ' . STATTIC_CONTENT_SOURCE_JOURNAL_TABLE . '
            (entry_id, space_id, operation_id, effect_index, binding_id, open_binding_id, state,
             payload_json, attempt_count, available_at, created_at, updated_at)
         VALUES (%s, %s, %s, 0, %s, %s, \'queued\', %s, 0, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))
         ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = UTC_TIMESTAMP(6)',
        $entryId,
        $spaceId,
        $operationId,
        $bindingId,
        $coalesce ? $bindingId : 'operation.' . $operationId,
        $encoded
    );
    if (!is_string($statement) || $wpdb->query($statement) === false) {
        throw new RuntimeException('content_source_journal_append_failed');
    }
}

/** Conversion is a requested operation, never a side effect of saving blocks. */
function spacefast_content_request_conversion(array $request, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content conversion requires Spacefast authorization.');
    }
    $operationId = $request['operationId'] ?? null;
    $bindingId = $request['bindingId'] ?? null;
    if (!is_string($operationId) || preg_match('/^op_[A-Za-z0-9]+$/', $operationId) !== 1
        || !is_string($bindingId) || !spacefast_content_model_is_stable_id($bindingId)) {
        spacefast_content_sync_materialize_invalid();
    }
    $input = spacefast_content_sync_materialize_takeover($operationId, $bindingId);
    $post = $input['post'];
    $postId = (int) $post->ID;
    if (($request['postId'] ?? null) !== $postId || !spacefast_content_post_belongs_to_space($postId)) {
        throw new Spacefast_Content_Error(404, 'content_document_not_found', 'This binding has no matching document.');
    }
    $binding = spacefast_content_model_sync_binding($bindingId);
    $entry = spacefast_content_source_journal_binding_entry($bindingId, $binding, $postId,
        spacefast_content_sync_digest_text(spacefast_content_sync_read_blocks($post, $binding)));
    $entry['payload']['intent'] = 'convert';
    spacefast_content_source_journal_install();
    spacefast_content_source_journal_write($entry, $operationId, false);
    return ['operationId' => $operationId, 'status' => 'queued', 'bindingId' => $bindingId, 'postId' => $postId];
}

/** Journal delivery is separate from the build named by its downstream receipt. */
function spacefast_content_source_journal_status(): array
{
    global $wpdb;
    spacefast_content_source_journal_install();
    if (get_option(SPACEFAST_CONTENT_SOURCE_JOURNAL_OPTION) !== '1') {
        return ['operations' => []];
    }
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT operation_id, binding_id, state, payload_json, attempt_count, available_at, lease_expires_at, downstream_receipt, last_error_code, updated_at FROM '
        . STATTIC_CONTENT_SOURCE_JOURNAL_TABLE . ' WHERE space_id = %s ORDER BY updated_at DESC, entry_id DESC LIMIT 100',
        spacefast_content_require_space_id()
    ), ARRAY_A);
    if (!is_array($rows)) {
        throw new Spacefast_Content_Error(503, 'content_sync_status_unavailable', 'Content synchronization status is unavailable.');
    }
    return ['operations' => array_map(static function (array $row): array {
        $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        return [
            'operationId' => $row['operation_id'],
            'bindingId' => $row['binding_id'],
            'postId' => (int) ($payload['postId'] ?? 0),
            'state' => $row['state'],
            'attemptCount' => (int) $row['attempt_count'],
            'availableAt' => $row['available_at'],
            'leaseExpiresAt' => $row['lease_expires_at'],
            'receipt' => $row['downstream_receipt'],
            'errorCode' => $row['last_error_code'],
            'updatedAt' => $row['updated_at'],
        ];
    }, $rows)];
}

function spacefast_content_source_journal_retry(string $operationId): array
{
    global $wpdb;
    if (preg_match('/^op_[A-Za-z0-9]+$/', $operationId) !== 1) {
        throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The operation ID is invalid.');
    }
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE " . STATTIC_CONTENT_SOURCE_JOURNAL_TABLE . " SET state = 'queued', attempt_count = 0, available_at = UTC_TIMESTAMP(6), lease_token = NULL, lease_expires_at = NULL, terminal_at = NULL, updated_at = UTC_TIMESTAMP(6) WHERE space_id = %s AND operation_id = %s AND state IN ('retry', 'ambiguous')",
        spacefast_content_require_space_id(), $operationId
    ));
    if ($updated !== 1) {
        throw new Spacefast_Content_Error(409, 'content_sync_retry_unavailable', 'This operation is not waiting for a retry. Resolve any content conflict before trying again.');
    }
    return ['operationId' => $operationId, 'status' => 'queued'];
}
