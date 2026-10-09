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
$pulled = spacefast_content_reconcile_source(['state' => 'bound', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $source, 'observedSourceRevision' => 'fixture-v1', 'operationId' => 'op_contextpull', 'baseRevision' => $seed['ledger']['revision']]);
context_check(str_contains($pulled['sourceWrite']['text'] ?? '', 'Human edit'), 'Saving must produce updated source through the existing writeback lane.');
context_check(spacefast_content_sync_document($pulled['sourceWrite']['text'])['body'] === $current['content']['raw'], 'Native source writeback must retain the exact Gutenberg markup.');
// A group carries metadata plain HTML cannot reconstruct. Keep that metadata
// through an actual save, source pull, and hydration in a different Space.
$grouped = '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' . $current['content']['raw'] . '</div><!-- /wp:group -->';
$rich = context_save($contextId, $current['spacefast_revision'], ['content' => $grouped])->get_data();
$richSource = spacefast_content_reconcile_source(['state' => 'bound', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $pulled['sourceWrite']['text'], 'observedSourceRevision' => 'fixture-v2', 'operationId' => 'op_contextrichpull', 'baseRevision' => $pulled['ledger']['revision']]);
context_check(spacefast_content_sync_document($richSource['sourceWrite']['text'])['body'] === $rich['content']['raw'], 'Layout attributes must survive source writeback without flattening.');
$forkId = 'spc_' . str_repeat('f', 32);
$forkModelRoot = str_replace($config['spaceId'], $forkId, $config['modelRoot']);
if (!is_dir($forkModelRoot)) mkdir($forkModelRoot, 0777, true);
copy($config['modelRoot'] . '/content-model.php', $forkModelRoot . '/content-model.php');
copy($config['modelRoot'] . '/content-model.sha256', $forkModelRoot . '/content-model.sha256');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $forkId; $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $forkModelRoot;
spacefast_content_reconcile_source(['state' => 'initial', 'bindingId' => $config['bindingId'], 'source' => SPACEFAST_CONTEXT_SOURCE, 'text' => $richSource['sourceWrite']['text'], 'observedSourceRevision' => 'fixture-fork-v1', 'operationId' => 'op_contextforkseed']);
$fork = context_page();

context_check($fork['content']['raw'] === $rich['content']['raw'] && $fork['id'] !== $rich['id'], 'Source hydration in another Space must preserve Gutenberg metadata and allocate independent content.');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $config['spaceId']; $GLOBALS['SPACEFAST_CONTENT_MODEL_RELEASE_ROOT'] = $config['modelRoot'];
$restored = context_save($contextId, $rich['spacefast_revision'], ['content' => $current['content']['raw']]);
context_check($restored->get_status() === 200, 'The original Space remains independently editable: ' . json_encode($restored->get_data()));
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = 'spc_' . str_repeat('e', 32);
$foreignList = context_read();
$foreign = rest_do_request(new WP_REST_Request('GET', '/wp/v2/pages/' . $contextId));
context_check($foreignList->get_data() === [] && $foreign->get_status() === 404, 'Another Space must not resolve this document: ' . $foreign->get_status());
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $config['spaceId'];
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = null; wp_set_current_user(0);
$viewer = context_page('view');
context_check(str_contains($viewer['content']['rendered'], 'Human edit') && !isset($viewer['content']['raw']) && !isset($viewer['spacefast_revision']), 'An admitted viewer must read the rendered page without editing authority.');
$viewerEdit = context_read('edit')->get_status();
$denied = context_save($contextId, $restored->get_data()['spacefast_revision'], ['markdown' => 'Denied']);
context_check(in_array($viewerEdit, [401, 403], true) && in_array($denied->get_status(), [401, 403], true), 'View access cannot read edit context or save.');

// A real native test session lets the browser prove the editor path without
// visiting an admin/login page. These disposable credentials stay in ignored cache.
wp_set_current_user(1);
$cookie = wp_generate_auth_cookie(1, time() + 3600, 'logged_in');
$_COOKIE[LOGGED_IN_COOKIE] = $cookie;
$sessionFile = defined('SPACEFAST_CONTEXT_PREVIEW') ? 'preview-editor-session.json' : 'editor-session.json';
file_put_contents('/spacefast/.cache/context-acceptance/' . $sessionFile, json_encode(['cookie' => LOGGED_IN_COOKIE, 'value' => $cookie, 'nonce' => wp_create_nonce('wp_rest')], JSON_THROW_ON_ERROR));
file_put_contents('/spacefast/.cache/context-acceptance/receipt.json', json_encode(['saved' => $saved->get_status(), 'unchanged' => $unchanged->get_status(), 'stale' => $stale->get_status(), 'missingRevision' => $missing->get_status(), 'markdown' => $markdown->get_status(), 'foreignSpace' => $foreign->get_status(), 'viewerEdit' => $viewerEdit, 'unauthorized' => $denied->get_status(), 'casedRoute' => $casedResponse->get_status(), 'unbind' => $unbind->get_status(), 'history' => true, 'rendered' => true, 'source' => true], JSON_THROW_ON_ERROR));
