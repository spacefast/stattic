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
function context_request(string $method, string $path = '', array $body = []): WP_REST_Response {
    $request = new WP_REST_Request($method, '/spacefast/v1/context' . $path);
    $request->set_header('content-type', 'application/json'); $request->set_body(json_encode($body, JSON_THROW_ON_ERROR));
    return rest_do_request($request);
}
$source = '<!-- spacefast:document {"version":1,"title":"Shared context","slug":"context","status":"publish"} -->' . "\n" . spacefast_content_markdown_to_blocks("# Shared context\n\nA **bold** thought.\n\n| Item | Status |\n| --- | --- |\n| Draft | Ready |\n\n```js\nconst answer = 42;\n```\n");
$seed = spacefast_content_reconcile_source(['state' => 'initial', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $source, 'observedSourceRevision' => 'fixture-v1', 'operationId' => 'op_contextseed']);
$original = context_request('GET')->get_data();
context_check($original['canEdit'] === true && $original['source'] === SPACEFAST_CONTEXT_SOURCE, 'The editor must open the source-backed document at the Space root.');
$revisionsBefore = count(context_history((int) $original['id']));
$saved = context_request('PATCH', '', ['baseRevision' => $original['revision'], 'title' => 'Human context', 'blocks' => str_replace('Shared context', 'Human edit', $original['blocks'])]);
context_check($saved->get_status() === 200, json_encode($saved->get_data()));
$current = $saved->get_data();
context_check(count(context_history((int) $original['id'])) === $revisionsBefore + 1, 'One save must create exactly one history entry.');
$unchanged = context_request('PATCH', '', ['baseRevision' => $current['revision'], 'title' => $current['title'], 'blocks' => $current['blocks']])->get_data();
context_check(count(context_history((int) $original['id'])) === $revisionsBefore + 1, 'Saving unchanged content must not add a history entry.');
$current = $unchanged;
$stale = context_request('PATCH', '', ['baseRevision' => $original['revision'], 'markdown' => 'Overwrite']);
context_check($stale->get_status() === 409 && context_request('GET')->get_data()['revision'] === $current['revision'], 'A stale save must preserve the newer content.');
// Saving exactly the stored content is not a conflict, whatever revision was read.
$peer = context_request('PATCH', '', ['baseRevision' => $original['revision'], 'title' => $current['title'], 'blocks' => $current['blocks']]);
context_check($peer->get_status() === 200 && $peer->get_data()['revision'] === $current['revision'] && count(context_history((int) $original['id'])) === $revisionsBefore + 1, 'Saving the stored content again must succeed without a new revision.');
$native = new WP_REST_Request('POST', '/wp/v2/pages/' . $current['id']); $native->set_param('content', 'Bypass');
$bypass = rest_do_request($native);
context_check($bypass->get_status() === 409, 'Native REST cannot bypass the context revision fence.');
$history = context_history((int) $current['id']);
context_check(count($history) >= 2 && $history[0]['spacefast_actor']['kind'] === 'user', 'Native history must retain the human edit and attribution.');
$assets = context_request('GET', '/editor');
context_check($assets->get_status() === 200 && count($assets->get_data()['scripts']) > 0 && count((array) $assets->get_data()['imports']) > 0, 'The native editor must include scripts and its actual module dependencies.');
$pulled = spacefast_content_reconcile_source(['state' => 'bound', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $source, 'observedSourceRevision' => 'fixture-v1', 'operationId' => 'op_contextpull', 'baseRevision' => $seed['ledger']['revision']]);
context_check(str_contains($pulled['sourceWrite']['text'] ?? '', 'Human edit'), 'Saving must produce updated source through the existing writeback lane.');
context_check(spacefast_content_sync_document($pulled['sourceWrite']['text'])['body'] === $current['blocks'], 'Native source writeback must retain the exact Gutenberg markup.');
// A group carries metadata plain HTML cannot reconstruct. Keep that metadata
// through an actual save, source pull, and hydration in a different Space.
$grouped = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $current['blocks'] . '</div><!-- /wp:group -->';
$rich = context_request('PATCH', '', ['baseRevision' => $current['revision'], 'blocks' => $grouped])->get_data();
$richSource = spacefast_content_reconcile_source(['state' => 'bound', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $pulled['sourceWrite']['text'], 'observedSourceRevision' => 'fixture-v2', 'operationId' => 'op_contextrichpull', 'baseRevision' => $pulled['ledger']['revision']]);
context_check(spacefast_content_sync_document($richSource['sourceWrite']['text'])['body'] === $rich['blocks'], 'Layout attributes must survive source writeback without flattening.');
$forkId = 'spc_' . str_repeat('f', 32);
$forkModelRoot = str_replace($config['spaceId'], $forkId, $config['modelRoot']);
if (!is_dir($forkModelRoot)) mkdir($forkModelRoot, 0777, true);
copy($config['modelRoot'] . '/content-model.php', $forkModelRoot . '/content-model.php');
copy($config['modelRoot'] . '/content-model.sha256', $forkModelRoot . '/content-model.sha256');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $forkId; $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $forkModelRoot;
spacefast_content_reconcile_source(['state' => 'initial', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $richSource['sourceWrite']['text'], 'observedSourceRevision' => 'fixture-fork-v1', 'operationId' => 'op_contextforkseed']);
$fork = context_request('GET')->get_data();
context_check($fork['blocks'] === $rich['blocks'] && $fork['id'] !== $rich['id'], 'Source hydration in another Space must preserve Gutenberg metadata and allocate independent content.');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $config['spaceId']; $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $config['modelRoot'];
$restored = context_request('PATCH', '', ['baseRevision' => $rich['revision'], 'blocks' => $current['blocks']]);
context_check($restored->get_status() === 200, 'The original Space remains independently editable.');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_' . str_repeat('e', 32);
$foreign = context_request('GET'); context_check($foreign->get_status() === 404, 'Another Space must not resolve this document.');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $config['spaceId'];
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = null; wp_set_current_user(0);
$viewer = context_request('GET')->get_data(); context_check(!$viewer['canEdit'] && str_contains($viewer['html'], 'Human edit'), 'An admitted viewer must read saved content without editing authority.');
$denied = context_request('PATCH', '', ['baseRevision' => $current['revision'], 'markdown' => 'Denied']);
context_check($denied->get_status() === 403 && context_request('GET', '/editor')->get_status() === 403, 'View access cannot load editor authority or save.');

// A real native test session lets the browser prove the editor path without
// visiting an admin/login page. These disposable credentials stay in ignored cache.
wp_set_current_user(1);
$cookie = wp_generate_auth_cookie(1, time() + 3600, 'logged_in');
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$sessionFile = defined('SPACEFAST_CONTEXT_PREVIEW') ? 'preview-editor-session.json' : 'editor-session.json';
file_put_contents('/spacefast/.cache/context-acceptance/' . $sessionFile, json_encode(['cookie' => LOGGED_IN_COOKIE, 'value' => $cookie, 'nonce' => wp_create_nonce('wp_rest')], JSON_THROW_ON_ERROR));
file_put_contents('/spacefast/.cache/context-acceptance/receipt.json', json_encode(['saved' => $saved->get_status(), 'stale' => $stale->get_status(), 'peerSaved' => $peer->get_status(), 'nativeBypass' => $bypass->get_status(), 'foreignSpace' => $foreign->get_status(), 'unauthorized' => $denied->get_status(), 'history' => true, 'markdown' => true, 'rendered' => true, 'source' => true], JSON_THROW_ON_ERROR));
