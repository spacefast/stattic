<?php
/**
 * Two-way reconciliation between a Space's repo files and WordPress post
 * content, on top of the ContentModelRelease sync bindings.
 *
 * The authority split, which every function here assumes:
 *
 * - The **repo file** is the source of truth for the bytes a human wrote.
 * - **WordPress blocks** are the source of truth for what the editor produced.
 * - The **ledger** is the common base binding the two, keyed by revision and
 *   digest, stored on the post it binds.
 *
 * Nothing in this file knows what a Markdown or an HTML document looks like.
 * The reconciliation — common base, compare-and-swap, three-way merge,
 * idempotent receipts — is the same algorithm whichever format a binding names,
 * so the format-aware code is exactly four functions wide and lives next door
 * in `content-markdown.php` and `content-html.php`. Adding a third format is a
 * new file and a new arm of the match below; it is not an edit to the merge.
 *
 * Both serializers share two properties, and both are measured rather than
 * assumed (runtime/tests/content-source-sync.test.ts and
 * runtime/tests/content-html-sync.test.ts):
 *
 * 1. text -> blocks -> text is a FIXED POINT, not an identity. The first pass
 *    normalizes; every later pass is byte-stable. So the ledger stores the
 *    CANONICAL text, never the raw source bytes. Digesting raw bytes would
 *    report a change on every single sync.
 * 2. Neither format can represent every block. So any time WordPress content
 *    flows OUT to a source file, the blocks must first survive a round trip
 *    unchanged; if they do not, this refuses rather than silently dropping what
 *    the editor added. That is the fail-closed rule, and the refusal names the
 *    format that could not carry the document.
 */
declare(strict_types=1);

const SPACEFAST_CONTENT_SYNC_LEDGER_META = '_spacefast_source_sync_ledger_v1';
const SPACEFAST_CONTENT_SYNC_RECEIPT_META = '_spacefast_source_sync_receipts_v1';
/**
 * How many operation receipts one binding keeps. Receipts exist so a retried
 * operationId replays its answer instead of re-running the write, and a retry
 * ladder lives seconds — never dozens of operations. Keeping them in one
 * bounded row rather than a row per operationId matters because WordPress
 * primes a post's entire meta cache on first access, so an unbounded pile
 * would tax every content operation on that post, not just the sync.
 */
const SPACEFAST_CONTENT_SYNC_RECEIPT_LIMIT = 20;
const SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX = 'source:';
const SPACEFAST_CONTENT_SYNC_SERIALIZER_VERSION = 1;
const SPACEFAST_CONTENT_SYNC_MAX_TEXT_BYTES = 1000000;
const SPACEFAST_CONTENT_SYNC_FORMATS = ['md', 'html'];

/** The source text a document becomes, in the binding's format. */
function spacefast_content_sync_from_blocks(string $format, string $blocks): string
{
    return match ($format) {
        'md' => spacefast_content_markdown_from_blocks($blocks),
        'html' => spacefast_content_html_from_blocks($blocks),
    };
}

/** The blocks a source document becomes, in the binding's format. */
function spacefast_content_sync_to_blocks(string $format, string $text): string
{
    return match ($format) {
        'md' => spacefast_content_markdown_to_blocks($text),
        'html' => spacefast_content_html_to_blocks($text),
    };
}

/**
 * The ledger's spelling of a source document: the serializer's fixed point, so
 * that two files which mean the same thing digest the same and a no-op sync
 * stays a no-op. May refuse — a document the round trip would reshape must not
 * become a common base.
 */
function spacefast_content_sync_canonical_text(string $format, string $text): string
{
    return match ($format) {
        'md' => spacefast_content_markdown_canonical($text),
        'html' => spacefast_content_html_canonical($text),
    };
}

/** Whether these blocks survive being written out as this format. */
function spacefast_content_sync_representable(string $format, string $blocks): bool
{
    return match ($format) {
        'md' => spacefast_content_markdown_representable($blocks),
        'html' => spacefast_content_html_representable($blocks),
    };
}

/**
 * The refusal for a document richer than its binding's format. It is not an
 * error in the document — it means this document cannot be flattened into that
 * file, so the file is left alone.
 */
function spacefast_content_sync_not_representable(string $format, string $consequence): never
{
    throw new Spacefast_Content_Error(
        409,
        $format === 'html' ? 'content_html_not_representable' : 'content_markdown_not_representable',
        ($format === 'html'
            ? 'This WordPress document uses blocks HTML cannot carry. '
            : 'This WordPress document uses formatting Markdown cannot carry. ') . $consequence
    );
}

/**
 * @return array{merged:string,conflicts:bool}
 */
function spacefast_content_sync_three_way(string $base, string $source, string $wordpress): array
{
    spacefast_content_sync_require_toolkit();
    try {
        $strategy = new WordPress\Merge\MergeStrategy(
            new WordPress\Merge\Diff\LineDiffer(),
            new WordPress\Merge\Merge\LineMerger(),
            null
        );
        $result = $strategy->merge($base, $source, $wordpress);
        return [
            'merged' => (string) $result->get_merged_content(),
            'conflicts' => (bool) $result->has_conflicts(),
        ];
    } catch (Throwable $error) {
        error_log('spacefast source merge failed: ' . $error->getMessage());
        // A merge this driver cannot complete is a conflict for the caller, not
        // a 500: the two sides still have to be reconciled by a human.
        return ['merged' => '', 'conflicts' => true];
    }
}

function spacefast_content_sync_digest_text(string $value): string
{
    return 'sha256:' . hash('sha256', $value);
}

/**
 * The digest spelling packages/common/src/utils/canonical-json.ts produces, so
 * a ledger revision computed here verifies against `verifySyncLedgerV1` there.
 */
function spacefast_content_sync_canonical_json(mixed $value): string
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return '[' . implode(',', array_map('spacefast_content_sync_canonical_json', $value)) . ']';
        }
        ksort($value, SORT_STRING);
        $members = [];
        foreach ($value as $key => $member) {
            $members[] = json_encode((string) $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . ':' . spacefast_content_sync_canonical_json($member);
        }
        return '{' . implode(',', $members) . '}';
    }
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        throw new Spacefast_Content_Error(500, 'content_sync_ledger_invalid', 'The sync ledger could not be encoded.');
    }
    return $encoded;
}

function spacefast_content_sync_revision(array $ledger): string
{
    unset($ledger['revision']);
    return spacefast_content_sync_digest_text(spacefast_content_sync_canonical_json($ledger));
}

function spacefast_content_sync_make_ledger(
    array $input,
    string $text,
    string $blocks,
    int $wordpressRevisionId,
    string $direction
): array {
    $ledger = [
        'version' => 1,
        'bindingId' => $input['bindingId'],
        'source' => $input['source'],
        'format' => $input['format'],
        'baseText' => $text,
        'textDigest' => spacefast_content_sync_digest_text($text),
        'blocksDigest' => spacefast_content_sync_digest_text($blocks),
        'wordpressRevisionId' => $wordpressRevisionId,
        'serializerVersion' => SPACEFAST_CONTENT_SYNC_SERIALIZER_VERSION,
        'lastDirection' => $direction,
    ];
    $ledger['revision'] = spacefast_content_sync_revision($ledger);
    return $ledger;
}

function spacefast_content_sync_receipt_payload(
    string $status,
    string $operationId,
    array $ledger,
    ?array $sourceWrite = null
): array {
    $receipt = [
        'format' => 'spacefast.content-sync',
        'version' => 1,
        'operationId' => $operationId,
        'status' => $status,
        'ledger' => $ledger,
    ];
    if (is_array($sourceWrite)) {
        $receipt['sourceWrite'] = $sourceWrite;
    }
    return $receipt;
}

function spacefast_content_sync_prepared_source_write(array $input, array $ledger): array
{
    return [
        'state' => 'prepared',
        'source' => $ledger['source'],
        'expectedSourceRevision' => $input['observedSourceRevision'],
        'text' => $ledger['baseText'],
        'textDigest' => $ledger['textDigest'],
    ];
}

function spacefast_content_sync_source_valid(mixed $source): bool
{
    if (!is_string($source) || $source === '' || strlen($source) > 1000 || str_contains($source, '\\')) {
        return false;
    }
    if (str_starts_with($source, '/') || preg_match('/[\x00-\x1F\x7F]/', $source) === 1) {
        return false;
    }
    foreach (explode('/', $source) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }
    return true;
}

function spacefast_content_sync_parse_reconcile(array $request): array
{
    $state = $request['state'] ?? null;
    $bindingId = $request['bindingId'] ?? null;
    $source = $request['source'] ?? null;
    $text = $request['text'] ?? null;
    $sourceRevision = $request['observedSourceRevision'] ?? null;
    $operationId = $request['operationId'] ?? null;
    $baseRevision = $request['baseRevision'] ?? null;
    $binding = is_string($bindingId) ? spacefast_content_model_sync_binding($bindingId) : null;
    if (
        !in_array($state, ['initial', 'bound'], true)
        || !is_string($bindingId)
        || !spacefast_content_model_is_stable_id($bindingId)
        || !spacefast_content_sync_source_valid($source)
        || !is_string($text)
        || strlen($text) > SPACEFAST_CONTENT_SYNC_MAX_TEXT_BYTES
        || !is_string($sourceRevision)
        || trim($sourceRevision) === ''
        || strlen($sourceRevision) > 512
        || !is_string($operationId)
        || preg_match('/^op_[A-Za-z0-9]+$/', $operationId) !== 1
        || ($state === 'initial' && $baseRevision !== null)
        || ($state === 'bound' && (!is_string($baseRevision) || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $baseRevision) !== 1))
    ) {
        throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The reconciliation request is invalid.');
    }
    // The binding is the content model's, not the request's: a caller cannot invent a
    // source path or a post type this ContentModelRelease never declared.
    if (!is_array($binding)) {
        throw new Spacefast_Content_Error(404, 'content_sync_binding_not_found', 'This ContentModelRelease has no such sync binding.');
    }
    if (($binding['source'] ?? null) !== $source) {
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'The binding names a different source file.');
    }
    // The format comes from the binding too, for the same reason. A caller
    // cannot ask for the HTML serializer on a file the model bound to Markdown.
    if (!in_array($binding['format'] ?? null, SPACEFAST_CONTENT_SYNC_FORMATS, true)) {
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'The binding names no known source format.');
    }
    return [
        'state' => $state,
        'bindingId' => $bindingId,
        'source' => $source,
        'format' => $binding['format'],
        'text' => $text,
        'observedSourceRevision' => $sourceRevision,
        'operationId' => $operationId,
        'baseRevision' => $baseRevision,
        'binding' => $binding,
    ];
}

function spacefast_content_sync_find_post(string $bindingId, array $binding): ?object
{
    $externalId = SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $bindingId;
    $posts = get_posts([
        'post_type' => 'any',
        'post_status' => 'any',
        'numberposts' => 2,
        'meta_query' => [
            'relation' => 'AND',
            spacefast_content_space_meta_clause(),
            ['key' => SPACEFAST_CONTENT_EXTERNAL_ID_META, 'value' => $externalId, 'compare' => '='],
        ],
    ]);
    if (count($posts) > 1) {
        throw new Spacefast_Content_Error(409, 'content_document_identity_conflict', 'More than one post uses this sync binding.');
    }
    $post = $posts[0] ?? null;
    if ($post === null) {
        // First sync of a binding whose post the ContentModelRelease already
        // projected: adopt it by slug rather than creating a duplicate.
        $posts = get_posts([
            'post_type' => $binding['post_type'],
            'post_status' => 'any',
            'name' => $binding['slug'],
            'numberposts' => 2,
            'meta_query' => [spacefast_content_space_meta_clause()],
        ]);
        if (count($posts) > 1) {
            throw new Spacefast_Content_Error(409, 'content_document_identity_conflict', 'More than one post matches this sync binding.');
        }
        $post = $posts[0] ?? null;
    }
    if (is_int($post)) {
        $post = get_post($post);
    }
    return is_object($post) ? $post : null;
}

function spacefast_content_sync_read_blocks(object $post, array $binding): string
{
    return $binding['field_storage'] === 'post_content'
        ? (string) ($post->post_content ?? '')
        : (string) get_post_meta((int) $post->ID, $binding['field_storage'], true);
}

function spacefast_content_sync_save_document(array $input, string $blocks): int
{
    $binding = $input['binding'];
    $existing = spacefast_content_sync_find_post($input['bindingId'], $binding);
    if (is_object($existing) && (string) $existing->post_type !== $binding['post_type']) {
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'The binding post type changed.');
    }
    $slug = $binding['slug'];
    $post = [
        'post_type' => $binding['post_type'],
        'post_status' => is_object($existing) ? (string) $existing->post_status : 'draft',
        'post_name' => function_exists('sanitize_title') ? sanitize_title($slug) : $slug,
        'post_title' => is_object($existing) && (string) $existing->post_title !== ''
            ? (string) $existing->post_title
            : ucwords(str_replace(['-', '_'], ' ', $slug)),
    ];
    if (is_object($existing)) {
        $post['ID'] = (int) $existing->ID;
    }
    if ($binding['field_storage'] === 'post_content') {
        $post['post_content'] = $blocks;
    }
    $saved = wp_insert_post($post, true);
    if (function_exists('is_wp_error') && is_wp_error($saved)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The synchronized post could not be written.');
    }
    $postId = (int) $saved;
    update_post_meta($postId, SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $input['bindingId']);
    update_post_meta($postId, SPACEFAST_CONTENT_SPACE_META, spacefast_content_require_space_id());
    if ($binding['field_storage'] !== 'post_content') {
        update_post_meta($postId, $binding['field_storage'], $blocks);
    }
    return $postId;
}

function spacefast_content_sync_save_revision(int $postId): int
{
    $revisionId = function_exists('wp_save_post_revision') ? wp_save_post_revision($postId) : 0;
    if ((!is_int($revisionId) || $revisionId < 1) && function_exists('_wp_put_post_revision')) {
        $revisionId = _wp_put_post_revision($postId);
    }
    return is_int($revisionId) && $revisionId > 0 ? $revisionId : $postId;
}

function spacefast_content_sync_current_revision_id(int $postId): int
{
    if (function_exists('wp_get_post_revisions')) {
        $revisions = wp_get_post_revisions($postId, ['posts_per_page' => 1, 'order' => 'DESC']);
        $revision = is_array($revisions) ? reset($revisions) : false;
        if (is_object($revision) && (int) ($revision->ID ?? 0) > 0) {
            return (int) $revision->ID;
        }
    }
    return $postId;
}

function spacefast_content_sync_ledger(int $postId): ?array
{
    $ledger = get_post_meta($postId, SPACEFAST_CONTENT_SYNC_LEDGER_META, true);
    if (is_string($ledger) && $ledger !== '') {
        $ledger = json_decode($ledger, true);
    }
    return is_array($ledger) && $ledger !== [] ? $ledger : null;
}

function spacefast_content_sync_store_ledger(int $postId, array $ledger): void
{
    update_post_meta($postId, SPACEFAST_CONTENT_SYNC_LEDGER_META, $ledger);
}

function spacefast_content_sync_receipt_key(string $operationId): string
{
    return substr(hash('sha256', $operationId), 0, 32);
}

/** The whole bounded receipt book for one post, oldest entry first. */
function spacefast_content_sync_receipts(int $postId): array
{
    $receipts = get_post_meta($postId, SPACEFAST_CONTENT_SYNC_RECEIPT_META, true);
    if (is_string($receipts) && $receipts !== '') {
        $receipts = json_decode($receipts, true);
    }
    return is_array($receipts) ? $receipts : [];
}

function spacefast_content_sync_receipt(int $postId, string $operationId): ?array
{
    $receipt = spacefast_content_sync_receipts($postId)[spacefast_content_sync_receipt_key($operationId)] ?? null;
    return is_array($receipt) && $receipt !== [] ? $receipt : null;
}

function spacefast_content_sync_store_receipt(int $postId, string $operationId, array $receipt): void
{
    $receipts = spacefast_content_sync_receipts($postId);
    $key = spacefast_content_sync_receipt_key($operationId);
    // Re-insert at the end so replaying an operation refreshes its place in the
    // eviction order rather than letting a still-live operation age out.
    unset($receipts[$key]);
    $receipts[$key] = $receipt;
    if (count($receipts) > SPACEFAST_CONTENT_SYNC_RECEIPT_LIMIT) {
        $receipts = array_slice($receipts, -SPACEFAST_CONTENT_SYNC_RECEIPT_LIMIT, null, true);
    }
    update_post_meta($postId, SPACEFAST_CONTENT_SYNC_RECEIPT_META, $receipts);
}

function spacefast_content_sync_representation(string $text, string $revision): array
{
    return [
        'text' => $text,
        'digest' => spacefast_content_sync_digest_text($text),
        'revision' => $revision,
    ];
}

function spacefast_content_sync_throw_conflict(
    array $input,
    string $baseText,
    string $baseRevision,
    string $wordpressText,
    int $wordpressRevisionId
): never {
    throw new Spacefast_Content_Conflict(
        $input['bindingId'],
        spacefast_content_sync_representation($baseText, $baseRevision),
        spacefast_content_sync_representation($input['text'], $input['observedSourceRevision']),
        spacefast_content_sync_representation($wordpressText, (string) $wordpressRevisionId)
            + ['wordpressRevisionId' => $wordpressRevisionId]
    );
}

/**
 * A reconciliation the two sides cannot settle without a human. It carries the
 * three representations the caller needs to show a diff, which is why it is not
 * an ordinary Spacefast_Content_Error.
 */
final class Spacefast_Content_Conflict extends RuntimeException
{
    public readonly int $status;
    public readonly string $codeName;

    public function __construct(
        public readonly string $bindingId,
        public readonly array $base,
        public readonly array $source,
        public readonly array $wordpress,
    ) {
        $this->status = 409;
        $this->codeName = 'content_sync_conflict';
        parent::__construct('The source and WordPress document both changed after the common base.');
    }

    /** @return array<string,mixed> */
    public function details(): array
    {
        return [
            'bindingId' => $this->bindingId,
            'base' => $this->base,
            'source' => $this->source,
            'wordpress' => $this->wordpress,
        ];
    }
}

function spacefast_content_sync_with_transaction(callable $operation): array
{
    global $wpdb;
    $transactional = is_object($wpdb) && method_exists($wpdb, 'query');
    if ($transactional && $wpdb->query('START TRANSACTION') === false) {
        throw new Spacefast_Content_Error(503, 'content_transaction_unavailable', 'WordPress could not start a content transaction.');
    }
    try {
        $result = $operation();
        if ($transactional && $wpdb->query('COMMIT') === false) {
            throw new Spacefast_Content_Error(500, 'content_transaction_failed', 'WordPress could not commit the content transaction.');
        }
        return $result;
    } catch (Throwable $error) {
        if ($transactional) {
            $wpdb->query('ROLLBACK');
        }
        throw $error;
    }
}

/**
 * Serialize reconciliation per Space. Two syncs racing on one binding would
 * otherwise both read the same common base and both believe they won.
 */
function spacefast_content_sync_locked(callable $critical): array
{
    $privateRoot = $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] ?? null;
    if (!is_string($privateRoot) || $privateRoot === '' || !function_exists('_stattic_space_write_lock_with')) {
        return $critical();
    }
    return _stattic_space_write_lock_with(
        $privateRoot,
        spacefast_content_require_space_id(),
        STATTIC_LOCK_WAIT,
        static fn (): never => throw new Spacefast_Content_Error(
            503,
            'content_sync_busy',
            'Another change to this Space is in flight. Retry the sync.'
        ),
        static fn (): array => $critical()
    );
}

/**
 * Run a reconciliation with the source-change journal suppressed.
 *
 * Push and merge both write the post, which fires `save_post`. That save is the
 * reconciliation, not a change to reconcile, so journalling it would hand the
 * drain back the answer it had just produced. The flag is a global rather than
 * a parameter because the seam it has to reach is a WordPress hook.
 */
function spacefast_content_sync_without_journal(callable $operation): array
{
    $previous = $GLOBALS['SPACEFAST_CONTENT_SYNC_RECONCILING'] ?? false;
    $GLOBALS['SPACEFAST_CONTENT_SYNC_RECONCILING'] = true;
    try {
        return $operation();
    } finally {
        $GLOBALS['SPACEFAST_CONTENT_SYNC_RECONCILING'] = $previous;
    }
}

function spacefast_content_reconcile_source(array $request, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Source sync requires Spacefast authorization.');
    }
    $input = spacefast_content_sync_parse_reconcile($request);
    return spacefast_content_sync_without_journal(
        static fn (): array => spacefast_content_sync_locked(
            static fn (): array => spacefast_content_sync_reconcile_locked($input)
        )
    );
}

function spacefast_content_sync_reconcile_locked(array $input): array
{
    $binding = $input['binding'];
    $format = $input['format'];
    $existing = spacefast_content_sync_find_post($input['bindingId'], $binding);
    if (is_object($existing)) {
        // Idempotence: a retried operation returns its first receipt rather
        // than reconciling a second time against a base it already moved.
        $replayed = spacefast_content_sync_receipt((int) $existing->ID, $input['operationId']);
        if (is_array($replayed)) {
            return $replayed;
        }
    }
    // Canonicalizing before anything is compared is what makes an unchanged
    // file stay unchanged: the raw bytes and the ledger's spelling differ by
    // whatever the serializer normalizes.
    $input['text'] = spacefast_content_sync_canonical_text($format, $input['text']);

    return spacefast_content_sync_with_transaction(
        static fn (): array => $input['state'] === 'initial'
            ? spacefast_content_sync_bind($input, $existing)
            : spacefast_content_sync_reconcile_bound($input, $existing)
    );
}

/** First sync of a binding: establish the common base. */
function spacefast_content_sync_bind(array $input, ?object $existing): array
{
    $binding = $input['binding'];
    $format = $input['format'];
    $ledger = is_object($existing) ? spacefast_content_sync_ledger((int) $existing->ID) : null;
    if (is_array($ledger)) {
        throw new Spacefast_Content_Error(409, 'content_sync_already_bound', 'This binding already has a common base.');
    }
    $blocks = is_object($existing) ? spacefast_content_sync_read_blocks($existing, $binding) : '';
    $wrote = false;
    if (is_object($existing) && trim($blocks) !== '') {
        // The post already has content nobody agreed a base for. Adopting it
        // silently would overwrite one side, so the two must already agree.
        if (!spacefast_content_sync_representable($format, $blocks)) {
            spacefast_content_sync_not_representable($format, 'Bind it from WordPress instead.');
        }
        $wordpressText = spacefast_content_sync_canonical_text(
            $format,
            spacefast_content_sync_from_blocks($format, $blocks)
        );
        if ($wordpressText !== $input['text']) {
            spacefast_content_sync_throw_conflict(
                $input,
                '',
                'unbound',
                $wordpressText,
                spacefast_content_sync_current_revision_id((int) $existing->ID)
            );
        }
        $postId = (int) $existing->ID;
    } else {
        $postId = spacefast_content_sync_save_document($input, spacefast_content_sync_to_blocks($format, $input['text']));
        $wrote = true;
    }
    $post = get_post($postId);
    if (!is_object($post)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The synchronized post could not be read.');
    }
    $revisionId = $wrote
        ? spacefast_content_sync_save_revision($postId)
        : spacefast_content_sync_current_revision_id($postId);
    $ledger = spacefast_content_sync_make_ledger(
        $input,
        $input['text'],
        spacefast_content_sync_read_blocks($post, $binding),
        $revisionId,
        'push'
    );
    spacefast_content_sync_store_ledger($postId, $ledger);
    $receipt = spacefast_content_sync_receipt_payload('created', $input['operationId'], $ledger);
    spacefast_content_sync_store_receipt($postId, $input['operationId'], $receipt);
    return $receipt;
}

/** Every later sync: three-way reconcile against the stored common base. */
function spacefast_content_sync_reconcile_bound(array $input, ?object $post): array
{
    $binding = $input['binding'];
    $format = $input['format'];
    $ledger = is_object($post) ? spacefast_content_sync_ledger((int) $post->ID) : null;
    if (!is_object($post) || !is_array($ledger)) {
        throw new Spacefast_Content_Error(409, 'content_sync_not_bound', 'Initialize this binding first.');
    }
    if ($ledger['bindingId'] !== $input['bindingId'] || $ledger['source'] !== $input['source']) {
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'The binding identity changed.');
    }
    // A ledger written before the HTML sync format named the common base
    // `baseMarkdown`/`markdownDigest`. Read it under the format-neutral names so
    // an already-bound Markdown space is not misread as fully changed (which
    // would spuriously re-push) on its first reconcile after the deploy. The
    // stored `revision` is untouched, so the compare-and-swap below still holds.
    $ledger['baseText'] ??= $ledger['baseMarkdown'] ?? '';
    $ledger['textDigest'] ??= $ledger['markdownDigest'] ?? '';
    $postId = (int) $post->ID;
    $wordpressBlocks = spacefast_content_sync_read_blocks($post, $binding);
    $wordpressRevisionId = spacefast_content_sync_current_revision_id($postId);

    // Compare-and-swap, fail closed. The caller reconciles against the base it
    // last saw; if the ledger has moved, its view is stale and the only safe
    // answer is to make it pull.
    if (!hash_equals((string) $ledger['revision'], (string) $input['baseRevision'])) {
        spacefast_content_sync_throw_conflict(
            $input,
            (string) $ledger['baseText'],
            (string) $ledger['revision'],
            spacefast_content_sync_from_blocks($format, $wordpressBlocks),
            $wordpressRevisionId
        );
    }

    $sourceChanged = !hash_equals(
        (string) $ledger['textDigest'],
        spacefast_content_sync_digest_text($input['text'])
    );
    $wordpressChanged = !hash_equals(
        (string) $ledger['blocksDigest'],
        spacefast_content_sync_digest_text($wordpressBlocks)
    );

    if (!$sourceChanged && !$wordpressChanged) {
        $next = spacefast_content_sync_make_ledger(
            $input,
            (string) $ledger['baseText'],
            $wordpressBlocks,
            $wordpressRevisionId,
            (string) $ledger['lastDirection']
        );
        return spacefast_content_sync_commit($postId, $input, $next, 'unchanged');
    }

    if ($sourceChanged && !$wordpressChanged) {
        return spacefast_content_sync_push($postId, $input);
    }

    // Anything below writes WordPress content back out to the source file, so
    // the representability gate applies before the content is flattened.
    $wordpressText = spacefast_content_sync_pullable_text($format, $wordpressBlocks);

    if (!$sourceChanged) {
        $next = spacefast_content_sync_make_ledger(
            $input,
            $wordpressText,
            $wordpressBlocks,
            $wordpressRevisionId,
            'pull'
        );
        return spacefast_content_sync_commit($postId, $input, $next, 'pulled');
    }

    // Both sides moved. This is the reconciliation the lane exists for:
    // non-overlapping edits merge, overlapping ones are a conflict.
    $merge = spacefast_content_sync_three_way(
        (string) $ledger['baseText'],
        $input['text'],
        $wordpressText
    );
    if ($merge['conflicts']) {
        spacefast_content_sync_throw_conflict(
            $input,
            (string) $ledger['baseText'],
            (string) $ledger['revision'],
            $wordpressText,
            $wordpressRevisionId
        );
    }
    $merged = spacefast_content_sync_canonical_text($format, $merge['merged']);
    $mergedBlocks = spacefast_content_sync_to_blocks($format, $merged);
    $mergedInput = $input;
    $mergedInput['text'] = $merged;
    $savedId = spacefast_content_sync_save_document($mergedInput, $mergedBlocks);
    $savedPost = get_post($savedId);
    if (!is_object($savedPost)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The merged post could not be read.');
    }
    $next = spacefast_content_sync_make_ledger(
        $mergedInput,
        $merged,
        spacefast_content_sync_read_blocks($savedPost, $binding),
        spacefast_content_sync_save_revision($savedId),
        'pull'
    );
    // A merge settles both sides, so the source file still has to be written:
    // that is a prepared source write, the same shape a pull produces.
    return spacefast_content_sync_commit($savedId, $mergedInput, $next, 'pulled');
}

/** The source text for a document that is about to overwrite a repo file. */
function spacefast_content_sync_pullable_text(string $format, string $blocks): string
{
    if (!spacefast_content_sync_representable($format, $blocks)) {
        spacefast_content_sync_not_representable($format, 'Its source file was left untouched.');
    }
    return spacefast_content_sync_canonical_text($format, spacefast_content_sync_from_blocks($format, $blocks));
}

function spacefast_content_sync_push(int $postId, array $input): array
{
    $blocks = spacefast_content_sync_to_blocks($input['format'], $input['text']);
    $savedId = spacefast_content_sync_save_document($input, $blocks);
    $post = get_post($savedId);
    if (!is_object($post)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The synchronized post could not be read.');
    }
    $next = spacefast_content_sync_make_ledger(
        $input,
        $input['text'],
        spacefast_content_sync_read_blocks($post, $input['binding']),
        spacefast_content_sync_save_revision($savedId),
        'push'
    );
    return spacefast_content_sync_commit($savedId, $input, $next, 'pushed');
}

function spacefast_content_sync_commit(int $postId, array $input, array $ledger, string $status): array
{
    spacefast_content_sync_store_ledger($postId, $ledger);
    $receipt = spacefast_content_sync_receipt_payload(
        $status,
        $input['operationId'],
        $ledger,
        $status === 'pulled' ? spacefast_content_sync_prepared_source_write($input, $ledger) : null
    );
    spacefast_content_sync_store_receipt($postId, $input['operationId'], $receipt);
    return $receipt;
}

/**
 * Close a prepared source write.
 *
 * A `pulled` receipt hands the caller source text plus the revision it must
 * compare-and-swap against. Until the caller says it landed those bytes, the
 * ledger cannot claim a source revision that may never have been written. This
 * is the acknowledgement half of that exchange.
 */
function spacefast_content_acknowledge_source(array $request, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Source sync requires Spacefast authorization.');
    }
    $bindingId = $request['bindingId'] ?? null;
    $operationId = $request['operationId'] ?? null;
    $baseRevision = $request['baseRevision'] ?? null;
    if (
        ($request['format'] ?? null) !== 'spacefast.content-sync-ack'
        || ($request['version'] ?? null) !== 1
        || !is_string($bindingId)
        || !spacefast_content_model_is_stable_id($bindingId)
        || !is_string($operationId)
        || preg_match('/^op_[A-Za-z0-9]+$/', $operationId) !== 1
        || !is_string($baseRevision)
        || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $baseRevision) !== 1
    ) {
        throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The acknowledgement request is invalid.');
    }
    $binding = spacefast_content_model_sync_binding($bindingId);
    if (!is_array($binding)) {
        throw new Spacefast_Content_Error(404, 'content_sync_binding_not_found', 'This ContentModelRelease has no such sync binding.');
    }
    return spacefast_content_sync_locked(static function () use ($bindingId, $binding, $operationId, $baseRevision): array {
        $post = spacefast_content_sync_find_post($bindingId, $binding);
        $ledger = is_object($post) ? spacefast_content_sync_ledger((int) $post->ID) : null;
        if (!is_object($post) || !is_array($ledger)) {
            throw new Spacefast_Content_Error(409, 'content_sync_not_bound', 'Initialize this binding first.');
        }
        $receipt = spacefast_content_sync_receipt((int) $post->ID, $operationId);
        if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'pulled') {
            throw new Spacefast_Content_Error(409, 'content_sync_not_prepared', 'This operation did not prepare a source write.');
        }
        // Fail closed on the base the caller claims it wrote: acknowledging a
        // revision the ledger has already moved past would bind bytes nobody
        // reconciled.
        if (!hash_equals((string) $ledger['revision'], $baseRevision)) {
            throw new Spacefast_Content_Error(409, 'content_sync_stale_acknowledgement', 'The sync ledger moved after this source write was prepared.');
        }
        return [
            'format' => 'spacefast.content-sync-ack-receipt',
            'version' => 1,
            'status' => 'acknowledged',
            'operationId' => $operationId,
            'ledger' => $ledger,
        ];
    });
}
