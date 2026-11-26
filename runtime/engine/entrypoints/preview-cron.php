<?php
declare(strict_types=1);

// /__spacefast/preview-cron.php: runs one SpacePress version's WordPress cron,
// live or preview. Core's shutdown check on a request to that version sends it
// here, on the same host, with a two-minute token that names the Space, the
// version and its lane (shared/spacepress.php). It binds the version the
// way a visitor request does, then runs core's wp-cron.php, which runs the
// Action Scheduler queue. It needs no visitor session, so a private Space's
// jobs run too. The path keeps the name boxes already serve. See
// docs/decisions/2026-11-25-live-spacepress-jobs-run-from-live-traffic.md.

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/bootstrap-config.php';
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/response.php';
require_once $engineRoot . '/shared/storage.php';
require_once $engineRoot . '/shared/artifacts.php';
require_once $engineRoot . '/shared/content-access.php';
require_once $engineRoot . '/shared/spacepress-preview.php';
require_once $engineRoot . '/shared/spacepress.php';

_stattic_emit_runtime_identity();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    _stattic_method_not_allowed('POST', [
        'code' => 'method_not_allowed',
        'message' => 'The cron endpoint accepts POST requests.',
    ]);
}
$refuse = static function (): never {
    _stattic_problem_response(403, 'spacepress_preview_cron_refused', 'This request may not run jobs.', [], ['Cache-Control' => 'no-store']);
};

$privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
_stattic_access_private_root($privateRoot);
$header = 'HTTP_' . strtoupper(str_replace('-', '_', STATTIC_SPACEPRESS_CRON_HEADER));
$token = $_SERVER[$header] ?? null;
$claims = is_string($token) ? _stattic_spacepress_cron_verify($privateRoot, $token) : null;
if ($claims === null) $refuse();

// The host must select the token's version now, in the token's lane: a version
// that went live or was replaced, or a hostname that moved on, runs no jobs.
$target = _stattic_spacepress_cron_target(
    $privateRoot,
    (string) ($_SERVER['HTTP_HOST'] ?? ''),
    $claims['space_id'],
    $claims['version_id'],
    $claims['preview']
);
if ($target === null) $refuse();
try {
    $selected = _stattic_spacepress_select($privateRoot, $target['space_id'], $target['version_id'], $target['serving']);
} catch (Throwable) {
    // A legacy runtime generation, or a version that cannot load.
    $selected = false;
}
if (!$selected || is_array($GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'] ?? null) !== $claims['preview']) {
    $refuse();
}
$GLOBALS['SPACEFAST_PAGE_SERVING'] = $target['serving'];

// A managed box keeps core under the provider's `__wp__/` and links only
// wp-load.php into the root, so core's cron runs beside that link's target.
$wpLoad = dirname(_stattic_runtime_install_root($engineRoot)) . '/wp-load.php';
$wpRoot = dirname(realpath($wpLoad) ?: $wpLoad);
if (($_GET[STATTIC_SPACEPRESS_CRON_QUEUE_QUERY] ?? null) === '1') {
    // A request that queued due work asks for the queue alone. Like
    // wp-cron.php, keep running when the caller leaves, answer first, then run
    // as cron through Action Scheduler's own hook, which a site may unhook.
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    define('DOING_CRON', true);
    require $wpRoot . '/wp-load.php';
    do_action('action_scheduler_run_queue', 'Spacefast');
    exit;
}
require $wpRoot . '/wp-cron.php';
