<?php
declare(strict_types=1);

/** One Space, one source-backed document, one existing Space access policy. */
const SPACEFAST_CONTEXT_SOURCE = 'content/context.blocks';

function spacefast_context_binding(): array
{
    $model = spacefast_content_model_active_release();
    foreach ($model['syncBindings'] ?? [] as $candidate) {
        if (($candidate['source'] ?? null) !== SPACEFAST_CONTEXT_SOURCE) continue;
        $binding = spacefast_content_model_sync_binding($candidate['id']);
        if (is_array($binding) && $binding['post_type'] === 'page' && $binding['field_storage'] === 'post_content') {
            return $binding + ['id' => $candidate['id']];
        }
    }
    throw new Spacefast_Content_Error(404, 'content_context_not_found', 'This Space has no context document.');
}

function spacefast_context_post(): object
{
    $binding = spacefast_context_binding();
    global $wpdb;
    // The active binding, not a client-supplied id, selects the document.
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s AND s.meta_value = %s
         JOIN {$wpdb->postmeta} b ON b.post_id = p.ID AND b.meta_key = %s AND b.meta_value = %s
         WHERE p.post_type = 'page' AND p.post_status = 'publish' LIMIT 2",
        SPACEFAST_CONTENT_SPACE_META, spacefast_content_require_space_id(),
        SPACEFAST_CONTENT_EXTERNAL_ID_META, SPACEFAST_CONTENT_SYNC_EXTERNAL_ID_PREFIX . $binding['id']
    ));
    if (count($ids) !== 1) throw new Spacefast_Content_Error(404, 'content_context_not_found', 'The context document is unavailable.');
    $post = get_post((int) $ids[0]);
    if (!$post || spacefast_content_source_is_retired((int) $post->ID)) throw new Spacefast_Content_Error(404, 'content_context_not_found', 'The context document is unavailable.');
    return $post;
}

/**
 * What a writer read: the words and the history entry they came from. The
 * modified time is left out, so re-saving identical content invalidates no one.
 */
function spacefast_context_revision(object $post): string
{
    return spacefast_content_sync_digest_text(json_encode([
        $post->ID, $post->post_title, $post->post_content,
        spacefast_content_sync_current_revision_id((int) $post->ID),
    ], JSON_THROW_ON_ERROR));
}

/** The context page id, or null when this Space has no context document. */
function spacefast_context_post_id(): ?int
{
    try {
        return (int) spacefast_context_post()->ID;
    } catch (Spacefast_Content_Error) {
        return null;
    }
}

/**
 * The context document is a WordPress page, read and saved through
 * `/wp/v2/pages/{id}`. Two fields carry what core does not: the revision a
 * writer read, which every save sends back, and the document as Markdown.
 */
function spacefast_context_register_fields(): void
{
    register_rest_field('page', 'spacefast_revision', [
        'get_callback' => static fn (array $page): ?string => (int) $page['id'] === spacefast_context_post_id() ? spacefast_context_revision(get_post((int) $page['id'])) : null,
        // Writable so a save can send back the revision it read; the write fence consumes it.
        'schema' => ['description' => 'The context revision you read. Send it back with every save.', 'type' => ['string', 'null'], 'context' => ['edit']],
    ]);
    register_rest_field('page', 'markdown', [
        'get_callback' => 'spacefast_context_markdown',
        'schema' => ['description' => 'The context document as Markdown; null when Markdown cannot represent its blocks. Write it instead of content to save Markdown.', 'type' => ['string', 'null'], 'context' => ['view', 'edit']],
    ]);
    register_rest_field('page-revision', 'spacefast_actor', ['get_callback' => 'spacefast_context_revision_actor', 'schema' => ['type' => 'object', 'context' => ['view', 'edit'], 'readonly' => true]]);
}

function spacefast_context_markdown(array $page): ?string
{
    if ((int) $page['id'] !== spacefast_context_post_id()) return null;
    $blocks = get_post((int) $page['id'])->post_content;
    try {
        return spacefast_content_markdown_representable($blocks) ? spacefast_content_markdown_from_blocks($blocks) : null;
    } catch (Spacefast_Content_Error) {
        // The conversion logged its cause; the document is still readable as blocks.
        return null;
    }
}

/** The parameters a context save may carry. Status, slug, parent and the rest stay as source declares them. */
const SPACEFAST_CONTEXT_WRITE_PARAMETERS = ['id', 'context', 'title', 'content', 'markdown', 'spacefast_revision'];

/**
 * Every page write reaches this filter, whatever its route. A write to the
 * context page is admitted only from inside the revision fence, and stores
 * sanitized markup: Markdown is converted to blocks first.
 */
function spacefast_context_prepare_write(mixed $prepared, mixed $request): mixed
{
    if (is_wp_error($prepared)) return $prepared;
    $contextId = spacefast_context_post_id();
    $id = (int) ($prepared->ID ?? 0);
    if ($id === 0 || $id !== $contextId) {
        return isset($request['markdown']) ? new WP_Error('content_context_markdown_invalid', 'Only the context document accepts markdown.', ['status' => 400]) : $prepared;
    }
    if (($GLOBALS['SPACEFAST_CONTEXT_FENCED_WRITE'] ?? null) !== $id) {
        return new WP_Error('content_context_revision_required', 'Save the context document with POST /wp/v2/pages/{id} and the spacefast_revision you read.', ['status' => 400]);
    }
    if (isset($request['markdown'])) {
        if (isset($request['content'])) return new WP_Error('content_context_markdown_invalid', 'Supply either content or markdown.', ['status' => 400]);
        try {
            $prepared->post_content = spacefast_content_markdown_to_blocks((string) $request['markdown']);
        } catch (Spacefast_Content_Error $error) {
            return new WP_Error($error->codeName, $error->getMessage(), ['status' => $error->status]);
        }
    }
    // Core skips kses for editors holding unfiltered_html; every viewer renders this document.
    if (isset($prepared->post_content)) $prepared->post_content = wp_kses_post($prepared->post_content);
    if (isset($prepared->post_title)) $prepared->post_title = sanitize_text_field($prepared->post_title);
    return $prepared;
}

/** The rendered document every viewer injects, sanitized like the stored one, source-written content included. */
function spacefast_context_sanitize_rendered(mixed $response, mixed $post): mixed
{
    if (!$response instanceof WP_REST_Response || (int) ($post->ID ?? 0) !== spacefast_context_post_id()) return $response;
    $data = $response->get_data();
    foreach (['content', 'title'] as $field) {
        if (isset($data[$field]['rendered'])) $data[$field]['rendered'] = wp_kses_post($data[$field]['rendered']);
    }
    $response->set_data($data);
    return $response;
}

/**
 * Every write to the context page names the revision it read, and runs inside
 * the Space write lock so the check and the save cannot interleave with
 * another writer. WordPress then saves the page through its own controller.
 */
function spacefast_context_fenced_write(mixed $result, mixed $request, string $route, array $handler): mixed
{
    // The matched route pattern, not the request path: WordPress matches paths case-insensitively.
    if ($result !== null || $route !== '/wp/v2/pages/(?P<id>[\d]+)'
        || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)) return $result;
    $id = (int) $request['id'];
    if ($id !== spacefast_context_post_id()) return $result;
    $sent = array_keys(($request->get_json_params() ?? []) + ($request->get_body_params() ?? []) + ($request->get_query_params() ?? []));
    // `rest_route`, `_method`, `_fields`, `_wpnonce` and the like address the REST server, not the page.
    $refused = array_filter(array_diff($sent, SPACEFAST_CONTEXT_WRITE_PARAMETERS), static fn (string $key): bool => $key !== 'rest_route' && !str_starts_with($key, '_'));
    if ($refused !== []) return new WP_Error('content_context_invalid', 'A context save may change only title and content or markdown. Refused: ' . implode(', ', $refused) . '.', ['status' => 400]);
    $expected = (string) ($request['spacefast_revision'] ?? '');
    if ($expected === '') return new WP_Error('content_context_revision_required', 'Send back the spacefast_revision you read.', ['status' => 400]);
    if (empty($GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT']) || !function_exists('_stattic_space_write_lock_with')) return new WP_Error('content_context_lock_unavailable', 'Context saves are unavailable. Retry later.', ['status' => 503]);
    try {
        return spacefast_content_sync_locked(static function () use ($id, $expected, $request, $handler): array {
            clean_post_cache($id);
            $post = get_post($id);
            if (!hash_equals(spacefast_context_revision($post), $expected)) return [new WP_Error('content_context_conflict', 'The context changed. Read it again before saving.', ['status' => 409])];
            $before = spacefast_content_sync_current_revision_id($id);
            $GLOBALS['SPACEFAST_CONTEXT_FENCED_WRITE'] = $id;
            try {
                $response = call_user_func($handler['callback'], $request);
            } finally {
                unset($GLOBALS['SPACEFAST_CONTEXT_FENCED_WRITE']);
            }
            if (is_wp_error($response) || ($response instanceof WP_REST_Response && $response->is_error())) return [$response];
            $saved = get_post($id);
            if ($saved->post_title === $post->post_title && $saved->post_content === $post->post_content) return [$response];
            // WordPress revisions the update itself; adding another duplicated every
            // history entry. Only create one when the site's revisioning did not.
            $revisionId = spacefast_content_sync_current_revision_id($id);
            if ($revisionId === $before || $revisionId === $id) $revisionId = spacefast_content_sync_save_revision($id);
            spacefast_context_record_actor($revisionId);
            // The response named the revision before history recorded it.
            if ($response instanceof WP_REST_Response && is_array($response->get_data()) && array_key_exists('spacefast_revision', $response->get_data())) {
                $response->set_data(['spacefast_revision' => spacefast_context_revision(get_post($id))] + $response->get_data());
            }
            return [$response];
        })[0];
    } catch (Spacefast_Content_Error $error) {
        return new WP_Error($error->codeName, $error->getMessage(), ['status' => $error->status]);
    }
}

/**
 * History is WordPress's own revisions endpoint; this adds who saved each one,
 * which WordPress records only as a user id.
 */
function spacefast_context_revision_actor(array $revision): array
{
    $actor = get_metadata('post', (int) $revision['id'], '_spacefast_context_actor', true);
    if (is_array($actor)) return $actor;
    // Read the revision post itself: a `_fields` request leaves `author` out of $revision.
    $author = (int) (get_post((int) $revision['id'])->post_author ?? 0);
    return ['name' => get_the_author_meta('display_name', $author) ?: 'Space collaborator', 'kind' => 'user'];
}

function spacefast_context_record_actor(int $revisionId): void
{
    $user = wp_get_current_user();
    $identity = spacefast_content_principal_user_authority((int) $user->ID);
    update_metadata('post', $revisionId, '_spacefast_context_actor', ['name' => $user->display_name ?: 'Space collaborator', 'kind' => ($identity['kind'] ?? 'user') === 'service' ? 'service' : 'user']);
}
