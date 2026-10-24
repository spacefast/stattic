<?php
declare(strict_types=1);

// Real disposable WordPress/SQLite. This projects the same Space/role context
// the access runtime supplies; no customer identity or database is involved.
$config = json_decode(file_get_contents('/spacefast/.cache/context-acceptance/config.json'), true, flags: JSON_THROW_ON_ERROR);
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $config['spaceId'];
$GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'] = '/spacefast/.cache/context-acceptance/.stattic/storage';
$GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $config['modelRoot'];
$GLOBALS['SPACEFAST_CONTENT_MODEL_REVISION'] = $config['revision'];
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'administrator';
$GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = 'http://127.0.0.1:9419';
require_once '/spacefast/runtime/engine/shared/lock.php';
require_once '/spacefast/runtime/engine/wordpress/content-kernel.php';
spacefast_content_model_register_wordpress_projection();
wp_set_current_user(1);
update_user_meta(1, '_spacefast_native_role_' . $config['spaceId'], 'administrator');

function context_check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
/** History is WordPress's own revisions endpoint, with who saved each one. */
function context_history(int $id): array {
    $request = new WP_REST_Request('GET', '/wp/v2/pages/' . $id . '/revisions'); $request->set_param('context', 'edit');
    $response = rest_do_request($request);
    context_check($response->get_status() === 200, 'History must load: ' . json_encode($response->get_data()));
    return $response->get_data();
}
/** The context document is the Space page with the slug `context`, read through core. */
function context_read(string $context = 'edit'): WP_REST_Response {
    $request = new WP_REST_Request('GET', '/wp/v2/pages'); $request->set_param('slug', 'context'); $request->set_param('context', $context);
    return rest_do_request($request);
}
function context_page(string $context = 'edit'): array {
    $response = context_read($context);
    context_check($response->get_status() === 200 && count($response->get_data()) === 1, 'The context page must resolve: ' . json_encode($response->get_data()));
    return $response->get_data()[0];
}
/** Saves are native page writes that send back the revision they read. */
function context_save(int $id, ?string $revision, array $body): WP_REST_Response {
    $request = new WP_REST_Request('POST', '/wp/v2/pages/' . $id); $request->set_param('context', 'edit');
    if ($revision !== null) $body['spacefast_revision'] = $revision;
    $request->set_header('content-type', 'application/json'); $request->set_body(json_encode($body, JSON_THROW_ON_ERROR));
    return rest_do_request($request);
}
$source = '<!-- spacefast:document {"version":1,"title":"Shared context","slug":"context","status":"publish"} -->' . "\n" . spacefast_content_markdown_to_blocks("# Shared context\n\nA **bold** thought.\n\n| Item | Status |\n| --- | --- |\n| Draft | Ready |\n\n```js\nconst answer = 42;\n```\n");
$seed = spacefast_content_reconcile_source(['state' => 'initial', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $source, 'observedSourceRevision' => 'fixture-v1', 'operationId' => 'op_contextseed']);
$original = context_page();
context_check(is_string($original['spacefast_revision']) && $original['markdown'] !== null, 'The editor must open the source-backed page with its revision and Markdown.');
// Not `$id`: that is WordPress's global post id, which rendering overwrites.
$contextId = (int) $original['id'];
$revisionsBefore = count(context_history($contextId));
$saved = context_save($contextId, $original['spacefast_revision'], ['title' => 'Human context', 'content' => str_replace('Shared context', 'Human edit', $original['content']['raw'])]);
context_check($saved->get_status() === 200, json_encode($saved->get_data()));
$current = $saved->get_data();
context_check(count(context_history($contextId)) === $revisionsBefore + 1, 'One save must create exactly one history entry.');
context_check($current['spacefast_revision'] === context_page()['spacefast_revision'], 'The save response must name the revision a reader now sees.');
$unchanged = context_save($contextId, $current['spacefast_revision'], ['title' => $current['title']['raw'], 'content' => $current['content']['raw']]);
context_check($unchanged->get_status() === 200 && count(context_history($contextId)) === $revisionsBefore + 1, 'Saving unchanged content must not add a history entry.');
context_check($unchanged->get_data()['spacefast_revision'] === $current['spacefast_revision'], 'Saving unchanged content must not invalidate other readers.');
$stale = context_save($contextId, $original['spacefast_revision'], ['markdown' => 'Overwrite']);
context_check($stale->get_status() === 409 && context_page()['spacefast_revision'] === $current['spacefast_revision'], 'A stale save must preserve the newer content.');
$missing = context_save($contextId, null, ['content' => 'Bypass']);
context_check($missing->get_status() === 400 && context_page()['content']['raw'] === $current['content']['raw'], 'A page write without its revision cannot bypass the fence.');
// WordPress matches routes case-insensitively; the fence must too.
$cased = new WP_REST_Request('POST', '/wp/v2/Pages/' . $contextId); $cased->set_header('content-type', 'application/json'); $cased->set_body(json_encode(['content' => 'Bypass']));
$casedResponse = rest_do_request($cased);
context_check($casedResponse->get_status() === 400 && context_page()['content']['raw'] === $current['content']['raw'], 'A differently cased route cannot bypass the fence: ' . $casedResponse->get_status());
$controlled = new WP_REST_Request('POST', '/wp/v2/pages/' . $contextId); $controlled->set_query_params(['rest_route' => '/wp/v2/pages/' . $contextId, '_method' => 'POST', '_locale' => 'user']); $controlled->set_header('content-type', 'application/json'); $controlled->set_body(json_encode(['spacefast_revision' => $current['spacefast_revision'], 'title' => $current['title']['raw']]));
context_check(rest_do_request($controlled)->get_status() === 200, 'REST control parameters are not refused page fields.');
$unbind = context_save($contextId, $current['spacefast_revision'], ['status' => 'draft', 'slug' => 'elsewhere']);
context_check($unbind->get_status() === 400 && context_page()['spacefast_revision'] === $current['spacefast_revision'], 'A save cannot unpublish or rename the context document.');
$script = context_save($contextId, $current['spacefast_revision'], ['content' => '<!-- wp:paragraph --><p>Safe<img src="x" onerror="alert(1)"></p><!-- /wp:paragraph -->']);
context_check($script->get_status() === 200 && !str_contains($script->get_data()['content']['raw'], 'onerror') && !str_contains($script->get_data()['content']['rendered'], 'onerror'), 'Stored and rendered context markup must be sanitized.');
$current = context_save($contextId, $script->get_data()['spacefast_revision'], ['content' => $current['content']['raw']])->get_data();
// Rich-text meta never passes core's kses save filters. The kernel sanitizes it
// for every writer, administrators included, and on read for rows stored earlier.
$richMeta = '<p>Kept <a href="javascript:alert(1)">link</a></p><iframe src="javascript:alert(1)"></iframe>';
$summaryRequest = new WP_REST_Request('POST', '/wp/v2/pages'); $summaryRequest->set_param('context', 'edit');
$summaryRequest->set_header('content-type', 'application/json'); $summaryRequest->set_body(json_encode(['title' => 'Summary', 'meta' => ['_zero_pages_summary' => $richMeta]], JSON_THROW_ON_ERROR));
$summary = rest_do_request($summaryRequest);
context_check($summary->get_status() === 201, 'A rich-text field must save: ' . json_encode($summary->get_data()));
$summaryId = (int) $summary->get_data()['id'];
$richSanitized = static fn (string $value): bool => str_contains($value, 'Kept') && !str_contains($value, 'iframe') && !str_contains($value, 'javascript:');
$stored = get_post_meta($summaryId, '_zero_pages_summary', true);
context_check($richSanitized($stored), "The stored rich-text field must be sanitized: $stored");
$wpdb->update($wpdb->postmeta, ['meta_value' => $richMeta], ['post_id' => $summaryId, 'meta_key' => '_zero_pages_summary']);
wp_cache_delete($summaryId, 'post_meta');
$legacyRequest = new WP_REST_Request('GET', '/wp/v2/pages/' . $summaryId); $legacyRequest->set_param('context', 'edit');
$legacy = rest_do_request($legacyRequest)->get_data()['meta']['_zero_pages_summary'];
context_check($richSanitized($legacy), "A stored rich-text field must read back sanitized: $legacy");
$markdown = context_save($contextId, $current['spacefast_revision'], ['markdown' => "# Human edit\n\nWritten as **Markdown**.\n"]);
context_check($markdown->get_status() === 200 && str_contains($markdown->get_data()['content']['raw'], '<!-- wp:heading') && str_contains((string) $markdown->get_data()['markdown'], '**Markdown**'), 'A Markdown write must store blocks and read back as Markdown: ' . json_encode($markdown->get_data()));
$both = context_save($contextId, $markdown->get_data()['spacefast_revision'], ['markdown' => 'One', 'content' => 'Two']);
context_check($both->get_status() === 400, 'A write must choose content or markdown.');
$current = context_save($contextId, $markdown->get_data()['spacefast_revision'], ['title' => 'Human context', 'content' => $current['content']['raw']])->get_data();
$history = context_history($contextId);
context_check(count($history) >= 2 && $history[0]['spacefast_actor']['kind'] === 'user', 'Native history must retain the human edit and attribution.');
$assetsRequest = new WP_REST_Request('GET', '/wp/v2/editor/assets'); $assetsRequest->set_param('standalone', true);
$assets = rest_do_request($assetsRequest)->get_data();
context_check(isset($assets['scripts']['wp-block-editor'], $assets['scripts']['wp-element'], $assets['scripts']['react'], $assets['styles']['wp-components']), 'The dashboard editor route must serve the complete standalone editor.');
$me = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users/me'));
context_check($me->get_status() === 200 && (int) $me->get_data()['id'] === 1, 'The editor must read its current user from core.');
$withCollaborationMeta = static fn (array $body): array => $body + ['meta' => [SPACEFAST_CONTEXT_CRDT_META => 'fixture-shared-document']];
// Collaborators share one live draft: saving what a peer already saved is not a conflict.
$revisionsBeforePeer = count(context_history($contextId));
$peer = context_save($contextId, $original['spacefast_revision'], ['title' => $current['title']['raw'], 'content' => $current['content']['raw']]);
context_check($peer->get_status() === 200 && $peer->get_data()['spacefast_revision'] === $current['spacefast_revision'] && count(context_history($contextId)) === $revisionsBeforePeer, 'Saving content a collaborator already saved must succeed without a new revision.');
$foreignMeta = context_save($contextId, $current['spacefast_revision'], ['meta' => ['footnotes' => '[]']]);
context_check($foreignMeta->get_status() === 400, 'A context save may carry only the shared real-time document as meta.');
// The plugin hands the dashboard's editor its collaboration flags, outside wp-admin.
$pluginCoreData = $assets['scripts']['wp-core-data']['src'] ?? '';
$coreDataSetup = implode("\n", (array) ($assets['inline_scripts']['before']['wp-core-data'] ?? [])) . "\n" . implode("\n", (array) ($assets['inline_scripts']['after']['wp-core-data'] ?? []));
$collaborationAssets = str_contains((string) $pluginCoreData, '/wp-content/plugins/gutenberg/')
    && str_contains($coreDataSetup, 'window.__experimentalEnableRealTimeCollaboration = true')
    && str_contains($coreDataSetup, 'window._wpCollaborationDisabledPostTypes');
context_check($collaborationAssets, 'The editor must load the platform Gutenberg plugin with real-time collaboration on: ' . json_encode([$pluginCoreData, $coreDataSetup]));
// The plugin's own sync endpoint serves this document's room to its editors.
function context_sync(int $postId, ?array $awareness = ['fixture' => true], array $updates = []): WP_REST_Response {
    $request = new WP_REST_Request('POST', '/wp-sync/v1/updates');
    $request->set_header('content-type', 'application/json');
    $request->set_body(json_encode(['rooms' => [['room' => 'postType/page:' . $postId, 'client_id' => 1, 'after' => 0, 'awareness' => $awareness, 'updates' => $updates]]], JSON_THROW_ON_ERROR));
    return rest_do_request($request);
}
function context_queued_updates(int $postId): int {
    global $wpdb;
    $room = get_posts(['post_type' => 'wp_sync_storage', 'name' => md5('postType/page:' . $postId), 'post_status' => 'publish', 'fields' => 'ids']);
    return $room === [] ? 0 : (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'wp_sync_update_data'", $room[0]));
}
// An opaque queued edit; the outside write below must clear it from the room.
$sync = context_sync($contextId, ['fixture' => true], [['type' => 'update', 'data' => 'AQEAAAA=']]);
context_check($sync->get_status() === 200, 'An editor must reach the native sync room: ' . json_encode($sync->get_data()));
// Joining opens the room. The plugin caches it in-process; a peer's request
// finds it through a WordPress query instead. It must, or every poll
// opens an empty room and no edit reaches a peer.
$room = get_posts(['post_type' => 'wp_sync_storage', 'name' => md5('postType/page:' . $contextId), 'post_status' => 'publish', 'fields' => 'ids']);
context_check(count($room) === 1 && context_queued_updates($contextId) === 1, 'A peer must find the Space\'s editing room and its queued edit.');
// An outside write cannot merge into a live session, so agents wait for it to end.
$duringLive = context_save($contextId, $current['spacefast_revision'], ['markdown' => 'Agent during a live session']);
context_check($duringLive->get_status() === 409 && ($duringLive->get_data()['code'] ?? '') === 'content_context_live_editing' && context_page()['spacefast_revision'] === $current['spacefast_revision'], 'An agent write during live editing must be refused without a change: ' . json_encode($duringLive->get_data()));
// The live editor's own save persists the shared document beside the content, with attribution.
$revisionsBeforeLive = count(context_history($contextId));
$liveSave = context_save($contextId, $current['spacefast_revision'], $withCollaborationMeta(['content' => str_replace('Human edit', 'Human edit, live', $current['content']['raw'])]));
$afterLive = context_history($contextId);
context_check($liveSave->get_status() === 200 && count($afterLive) === $revisionsBeforeLive + 1 && $afterLive[0]['spacefast_actor']['kind'] === 'user' && get_post_meta($contextId, SPACEFAST_CONTEXT_CRDT_META, true) === 'fixture-shared-document', 'The live editor\'s save must save once, with attribution and its shared document: ' . json_encode($liveSave->get_data()));
context_check(context_queued_updates($contextId) === 1, 'The live editor\'s own save must keep the room\'s queued edits.');
$current = $liveSave->get_data();
context_sync($contextId, null);
$pulled = spacefast_content_reconcile_source(['state' => 'bound', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $source, 'observedSourceRevision' => 'fixture-v1', 'operationId' => 'op_contextpull', 'baseRevision' => $seed['ledger']['revision']]);
context_check(str_contains($pulled['sourceWrite']['text'] ?? '', 'Human edit'), 'Saving must produce updated source through the existing writeback lane.');
context_check(spacefast_content_sync_document($pulled['sourceWrite']['text'])['body'] === $current['content']['raw'], 'Native source writeback must retain the exact Gutenberg markup.');
// A group carries metadata plain HTML cannot reconstruct. Keep that metadata
// through an actual save and source pull.
$grouped = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $current['content']['raw'] . '</div><!-- /wp:group -->';
$rich = context_save($contextId, $current['spacefast_revision'], ['content' => $grouped])->get_data();
// Once the editors left, the agent's write drops the room's queued edits, so the
// next session opens from the saved content instead of replaying older edits over it.
context_check(($rich['spacefast_revision'] ?? '') !== $current['spacefast_revision'] && context_queued_updates($contextId) === 0, 'An agent write after editors leave must clear queued live edits.');
$richSource = spacefast_content_reconcile_source(['state' => 'bound', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $pulled['sourceWrite']['text'], 'observedSourceRevision' => 'fixture-v2', 'operationId' => 'op_contextrichpull', 'baseRevision' => $pulled['ledger']['revision']]);
context_check(spacefast_content_sync_document($richSource['sourceWrite']['text'])['body'] === $rich['content']['raw'], 'Layout attributes must survive source writeback without flattening.');
$restored = context_save($contextId, $rich['spacefast_revision'], ['content' => $current['content']['raw']]);
context_check($restored->get_status() === 200, 'The document can restore its earlier content: ' . json_encode($restored->get_data()));
// The Gutenberg plugin replaces core's global-styles controller on the same
// route. Styles belong to this Space's WordPress installation.
$stylesId = wp_insert_post(['post_type' => 'wp_global_styles', 'post_status' => 'publish', 'post_title' => 'Custom Styles', 'post_name' => 'wp-global-styles-' . urlencode(get_stylesheet()), 'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}'], true);
context_check(is_int($stylesId) && spacefast_content_post_belongs_to_space($stylesId), 'A Space\'s global styles must be stamped with the Space.');
$stylesRoute = '/wp/v2/global-styles/' . $stylesId;
context_check(get_class(rest_get_server()->get_routes()[preg_replace('/\d+$/', '(?P<id>[\/\w-]+)', $stylesRoute)][0]['callback'][0] ?? null) === 'WP_REST_Global_Styles_Controller_Gutenberg', 'The plugin must own the global-styles route this check exercises.');
$styles = new WP_REST_Request('POST', $stylesRoute);
$styles->set_header('content-type', 'application/json');
$styles->set_body(json_encode(['styles' => ['color' => ['background' => '#ff0000']]], JSON_THROW_ON_ERROR));
$styles = rest_do_request($styles);
context_check($styles->get_status() === 200 && str_contains((string) get_post($stylesId)->post_content, '#ff0000'), 'An editor must save this installation\'s global styles: ' . json_encode($styles->get_data()));
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = null; wp_set_current_user(0);
$viewer = context_page('view');
context_check(str_contains($viewer['content']['rendered'], 'Human edit') && !isset($viewer['content']['raw']) && !isset($viewer['spacefast_revision']), 'An admitted viewer must read the rendered page without editing authority.');
$viewerEdit = context_read('edit')->get_status();
$denied = context_save($contextId, $restored->get_data()['spacefast_revision'], ['markdown' => 'Denied']);
context_check(in_array($viewerEdit, [401, 403], true) && in_array($denied->get_status(), [401, 403], true), 'View access cannot read edit context or save.');
$viewerSync = context_sync($contextId);
context_check(in_array($viewerSync->get_status(), [401, 403], true), 'View access cannot join the live editing room.');

// A real native test session lets the browser prove the editor path without
// visiting an admin/login page. These disposable credentials stay in ignored cache.
wp_set_current_user(1);
$cookie = wp_generate_auth_cookie(1, time() + 3600, 'logged_in');
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$sessionFile = defined('SPACEFAST_CONTEXT_PREVIEW') ? 'preview-editor-session.json' : 'editor-session.json';
file_put_contents('/spacefast/.cache/context-acceptance/' . $sessionFile, json_encode(['cookie' => LOGGED_IN_COOKIE, 'value' => $cookie, 'nonce' => wp_create_nonce('wp_rest')], JSON_THROW_ON_ERROR));
file_put_contents('/spacefast/.cache/context-acceptance/receipt.json', json_encode(['saved' => $saved->get_status(), 'unchanged' => $unchanged->get_status(), 'stale' => $stale->get_status(), 'missingRevision' => $missing->get_status(), 'markdown' => $markdown->get_status(), 'viewerEdit' => $viewerEdit, 'unauthorized' => $denied->get_status(), 'casedRoute' => $casedResponse->get_status(), 'unbind' => $unbind->get_status(), 'peerSaved' => $peer->get_status(), 'foreignMeta' => $foreignMeta->get_status(), 'styles' => $styles->get_status(), 'collaboration' => ['assets' => $collaborationAssets, 'sync' => $sync->get_status(), 'viewerSync' => $viewerSync->get_status(), 'agentDuringLive' => $duringLive->get_status(), 'liveSave' => $liveSave->get_status()], 'history' => true, 'rendered' => true, 'source' => true], JSON_THROW_ON_ERROR));
