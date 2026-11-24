<?php
declare(strict_types=1);

// /__spacefast/preview-cron.php: runs one SpacePress preview's WordPress cron.
// Core's shutdown check on a preview request sends it here, on the preview's
// own host, with a two-minute token that names the Space and version
// (shared/spacepress-preview.php). It binds that preview the way a visitor
// request does, then runs core's wp-cron.php, which runs the Action Scheduler
// queue. It never binds a production version, whose jobs keep running on the
// site's own WordPress. See
// docs/decisions/2026-11-20-spacepress-preview-jobs-run-from-preview-traffic.md.

$engineRoot = dirname(__DIR__);
require_once $engineRoot . '/shared/context.php';
require_once $engineRoot . '/shared/response.php';
require_once $engineRoot . '/shared/storage.php';
require_once $engineRoot . '/shared/artifacts.php';
require_once $engineRoot . '/shared/content-access.php';
require_once $engineRoot . '/shared/spacepress-preview.php';

_stattic_emit_runtime_identity();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    _stattic_method_not_allowed('POST', [
        'code' => 'method_not_allowed',
        'message' => 'The preview cron endpoint accepts POST requests.',
    ]);
}
$refuse = static function (): never {
    _stattic_problem_response(403, 'spacepress_preview_cron_refused', 'This request may not run preview jobs.', [], ['Cache-Control' => 'no-store']);
};

$privateRoot = _stattic_runtime_install_root($engineRoot) . '/storage';
$header = 'HTTP_' . strtoupper(str_replace('-', '_', STATTIC_SPACEPRESS_PREVIEW_CRON_HEADER));
$token = $_SERVER[$header] ?? null;
$claims = is_string($token) ? _stattic_spacepress_preview_cron_verify($privateRoot, $token) : null;
if ($claims === null) $refuse();

// The host must select the token's version now, as a preview: a version that
// went live, or a hostname that moved on, runs no jobs from here.
$target = _stattic_content_access_target($privateRoot, (string) ($_SERVER['HTTP_HOST'] ?? ''));
if (($target['kind'] ?? null) !== 'present'
    || $target['space_id'] !== $claims['space_id']
    || $target['version_id'] !== $claims['version_id']
    || !_stattic_spacepress_is_preview($privateRoot, $target['space_id'], $target['version_id'], $target['serving'])) {
    $refuse();
}
// Cron never seeds: only a preview that is ready has jobs to run.
if (!is_file(_stattic_spacepress_preview_dir($privateRoot, $target['space_id'], $target['version_id']) . '/ready.json')) {
    $refuse();
}
try {
    $selected = _stattic_spacepress_select($privateRoot, $target['space_id'], $target['version_id'], $target['serving']);
} catch (Throwable) {
    // A legacy runtime generation, or a version that cannot load.
    $selected = false;
}
if (!$selected || !is_array($GLOBALS['SPACEFAST_SPACEPRESS_PREVIEW'] ?? null)) {
    $refuse();
}
$GLOBALS['SPACEFAST_PAGE_SERVING'] = $target['serving'];

// A managed box keeps core under the provider's `__wp__/` and links only
// wp-load.php into the root, so core's cron runs beside that link's target.
$wpLoad = dirname(_stattic_runtime_install_root($engineRoot)) . '/wp-load.php';
require dirname(realpath($wpLoad) ?: $wpLoad) . '/wp-cron.php';
