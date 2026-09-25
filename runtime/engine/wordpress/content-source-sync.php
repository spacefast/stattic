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
 * A versioned document envelope carries title, slug, publication state and
 * original component provenance. Body serialization lives in content-markdown.php
 * and content-html.php. Metadata and body merge independently against the same
 * common base.
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
/**
 * What a compile-class binding is handed back as when the editor takes its page
 * over. HTML is the interchange format with the widest block coverage: a page
 * authored as code must not be returned in the format most likely to refuse it.
 */
const SPACEFAST_CONTENT_SYNC_TAKEOVER_FORMATS = ['tsx' => 'html'];
/**
 * The path a document's bytes have already been prepared for. A materialization
 * mints one path per document, once; everything after it is the ordinary
 * two-way lane against the file that path named.
 */
const SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META = '_spacefast_source_materialized';
const SPACEFAST_CONTENT_SOURCE_PREPARED_META = '_spacefast_source_prepared';
const SPACEFAST_CONTENT_SOURCE_RETIRED_META = '_spacefast_source_retired';

/** A source document carries metadata separately from its editable body. */
function spacefast_content_sync_document(string $text): array
{
    if (!str_starts_with($text, '<!-- spacefast:document ')) {
        return ['metadata' => null, 'body' => $text];
    }
    $end = strpos($text, ' -->');
    $metadata = $end === false ? null : json_decode(substr($text, 24, $end - 24), true);
    $dateGmtPresent = is_array($metadata) && array_key_exists('dateGmt', $metadata);
    $dateGmt = $dateGmtPresent ? spacefast_content_sync_date_normalize($metadata['dateGmt']) : null;
    if (!is_array($metadata) || array_diff(array_keys($metadata), ['version', 'title', 'slug', 'status', 'componentSource', 'dateGmt']) !== []
        || ($metadata['version'] ?? null) !== 1
        || !is_string($metadata['title'] ?? null) || strlen($metadata['title']) > 2000
        || !is_string($metadata['slug'] ?? null) || strlen($metadata['slug']) > 200
        || !in_array($metadata['status'] ?? null, ['draft', 'pending', 'publish', 'private', 'future', 'trash'], true)
        || (isset($metadata['componentSource']) && !spacefast_content_sync_source_valid($metadata['componentSource']))
        || ($dateGmtPresent && $dateGmt === null)
        || (($metadata['status'] ?? null) === 'future' && !$dateGmtPresent)) {
        throw new Spacefast_Content_Error(422, 'content_document_metadata_invalid', 'The source document metadata is invalid.');
    }
    if ($dateGmt !== null) $metadata['dateGmt'] = $dateGmt;
    return ['metadata' => $metadata, 'body' => ltrim(substr($text, $end + 4), "\r\n")];
}

function spacefast_content_sync_date_normalize(mixed $date): ?string
{
    if (!is_string($date) || preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?Z$/D', $date, $matches) !== 1) {
        return null;
    }
    $canonical = $matches[1] . 'Z';
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $canonical, new DateTimeZone('UTC'));
    return $parsed !== false && $parsed->format('Y-m-d\TH:i:s\Z') === $canonical ? $canonical : null;
}

function spacefast_content_sync_date_valid(mixed $date): bool
{
    return spacefast_content_sync_date_normalize($date) !== null;
}

function spacefast_content_sync_envelope(?array $metadata, string $body): string
{
    if ($metadata === null) {
        return $body;
    }
    $json = str_replace(['<', '>'], ['\\u003c', '\\u003e'], spacefast_content_sync_canonical_json($metadata));
    return '<!-- spacefast:document ' . $json . " -->\n" . $body;
}

/** Missing metadata leaves the database fields unchanged during source adoption. */
function spacefast_content_sync_source_agrees(string $source, string $wordpress): bool
{
    $left = spacefast_content_sync_document($source);
    $right = spacefast_content_sync_document($wordpress);
    if ($left['body'] !== $right['body']) return false;
    foreach ($left['metadata'] ?? [] as $key => $value) {
        if (($right['metadata'][$key] ?? null) !== $value) return false;
    }
    return true;
}

function spacefast_content_sync_document_metadata(object $post): array
{
    $metadata = [
        'version' => 1,
        'title' => (string) ($post->post_title ?? ''),
        'slug' => (string) ($post->post_name ?? ''),
        'status' => (string) ($post->post_status ?? 'draft'),
    ];
    $date = str_replace(' ', 'T', (string) ($post->post_date_gmt ?? '')) . 'Z';
    if (spacefast_content_sync_date_valid($date)) $metadata['dateGmt'] = $date;
    return $metadata;
}

function spacefast_content_sync_metadata_digest(object $post): string
{
    return spacefast_content_sync_digest_text(spacefast_content_sync_canonical_json(spacefast_content_sync_document_metadata($post)));
}

function spacefast_content_sync_document_changed(array $ledger, object $post, array $binding): bool
{
    return !hash_equals((string) ($ledger['blocksDigest'] ?? ''), spacefast_content_sync_digest_text(spacefast_content_sync_read_blocks($post, $binding)))
        || !isset($ledger['metadataDigest'])
        || !hash_equals($ledger['metadataDigest'], spacefast_content_sync_metadata_digest($post));
}

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
    $text = spacefast_content_sync_document($text)['body'];
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
    $document = spacefast_content_sync_document($text);
    $body = match ($format) {
        'md' => spacefast_content_markdown_canonical($document['body']),
        'html' => spacefast_content_html_canonical($document['body']),
    };
    return spacefast_content_sync_envelope($document['metadata'], $body);
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
    $baseDocument = spacefast_content_sync_document($base);
    $sourceDocument = spacefast_content_sync_document($source);
    $wordpressDocument = spacefast_content_sync_document($wordpress);
    $baseMetadata = $baseDocument['metadata'];
    $sourceMetadata = $sourceDocument['metadata'] ?? $baseMetadata;
    $wordpressMetadata = $wordpressDocument['metadata'] ?? $baseMetadata;
    $metadata = null;
    if ($sourceMetadata !== null || $wordpressMetadata !== null) {
        $metadata = [];
        foreach (['version', 'title', 'slug', 'status', 'componentSource', 'dateGmt'] as $key) {
            $before = $baseMetadata[$key] ?? null;
            $left = $sourceMetadata[$key] ?? $before;
            $right = $wordpressMetadata[$key] ?? $before;
            if ($left !== $before && $right !== $before && $left !== $right) {
                return ['merged' => '', 'conflicts' => true];
            }
            $value = $left === $before ? $right : $left;
            if ($value !== null) $metadata[$key] = $value;
        }
    }
    $base = $baseDocument['body'];
    $source = $sourceDocument['body'];
    $wordpress = $wordpressDocument['body'];
    spacefast_content_sync_require_toolkit();
    try {
        $strategy = new WordPress\Merge\MergeStrategy(
            new WordPress\Merge\Diff\LineDiffer(),
            new WordPress\Merge\Merge\LineMerger(),
            null
        );
        $result = $strategy->merge($base, $source, $wordpress);
        return [
            'merged' => spacefast_content_sync_envelope($metadata, (string) $result->get_merged_content()),
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
    object $post,
    int $wordpressRevisionId,
    string $direction
): array {
    $metadataPost = clone $post;
    $metadata = $input['format'] === 'tsx' ? null : spacefast_content_sync_document($text)['metadata'];
    foreach (['title' => 'post_title', 'slug' => 'post_name', 'status' => 'post_status'] as $key => $column) {
        if (isset($metadata[$key])) $metadataPost->{$column} = $metadata[$key];
    }
    if (isset($metadata['dateGmt'])) $metadataPost->post_date_gmt = str_replace('T', ' ', substr($metadata['dateGmt'], 0, -1));
    $ledger = [
        'version' => 1,
        'bindingId' => $input['bindingId'],
        'source' => $input['source'],
        'format' => $input['format'],
        'baseText' => $text,
        'textDigest' => spacefast_content_sync_digest_text($text),
        'blocksDigest' => spacefast_content_sync_digest_text(spacefast_content_sync_read_blocks($post, $input['binding'])),
        'metadataDigest' => spacefast_content_sync_metadata_digest($metadataPost),
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

function spacefast_content_sync_find_post(string $bindingId, array $binding, bool $adoptUnbound = true): ?object
{
    $externalId = SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $bindingId;
    $posts = get_posts([
        'post_type' => 'any',
        'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
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
    if ($post === null && $adoptUnbound) {
        $canonicalPage = ($binding['post_type'] ?? null) === 'page'
            && preg_match('/\Async\.pages\.[a-f0-9]{32}\z/D', $bindingId) === 1;
        $query = [
            'post_type' => $binding['post_type'],
            'post_status' => ['publish', 'future', 'draft', 'pending', 'private', 'trash'],
            'numberposts' => 2,
            'meta_query' => [spacefast_content_space_meta_clause()],
        ];
        $preparedMatch = null;
        if ($canonicalPage) {
            // Editor-created documents are adopted only by their prepared source
            // path, never by a URL basename that another document could share.
            $query['meta_query'][] = [
                'key' => SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META,
                'value' => $binding['source'],
                'compare' => '=',
            ];
        } else {
            $preparedQuery = $query;
            $preparedQuery['meta_query'][] = ['key' => SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META, 'value' => $binding['source'], 'compare' => '='];
            $preparedPosts = get_posts($preparedQuery);
            if (count($preparedPosts) > 1) {
                throw new Spacefast_Content_Error(409, 'content_document_identity_conflict', 'More than one post prepared this source.');
            }
            if (count($preparedPosts) === 1) {
                $preparedMatch = $preparedPosts;
            }
            $query['post_name__in'] = [$binding['slug']];
            $collection = spacefast_content_model_collection_projection($binding['resourceId']);
            $term = $collection['collection_term'] ?? null;
            $query['tax_query'] = [is_string($term)
                ? ['taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, 'field' => 'slug', 'terms' => [$term]]
                : ['taxonomy' => SPACEFAST_CONTENT_MODEL_COLLECTION_TAXONOMY, 'operator' => 'NOT EXISTS']];
        }
        $posts = $preparedMatch ?? get_posts($query);
        if (count($posts) > 1) {
            throw new Spacefast_Content_Error(409, 'content_document_identity_conflict', 'More than one post matches this sync binding.');
        }
        $post = $posts[0] ?? null;
        if (is_int($post)) $post = get_post($post);
        if (is_object($post)) {
            $owner = get_post_meta((int) $post->ID, SPACEFAST_CONTENT_EXTERNAL_ID_META, true);
            if (is_string($owner) && $owner !== '' && $owner !== $externalId) {
                throw new Spacefast_Content_Error(409, 'content_document_identity_conflict', 'This document belongs to a different source binding.');
            }
        }
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

/** Retired source ownership cannot become a mutable WordPress fallback. */
function spacefast_content_source_is_retired(int $postId): bool
{
    return is_array(get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_RETIRED_META, true));
}

function spacefast_content_sync_track_post(int $postId): void
{
    if (isset($GLOBALS['SPACEFAST_CONTENT_SYNC_TRANSACTION_POSTS']) && $postId > 0) {
        $GLOBALS['SPACEFAST_CONTENT_SYNC_TRANSACTION_POSTS'][$postId] = true;
    }
}

function spacefast_content_sync_lock_post(int $postId): void
{
    spacefast_content_sync_track_post($postId);
    global $wpdb;
    if (isset($wpdb->posts, $wpdb->postmeta)) {
        if ($wpdb->query($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $postId)) === false
            || $wpdb->query($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d FOR UPDATE", $postId)) === false) {
            throw new Spacefast_Content_Error(503, 'content_sync_busy', 'The document could not be locked.');
        }
        clean_post_cache($postId);
    }
}

function spacefast_content_sync_retire_documents(?array $previous, ?array $next, bool $commit): array
{
    $retired = [];
    $retainedIds = array_column($next['syncBindings'] ?? [], 'id');
    foreach ($previous['syncBindings'] ?? [] as $declaration) {
        if (in_array($declaration['id'], $retainedIds, true)) continue;
        $binding = [
            'resourceId' => $declaration['resourceId'], 'source' => $declaration['source'],
            'post_type' => $declaration['postType'], 'slug' => $declaration['slug'],
            'field_storage' => $declaration['fieldStorage'],
        ];
        $post = spacefast_content_sync_find_post($declaration['id'], $binding, false);
        if (!is_object($post)) continue;
        $postId = (int) $post->ID;
        spacefast_content_sync_lock_post($postId);
        $post = get_post($postId);
        if (!is_object($post) || !spacefast_content_post_belongs_to_space($postId)) {
            throw new Spacefast_Content_Error(409, 'content_document_identity_conflict', 'The removed source no longer owns its document.');
        }
        if (spacefast_content_source_is_retired($postId)) continue;
        $ledger = spacefast_content_sync_ledger($postId);
        if (!is_array($ledger) || ($ledger['bindingId'] ?? null) !== $declaration['id']
            || (!$commit && spacefast_content_sync_document_changed($ledger, $post, $binding))) {
            throw new Spacefast_Content_Error(409, 'content_document_retirement_conflict', 'The source was removed while its WordPress document had unpublished edits. Reconcile the document before removing its source.');
        }
        if (!$commit) continue;
        $before = ['status' => $post->post_status, 'slug' => $post->post_name];
        if ($post->post_status !== 'trash' && !wp_trash_post($postId)) {
            throw new Spacefast_Content_Error(500, 'content_document_retirement_failed', 'The removed source document could not be moved to trash.');
        }
        $trashed = get_post($postId);
        update_post_meta($postId, SPACEFAST_CONTENT_SOURCE_RETIRED_META, [
            'bindingId' => $declaration['id'], 'source' => $declaration['source'],
            'status' => $before['status'], 'slug' => $before['slug'],
            'blocksDigest' => spacefast_content_sync_digest_text(spacefast_content_sync_read_blocks($trashed, $binding)),
            'metadataDigest' => spacefast_content_sync_metadata_digest($trashed),
        ]);
        if (function_exists('wp_cache_set_comments_last_changed')) wp_cache_set_comments_last_changed();
        $retired[] = $postId;
    }
    return $retired;
}

function spacefast_content_sync_restore_retired(array $input, object $post): object
{
    $postId = (int) $post->ID;
    $retired = get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_RETIRED_META, true);
    if (!is_array($retired)) return $post;
    spacefast_content_sync_lock_post($postId);
    $post = get_post($postId);
    if ($retired['bindingId'] !== $input['bindingId'] || $retired['source'] !== $input['source']
        || !hash_equals($retired['blocksDigest'], spacefast_content_sync_digest_text(spacefast_content_sync_read_blocks($post, $input['binding'])))
        || !hash_equals($retired['metadataDigest'], spacefast_content_sync_metadata_digest($post))) {
        throw new Spacefast_Content_Error(409, 'content_document_retirement_conflict', 'The retired document changed. Resolve its WordPress edits before restoring source ownership.');
    }
    if ($retired['status'] !== 'trash' && !wp_untrash_post($postId)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The retired document could not be restored from trash.');
    }
    $saved = wp_update_post(['ID' => $postId, 'post_status' => $retired['status'], 'post_name' => $retired['slug']], true);
    if (is_wp_error($saved)) throw new Spacefast_Content_Error(500, 'content_write_failed', 'The retired document could not be restored.');
    delete_post_meta($postId, SPACEFAST_CONTENT_SOURCE_RETIRED_META);
    if (function_exists('wp_cache_set_comments_last_changed')) wp_cache_set_comments_last_changed();
    delete_post_meta($postId, '_spacefast_document_release');
    return get_post($postId);
}

/** Publish consumes a sealed source, not a prepared writeback to a mutable repository. */
function spacefast_content_sync_publish_document(array $input): array
{
    $post = spacefast_content_sync_find_post($input['bindingId'], $input['binding']);
    if (is_object($post)) $post = spacefast_content_sync_restore_retired($input, $post);
    $postId = is_object($post) ? (int) $post->ID : 0;
    if ($postId > 0 && get_post_meta($postId, '_spacefast_document_release', true) === $input['operationId']) {
        return ['postId' => $postId, 'status' => 'unchanged'];
    }
    $ledger = $postId > 0 ? spacefast_content_sync_ledger($postId) : null;
    $blocks = is_object($post) ? spacefast_content_sync_read_blocks($post, $input['binding']) : '';
    $materialized = $postId > 0 ? get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META, true) : '';
    if ($input['format'] === 'tsx') {
        if (is_string($materialized) && $materialized !== '') {
            throw new Spacefast_Content_Error(409, 'content_document_editor_owned', 'This document was taken over by the editor. Publish its canonical HTML source.');
        }
        // TSX seed bytes are already compiled Gutenberg markup, including dynamic blocks.
        $nextBlocks = $input['text'];
    } else {
        $input['text'] = spacefast_content_sync_canonical_text($input['format'], $input['text']);
        $sameBinding = is_array($ledger)
            && ($ledger['source'] ?? null) === $input['source']
            && ($ledger['format'] ?? null) === $input['format'];
        if ($sameBinding) {
            $sourceChanged = !hash_equals((string) $ledger['textDigest'], spacefast_content_sync_digest_text($input['text']));
            $editorChanged = spacefast_content_sync_document_changed($ledger, $post, $input['binding']);
            if (!$sourceChanged) {
                // Keep the common base: activation cannot claim the editor's bytes
                // were written back to the sealed source when they were not.
                update_post_meta($postId, '_spacefast_document_release', $input['operationId']);
                return ['postId' => $postId, 'status' => 'unchanged'];
            }
            if ($editorChanged) {
                $editorText = spacefast_content_sync_pullable_text($input['format'], $blocks, $post, is_array($ledger) ? $ledger['baseText'] : null);
                if (!spacefast_content_sync_source_agrees($input['text'], $editorText)) {
                    spacefast_content_sync_throw_conflict($input, (string) $ledger['baseText'], (string) $ledger['revision'], $editorText, spacefast_content_sync_current_revision_id($postId));
                }
            }
        } elseif (is_object($post) && is_array($preparedBase = get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_PREPARED_META, true))
            && ($preparedBase['source'] ?? null) === $input['source']
            && ($preparedBase['format'] ?? null) === $input['format']
            && hash_equals(spacefast_content_sync_digest_text($preparedBase['text']), spacefast_content_sync_digest_text($input['text']))) {
            // This exact source was prepared here. Adopt its common base while
            // keeping edits made while its commit and build were in flight.
            $next = spacefast_content_sync_make_ledger($input, $input['text'], $post, $preparedBase['wordpressRevisionId'], 'push');
            $next['blocksDigest'] = $preparedBase['blocksDigest'];
            $next['metadataDigest'] = $preparedBase['metadataDigest'];
            $next['revision'] = spacefast_content_sync_revision($next);
            update_post_meta($postId, SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $input['bindingId']);
            spacefast_content_sync_store_ledger($postId, $next);
            update_post_meta($postId, '_spacefast_document_release', $input['operationId']);
            return ['postId' => $postId, 'status' => 'adopted'];
        } elseif (is_object($post) && trim($blocks) !== '') {
            // Adoption and TSX -> HTML takeover must agree with the existing
            // document. A route-stable ID is not permission to discard edits.
            $editorText = spacefast_content_sync_pullable_text($input['format'], $blocks, $post, is_array($ledger) ? $ledger['baseText'] : null);
            if ((is_string($materialized) && $materialized !== '' && $materialized !== $input['source'])
                || !spacefast_content_sync_source_agrees($input['text'], $editorText)) {
                spacefast_content_sync_throw_conflict($input, '', 'unbound', $editorText, spacefast_content_sync_current_revision_id($postId));
            }
        }
        $nextBlocks = spacefast_content_sync_to_blocks($input['format'], $input['text']);
    }
    $input['publish'] = true;
    $savedId = spacefast_content_sync_save_document($input, $nextBlocks);
    $saved = get_post($savedId);
    if (!is_object($saved)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The published document could not be read.');
    }
    $next = spacefast_content_sync_make_ledger($input, $input['text'], $saved, spacefast_content_sync_save_revision($savedId), 'push');
    spacefast_content_sync_store_ledger($savedId, $next);
    update_post_meta($savedId, '_spacefast_document_release', $input['operationId']);
    return ['postId' => $savedId, 'status' => $postId > 0 ? 'pushed' : 'created'];
}

function spacefast_content_sync_save_document(array $input, string $blocks): int
{
    $binding = $input['binding'];
    $existing = spacefast_content_sync_find_post($input['bindingId'], $binding);
    if (is_object($existing) && (string) $existing->post_type !== $binding['post_type']) {
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'The binding post type changed.');
    }
    $slug = $binding['slug'];
    $canonicalPage = $binding['post_type'] === 'page'
        && preg_match('/\Async\.pages\.[a-f0-9]{32}\z/D', $input['bindingId']) === 1;
    if ($canonicalPage && is_string($binding['publicPath'] ?? null)) {
        $slug = trim($binding['publicPath'], '/') === '' ? 'home' : basename($binding['publicPath']);
    }
    $titleSource = $canonicalPage ? pathinfo(basename($input['source']), PATHINFO_FILENAME) : $slug;
    if ($canonicalPage && $titleSource === 'index') {
        $titleSource = 'Home';
    }
    $post = [
        'post_type' => $binding['post_type'],
        'post_status' => is_object($existing) ? (string) $existing->post_status : ($canonicalPage || !empty($input['publish']) ? 'publish' : 'draft'),
        'post_name' => is_object($existing) && (string) ($existing->post_name ?? '') !== ''
            ? (string) $existing->post_name
            : (function_exists('sanitize_title') ? sanitize_title($slug) : $slug),
        'post_title' => is_object($existing) && (string) $existing->post_title !== ''
            ? (string) $existing->post_title
            : ucwords(str_replace(['-', '_'], ' ', $titleSource)),
    ];
    $metadata = $input['format'] === 'tsx' ? null : spacefast_content_sync_document($input['text'])['metadata'];
    if ($metadata !== null) {
        $post['post_title'] = $metadata['title'];
        $post['post_name'] = $metadata['slug'];
        $post['post_status'] = $metadata['status'];
        if (isset($metadata['dateGmt'])) {
            $post['post_date_gmt'] = str_replace('T', ' ', substr($metadata['dateGmt'], 0, -1));
            $post['post_date'] = get_date_from_gmt($post['post_date_gmt']);
        }
    }
    if (is_object($existing)) {
        $post['ID'] = (int) $existing->ID;
        spacefast_content_sync_track_post((int) $existing->ID);
    }
    if ($binding['field_storage'] === 'post_content') {
        $post['post_content'] = $blocks;
    }
    $previousCollection = $GLOBALS['SPACEFAST_CONTENT_WRITE_COLLECTION'] ?? null;
    $GLOBALS['SPACEFAST_CONTENT_WRITE_COLLECTION'] = $binding['resourceId'];
    try {
        $saved = wp_insert_post($post, true);
    } finally {
        $GLOBALS['SPACEFAST_CONTENT_WRITE_COLLECTION'] = $previousCollection;
    }
    if (function_exists('is_wp_error') && is_wp_error($saved)) {
        throw new Spacefast_Content_Error(500, 'content_write_failed', 'The synchronized post could not be written.');
    }
    $postId = (int) $saved;
    spacefast_content_sync_track_post($postId);
    update_post_meta($postId, SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $input['bindingId']);
    update_post_meta($postId, SPACEFAST_CONTENT_SPACE_META, spacefast_content_require_space_id());
    spacefast_content_assign_collection($postId, $binding['resourceId']);
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
    $GLOBALS['SPACEFAST_CONTENT_SYNC_TRANSACTION_POSTS'] = [];
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
        if (function_exists('clean_post_cache')) {
            foreach (array_keys($GLOBALS['SPACEFAST_CONTENT_SYNC_TRANSACTION_POSTS']) as $postId) clean_post_cache($postId);
        }
        throw $error;
    } finally {
        unset($GLOBALS['SPACEFAST_CONTENT_SYNC_TRANSACTION_POSTS']);
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
        $wordpressText = spacefast_content_sync_pullable_text($format, $blocks, $existing, $input['text']);
        if (!spacefast_content_sync_source_agrees($input['text'], $wordpressText)) {
            spacefast_content_sync_throw_conflict(
                $input,
                '',
                'unbound',
                $wordpressText,
                spacefast_content_sync_current_revision_id((int) $existing->ID)
            );
        }
        $postId = (int) $existing->ID;
        update_post_meta($postId, SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $input['bindingId']);
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
        $post,
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
    $wordpressChanged = spacefast_content_sync_document_changed($ledger, $post, $binding);

    if (!$sourceChanged && !$wordpressChanged) {
        $next = spacefast_content_sync_make_ledger(
            $input,
            (string) $ledger['baseText'],
            $post,
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
    $wordpressText = spacefast_content_sync_pullable_text($format, $wordpressBlocks, $post, (string) $ledger['baseText']);

    if (!$sourceChanged) {
        $next = spacefast_content_sync_make_ledger(
            $input,
            $wordpressText,
            $post,
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
        $savedPost,
        spacefast_content_sync_save_revision($savedId),
        'pull'
    );
    // A merge settles both sides, so the source file still has to be written:
    // that is a prepared source write, the same shape a pull produces.
    return spacefast_content_sync_commit($savedId, $mergedInput, $next, 'pulled');
}

/** The source text for a document that is about to overwrite a repo file. */
function spacefast_content_sync_pullable_text(string $format, string $blocks, ?object $post = null, ?string $baseText = null): string
{
    if (!spacefast_content_sync_representable($format, $blocks)) {
        spacefast_content_sync_not_representable($format, 'Its source file was left untouched.');
    }
    $text = spacefast_content_sync_canonical_text($format, spacefast_content_sync_from_blocks($format, $blocks));
    if ($post === null) return $text;
    $metadata = spacefast_content_sync_document_metadata($post);
    $base = $baseText === null ? null : spacefast_content_sync_document($baseText)['metadata'];
    if (isset($base['componentSource'])) $metadata['componentSource'] = $base['componentSource'];
    return spacefast_content_sync_envelope($metadata, $text);
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
        $post,
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

/** `pages/docs/about.tsx` becomes `pages/docs/about.html`. */
function spacefast_content_sync_takeover_source(string $source, string $format): string
{
    $dot = strrpos($source, '.');
    $slash = strrpos($source, '/');
    $stem = $dot === false || ($slash !== false && $dot < $slash) ? $source : substr($source, 0, $dot);
    return $stem . '.' . $format;
}

function spacefast_content_sync_materialize_invalid(): never
{
    throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The materialization request is invalid.');
}

/**
 * Hand the control plane the file this document's bytes belong in.
 *
 * Two callers, one answer. A post the editor created under a materializing
 * collection has never had a file; a compile-class binding's page has a file
 * nothing can write back to. Both get a NEW path and the canonical text of what
 * WordPress holds, in the format that path implies.
 *
 * The first prepared document remains the common base until a release adopts
 * it. Later saves keep their WordPress bytes; activation binds that exact source
 * and journals any difference for the next normal reconciliation.
 */
function spacefast_content_materialize_source(array $request, bool $managed): array
{
    if (!$managed) {
        throw new Spacefast_Content_Error(401, 'content_auth_required', 'Source materialization requires Spacefast authorization.');
    }
    $input = spacefast_content_sync_parse_materialize($request);
    return spacefast_content_sync_without_journal(
        static fn (): array => spacefast_content_sync_locked(
            static fn (): array => spacefast_content_sync_materialize_locked($input)
        )
    );
}

/** @return array{operationId:string,post:object,format:string,source:string,field_storage:string} */
function spacefast_content_sync_parse_materialize(array $request): array
{
    $operationId = $request['operationId'] ?? null;
    if (!is_string($operationId) || preg_match('/^op_[A-Za-z0-9]+$/', $operationId) !== 1) {
        spacefast_content_sync_materialize_invalid();
    }
    $bindingId = $request['bindingId'] ?? null;
    $input = is_string($bindingId)
        ? spacefast_content_sync_materialize_takeover($operationId, $bindingId)
        : spacefast_content_sync_materialize_collection($operationId, $request['postId'] ?? null);
    // The serializer is the binding's or the release's, never the caller's, and
    // it has to be one this engine actually has.
    if (!in_array($input['format'], SPACEFAST_CONTENT_SYNC_FORMATS, true)) {
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'The materialization names no known source format.');
    }
    return $input;
}

/** The compile-class takeover: the binding's own source, in the format that can carry it. */
function spacefast_content_sync_materialize_takeover(string $operationId, string $bindingId): array
{
    if (!spacefast_content_model_is_stable_id($bindingId)) {
        spacefast_content_sync_materialize_invalid();
    }
    $binding = spacefast_content_model_sync_binding($bindingId);
    if (!is_array($binding)) {
        throw new Spacefast_Content_Error(404, 'content_sync_binding_not_found', 'This ContentModelRelease has no such sync binding.');
    }
    $format = SPACEFAST_CONTENT_SYNC_TAKEOVER_FORMATS[$binding['format']] ?? null;
    if (!is_string($format)) {
        // A two-way binding already has a file the editor's changes reconcile
        // into. Taking it over would abandon a source that can be written back.
        throw new Spacefast_Content_Error(409, 'content_sync_binding_conflict', 'This binding already has a two-way source file.');
    }
    $post = spacefast_content_sync_find_post($bindingId, $binding);
    if (!is_object($post)) {
        throw new Spacefast_Content_Error(404, 'content_document_not_found', 'This binding has no WordPress document to materialize.');
    }
    return [
        'operationId' => $operationId,
        'post' => $post,
        'format' => $format,
        'source' => spacefast_content_sync_takeover_source((string) $binding['source'], $format),
        'componentSource' => (string) $binding['source'],
        'field_storage' => (string) $binding['field_storage'],
    ];
}

/** A document the editor created under a collection that declares where files go. */
function spacefast_content_sync_materialize_collection(string $operationId, mixed $postId): array
{
    if (!is_int($postId) || $postId < 1 || !function_exists('get_post')) {
        spacefast_content_sync_materialize_invalid();
    }
    $post = get_post($postId);
    // Ownership first, and the refusal says nothing about whether a post another
    // Space holds exists at all.
    if (!is_object($post) || !spacefast_content_post_belongs_to_space($postId)) {
        throw new Spacefast_Content_Error(404, 'content_document_not_found', 'This Space has no such document.');
    }
    $collection = spacefast_content_collection_for_post($postId);
    $materialization = is_array($collection)
        ? spacefast_content_model_materialization((string) $collection['name'])
        : null;
    if ($materialization === null) {
        throw new Spacefast_Content_Error(409, 'content_materialization_not_declared', 'This collection does not materialize source files.');
    }
    $slug = (string) ($post->post_name ?? '');
    $slug = function_exists('sanitize_title') ? (string) sanitize_title($slug) : $slug;
    $source = rtrim($materialization['directory'], '/') . '/' . $slug . $materialization['suffix'];
    if ($slug === '' || !spacefast_content_sync_source_valid($source)) {
        throw new Spacefast_Content_Error(409, 'content_materialization_path_invalid', 'This document has no usable source path.');
    }
    return [
        'operationId' => $operationId,
        'post' => $post,
        'format' => $materialization['format'],
        'source' => $source,
        'field_storage' => $materialization['field_storage'],
    ];
}

function spacefast_content_sync_materialize_locked(array $input): array
{
    $post = $input['post'];
    $postId = (int) $post->ID;
    // Idempotence, layer two: a retried operationId replays its first answer
    // rather than deriving a second one against content that has since moved.
    $replayed = spacefast_content_sync_receipt($postId, $input['operationId']);
    if (is_array($replayed)) {
        return $replayed;
    }
    $preparedBase = get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_PREPARED_META, true);
    if (is_array($preparedBase)) {
        $receipt = [
            'format' => 'spacefast.content-materialize', 'version' => 1, 'status' => 'skipped',
            'operationId' => $input['operationId'],
            'sourceWrite' => [
                'state' => 'prepared', 'source' => $preparedBase['source'],
                'expectedSourceRevision' => $preparedBase['expectedSourceRevision'] ?? 'absent', 'text' => $preparedBase['text'],
                'textDigest' => spacefast_content_sync_digest_text($preparedBase['text']),
            ],
        ];
        spacefast_content_sync_store_receipt($postId, $input['operationId'], $receipt);
        return $receipt;
    }
    $blocks = spacefast_content_sync_read_blocks($post, ['field_storage' => $input['field_storage']]);
    // The existing serializer and its existing representability gate. A document
    // richer than its format refuses here exactly as a pull refuses, and the
    // drain already treats that refusal as terminal.
    $text = spacefast_content_sync_pullable_text($input['format'], $blocks, $post);
    if (isset($input['componentSource'])) {
        $document = spacefast_content_sync_document($text);
        $document['metadata']['componentSource'] = $input['componentSource'];
        $text = spacefast_content_sync_envelope($document['metadata'], $document['body']);
    }
    $prepared = function_exists('get_post_meta')
        ? get_post_meta($postId, SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META, true)
        : '';
    // Idempotence, layer one: a document that already has a path keeps it, so a
    // second materialization mints nothing and moves nothing.
    $already = is_string($prepared) && $prepared !== '';
    $receipt = [
        'format' => 'spacefast.content-materialize',
        'version' => 1,
        'status' => $already ? 'skipped' : 'materialized',
        'operationId' => $input['operationId'],
        'sourceWrite' => [
            'state' => 'prepared',
            'source' => $already ? $prepared : $input['source'],
            // A new file has no revision to stand on, so what the drain
            // compare-and-swaps against is nothing being there.
            'expectedSourceRevision' => 'absent',
            'text' => $text,
            'textDigest' => spacefast_content_sync_digest_text($text),
        ],
    ];
    if (!$already && function_exists('update_post_meta')) {
        update_post_meta($postId, SPACEFAST_CONTENT_SOURCE_MATERIALIZED_META, $input['source']);
    }
    update_post_meta($postId, SPACEFAST_CONTENT_SOURCE_PREPARED_META, [
        'source' => $receipt['sourceWrite']['source'], 'format' => $input['format'], 'text' => $text,
        'expectedSourceRevision' => 'absent',
        'blocksDigest' => spacefast_content_sync_digest_text($blocks),
        'metadataDigest' => spacefast_content_sync_metadata_digest($post),
        'wordpressRevisionId' => spacefast_content_sync_current_revision_id($postId),
    ]);
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

/** Prepared source exists before its first release establishes a normal binding. */
function spacefast_content_sync_pending_source(string $bindingId): ?array
{
    $binding = spacefast_content_model_sync_binding($bindingId);
    $synthetic = preg_match('/\Amaterialize\.([1-9][0-9]*)\z/D', $bindingId, $match) === 1;
    $post = $synthetic ? get_post((int) $match[1])
        : (is_array($binding) ? spacefast_content_sync_find_post($bindingId, $binding, false) : null);
    if (!is_object($post) || !spacefast_content_post_belongs_to_space((int) $post->ID)) return null;
    $prepared = get_post_meta((int) $post->ID, SPACEFAST_CONTENT_SOURCE_PREPARED_META, true);
    if (!is_array($prepared)) return null;
    $ledger = spacefast_content_sync_ledger((int) $post->ID);
    if (is_array($ledger) && ($ledger['source'] ?? null) === $prepared['source'] && ($ledger['format'] ?? null) === $prepared['format']) return null;
    $declaration = $synthetic
        ? spacefast_content_sync_materialize_collection('op_inspectmaterialization', (int) $post->ID)
        : spacefast_content_sync_materialize_takeover('op_inspectmaterialization', $bindingId);
    return [
        'post' => $post, 'binding' => $binding, 'synthetic' => $synthetic,
        'field_storage' => $declaration['field_storage'], 'prepared' => $prepared,
    ];
}

function spacefast_content_sync_pending_inspection(string $bindingId, array $pending): array
{
    $post = $pending['post'];
    $prepared = $pending['prepared'];
    $text = spacefast_content_sync_pullable_text($prepared['format'], spacefast_content_sync_read_blocks($post, $pending), $post, $prepared['text']);
    return [
        'bindingId' => $bindingId, 'postId' => (int) $post->ID, 'source' => $prepared['source'],
        'baseRevision' => spacefast_content_sync_digest_text(spacefast_content_sync_canonical_json($prepared)),
        'baseText' => $prepared['text'], 'wordpressText' => $text,
        'wordpressDigest' => spacefast_content_sync_digest_text($text),
        'wordpressRevisionId' => spacefast_content_sync_current_revision_id((int) $post->ID),
    ];
}

function spacefast_content_sync_resolve_pending(array $request): array
{
    foreach (['expectedBaseRevision', 'expectedWordpressDigest'] as $field) {
        if (!is_string($request[$field] ?? null) || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $request[$field]) !== 1) {
            throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The expected document revision is invalid.');
        }
    }
    if (!is_string($request['operationId'] ?? null) || preg_match('/\Aop_[A-Za-z0-9]+\z/D', $request['operationId']) !== 1
        || !is_string($request['text'] ?? null) || strlen($request['text']) > SPACEFAST_CONTENT_SYNC_MAX_TEXT_BYTES
        || !is_string($request['observedSourceRevision'] ?? null) || $request['observedSourceRevision'] === '' || strlen($request['observedSourceRevision']) > 512) {
        throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The materialization resolution is invalid.');
    }
    return spacefast_content_sync_without_journal(static fn (): array => spacefast_content_sync_locked(
        static fn (): array => spacefast_content_sync_with_transaction(static function () use ($request): array {
            $pending = spacefast_content_sync_pending_source($request['bindingId']);
            if ($pending === null) throw new Spacefast_Content_Error(409, 'content_sync_resolution_stale', 'Source ownership changed. Refresh the comparison.');
            $postId = (int) $pending['post']->ID;
            spacefast_content_sync_lock_post($postId);
            $pending = spacefast_content_sync_pending_source($request['bindingId']);
            if ($pending === null) throw new Spacefast_Content_Error(409, 'content_sync_resolution_stale', 'Source ownership changed. Refresh the comparison.');
            $replay = spacefast_content_sync_receipt($postId, $request['operationId']);
            if (is_array($replay) && ($replay['format'] ?? null) === 'spacefast.content-materialize') {
                return ['operationId' => $request['operationId'], 'bindingId' => $request['bindingId'], 'postId' => $postId, 'status' => 'queued'];
            }
            $observed = spacefast_content_sync_pending_inspection($request['bindingId'], $pending);
            if (($request['source'] ?? null) !== $observed['source']
                || !hash_equals($request['expectedBaseRevision'], $observed['baseRevision'])
                || !hash_equals($request['expectedWordpressDigest'], $observed['wordpressDigest'])) {
                throw new Spacefast_Content_Error(409, 'content_sync_resolution_stale', 'The document changed. Refresh the comparison.');
            }
            $prepared = $pending['prepared'];
            $document = spacefast_content_sync_document($request['text']);
            $original = spacefast_content_sync_document($prepared['text']);
            if (isset($original['metadata']['componentSource'])) {
                $document['metadata'] ??= spacefast_content_sync_document_metadata($pending['post']);
                $document['metadata']['componentSource'] = $original['metadata']['componentSource'];
            }
            $text = spacefast_content_sync_canonical_text($prepared['format'], spacefast_content_sync_envelope($document['metadata'], $document['body']));
            $blocks = spacefast_content_sync_to_blocks($prepared['format'], $text);
            $update = ['ID' => $postId];
            $metadata = spacefast_content_sync_document($text)['metadata'];
            if ($metadata !== null) {
                $update += ['post_title' => $metadata['title'], 'post_name' => $metadata['slug'], 'post_status' => $metadata['status']];
                if (isset($metadata['dateGmt'])) {
                    $update['post_date_gmt'] = str_replace('T', ' ', substr($metadata['dateGmt'], 0, -1));
                    $update['post_date'] = get_date_from_gmt($update['post_date_gmt']);
                }
            }
            if ($pending['field_storage'] === 'post_content') $update['post_content'] = $blocks;
            $saved = wp_update_post($update, true);
            if (is_wp_error($saved)) throw new Spacefast_Content_Error(500, 'content_write_failed', 'The resolved document could not be saved.');
            if ($pending['field_storage'] !== 'post_content') update_post_meta($postId, $pending['field_storage'], $blocks);
            $post = get_post($postId);
            $base = spacefast_content_sync_make_ledger(['bindingId' => $request['bindingId'], 'source' => $prepared['source'], 'format' => $prepared['format'], 'binding' => $pending], $text, $post, spacefast_content_sync_save_revision($postId), 'pull');
            $prepared = [
                'source' => $prepared['source'], 'format' => $prepared['format'], 'text' => $text,
                'expectedSourceRevision' => $request['observedSourceRevision'],
                'blocksDigest' => $base['blocksDigest'], 'metadataDigest' => $base['metadataDigest'],
                'wordpressRevisionId' => $base['wordpressRevisionId'],
            ];
            update_post_meta($postId, SPACEFAST_CONTENT_SOURCE_PREPARED_META, $prepared);
            spacefast_content_sync_store_receipt($postId, $request['operationId'], [
                'format' => 'spacefast.content-materialize', 'version' => 1, 'status' => 'materialized', 'operationId' => $request['operationId'],
                'sourceWrite' => ['state' => 'prepared', 'source' => $prepared['source'], 'expectedSourceRevision' => $request['observedSourceRevision'], 'text' => $text, 'textDigest' => spacefast_content_sync_digest_text($text)],
            ]);
            $entry = $pending['synthetic']
                ? ['bindingId' => $request['bindingId'], 'payload' => ['resourceId' => spacefast_content_collection_for_post($postId)['name'], 'postId' => $postId, 'wordpressRevisionId' => $base['wordpressRevisionId'], 'author' => spacefast_content_source_journal_author()]]
                : spacefast_content_source_journal_binding_entry($request['bindingId'], $pending['binding'], $postId, $observed['baseRevision']);
            if (!$pending['synthetic']) $entry['payload']['intent'] = 'convert';
            spacefast_content_source_journal_install();
            spacefast_content_source_journal_write($entry, $request['operationId'], false);
            spacefast_content_public_routes_refresh();
            return ['operationId' => $request['operationId'], 'bindingId' => $request['bindingId'], 'postId' => $postId, 'status' => 'queued'];
        })
    ));
}

function spacefast_content_inspect_source(array $request, bool $managed): array
{
    if (!$managed) throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content synchronization requires Spacefast authorization.');
    $bindingId = $request['bindingId'] ?? null;
    $pending = is_string($bindingId) ? spacefast_content_sync_pending_source($bindingId) : null;
    if ($pending !== null) return spacefast_content_sync_pending_inspection($bindingId, $pending);
    $binding = is_string($bindingId) ? spacefast_content_model_sync_binding($bindingId) : null;
    $post = is_array($binding) ? spacefast_content_sync_find_post($bindingId, $binding, false) : null;
    $ledger = is_object($post) ? spacefast_content_sync_ledger((int) $post->ID) : null;
    if (!is_object($post) || !is_array($ledger) || !spacefast_content_post_belongs_to_space((int) $post->ID)) {
        throw new Spacefast_Content_Error(404, 'content_sync_not_bound', 'This source has no bound document.');
    }
    $text = spacefast_content_sync_pullable_text($binding['format'], spacefast_content_sync_read_blocks($post, $binding), $post, $ledger['baseText']);
    return [
        'bindingId' => $bindingId,
        'postId' => (int) $post->ID,
        'source' => $binding['source'],
        'baseRevision' => $ledger['revision'],
        'baseText' => $ledger['baseText'],
        'wordpressText' => $text,
        'wordpressDigest' => spacefast_content_sync_digest_text($text),
        'wordpressRevisionId' => spacefast_content_sync_current_revision_id((int) $post->ID),
    ];
}

/** The selected merge becomes a prepared source write in the existing durable lane. */
function spacefast_content_resolve_source(array $request, bool $managed): array
{
    if (!$managed) throw new Spacefast_Content_Error(401, 'content_auth_required', 'Content resolution requires Spacefast authorization.');
    if (is_string($request['bindingId'] ?? null) && spacefast_content_sync_pending_source($request['bindingId']) !== null) {
        return spacefast_content_sync_resolve_pending($request);
    }
    $input = spacefast_content_sync_parse_reconcile([
        ...$request,
        'state' => 'bound',
        'baseRevision' => $request['expectedBaseRevision'] ?? null,
    ]);
    $expectedWordpressDigest = $request['expectedWordpressDigest'] ?? null;
    if (!is_string($expectedWordpressDigest) || preg_match(SPACEFAST_CONTENT_MODEL_REVISION_PATTERN, $expectedWordpressDigest) !== 1) {
        throw new Spacefast_Content_Error(400, 'content_sync_invalid', 'The expected document revision is invalid.');
    }
    spacefast_content_source_journal_install();
    return spacefast_content_sync_without_journal(static fn (): array => spacefast_content_sync_locked(
        static fn (): array => spacefast_content_sync_with_transaction(static function () use ($input, $expectedWordpressDigest): array {
            $post = spacefast_content_sync_find_post($input['bindingId'], $input['binding'], false);
            $postId = is_object($post) ? (int) $post->ID : 0;
            $replay = $postId > 0 ? spacefast_content_sync_receipt($postId, $input['operationId']) : null;
            if (is_array($replay) && ($replay['status'] ?? null) === 'pulled') {
                return ['operationId' => $input['operationId'], 'bindingId' => $input['bindingId'], 'postId' => $postId, 'status' => 'queued'];
            }
            $observed = spacefast_content_inspect_source(['bindingId' => $input['bindingId']], true);
            if (!hash_equals($input['baseRevision'], $observed['baseRevision'])
                || !hash_equals($expectedWordpressDigest, $observed['wordpressDigest'])) {
                throw new Spacefast_Content_Error(409, 'content_sync_resolution_stale', 'The document changed while this conflict was being resolved. Refresh the comparison.');
            }
            $resolved = $input;
            $resolved['text'] = spacefast_content_sync_canonical_text($input['format'], $input['text']);
            $savedId = spacefast_content_sync_save_document($resolved, spacefast_content_sync_to_blocks($input['format'], $resolved['text']));
            $saved = get_post($savedId);
            if (!is_object($saved)) throw new Spacefast_Content_Error(500, 'content_write_failed', 'The resolved document could not be read.');
            $ledger = spacefast_content_sync_make_ledger($resolved, $resolved['text'], $saved, spacefast_content_sync_save_revision($savedId), 'pull');
            spacefast_content_sync_commit($savedId, $resolved, $ledger, 'pulled');
            $entry = spacefast_content_source_journal_binding_entry($input['bindingId'], $input['binding'], $savedId, $ledger['revision']);
            spacefast_content_source_journal_write($entry, $input['operationId'], false);
            return ['operationId' => $input['operationId'], 'bindingId' => $input['bindingId'], 'postId' => $savedId, 'status' => 'queued'];
        })
    ));
}
