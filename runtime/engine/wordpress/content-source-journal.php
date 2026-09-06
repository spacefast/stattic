<?php
/**
 * Back to the repo: the kernel half.
 *
 * When a bound field changes in the editor, its source file is out of date. The
 * only thing WordPress does about that is append the intent — one journal row
 * naming the binding, the revision the editor produced and the common base the
 * ledger stood on. It never serializes anything and it never talks to a
 * repository: `source.reconcile` owns the canonical source text, and the control
 * plane's drain owns the commit.
 *
 * The one property that makes this safe is where the row is written.
 * `$wpdb->insert` runs on the same connection and inside the same transaction
 * as the save that triggered it, so a rolled-back save takes its intent with
 * it. Intent cannot outlive the write. That is also why the table is created at
 * `init` and never here: MySQL commits implicitly on DDL, and a CREATE issued
 * inside a save's transaction would commit that save's writes behind its back.
 *
 * Three kinds of save reach the drain, and they differ only in what the row
 * names:
 *
 * 1. A bound field the editor changed — the original case. The row names the
 *    binding and the common base the ledger stands on.
 * 2. A document no file backs at all: a post the editor created under a
 *    collection whose release declares where its files go. There is no binding
 *    to name, so the row carries the synthetic `materialize.<postId>`, which
 *    cannot collide with a compiler-minted `sync.<resource>.<slug>` and
 *    coalesces a burst of saves exactly as a real binding id does.
 * 3. A page whose only source is compile-class. Nothing can write back to a
 *    `.tsx`, so that binding never gets a ledger; this branch stands on the
 *    blocks digest alone, and what the drain lands is a NEW file in a format
 *    that can carry the document.
 *
 * Deliberately not journalled:
 *
 * - Saves the sync lane makes itself. `source.reconcile` writes posts on the
 *   push and merge paths; that write IS the reconciliation, and journalling it
 *   would ask the drain to reconcile the answer it just produced.
 * - Two-way bindings with no ledger. Without a common base there is nothing to
 *   reconcile against, and the first `source.reconcile` establishes both the
 *   base and the file. Fail closed rather than guess a base. Case 3 is not an
 *   exception to this: it never reconciles, it materializes.
 * - Saves that left the bound field's blocks byte-identical to the ledger's.
 *   A title-only edit is not a source change.
 * - Pages the content model projected from the capsule's own client source.
 *   Activation rewrites those on every publish, and they are the compiler's.
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
    if (!is_array($release['syncBindings'] ?? null) || $release['syncBindings'] === []) {
        return;
    }
    if ($wpdb->query(_stattic_content_source_journal_ddl()) === false) {
        error_log('spacefast content source journal install failed');
        return;
    }
    update_option(SPACEFAST_CONTENT_SOURCE_JOURNAL_OPTION, '1', true);
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
    if (!is_object($post) || (string) ($post->post_status ?? '') === 'auto-draft') {
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
    $collection = spacefast_content_collection_for_post_type((string) ($post->post_type ?? ''));
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
 * Which of the three cases this save is, and what its row says.
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
    if (!is_array($binding)) {
        return null;
    }
    $blocks = spacefast_content_sync_read_blocks($post, $binding);
    $ledger = spacefast_content_sync_ledger($postId);
    if (!is_array($ledger) || ($ledger['bindingId'] ?? null) !== $bindingId) {
        // A compile-class binding has no ledger and never will, so the blocks
        // digest alone is what this intent stands on. Every other unbound
        // binding stays skipped: no common base, nothing to reconcile against.
        return isset(SPACEFAST_CONTENT_SYNC_TAKEOVER_FORMATS[(string) $binding['format']])
            ? spacefast_content_source_journal_binding_entry(
                $bindingId,
                $binding,
                $postId,
                spacefast_content_sync_digest_text($blocks)
            )
            : null;
    }
    if (hash_equals((string) ($ledger['blocksDigest'] ?? ''), spacefast_content_sync_digest_text($blocks))) {
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
    $bindingId = $entry['bindingId'];
    $payload = $entry['payload'];
    $operationId = 'op_' . bin2hex(random_bytes(16));
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
        $bindingId,
        $encoded
    );
    if (!is_string($statement) || $wpdb->query($statement) === false) {
        throw new RuntimeException('content_source_journal_append_failed');
    }
}
