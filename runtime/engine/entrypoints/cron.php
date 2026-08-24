<?php
declare(strict_types=1);

/**
 * Run one declared cron, CLI only. This is the command a wp.cloud crontab entry
 * carries, one entry per cron:
 *
 *   php -d auto_prepend_file= …/engine/entrypoints/cron.php \
 *       --host=example.spacefast.io --key=api-cron-growth-report
 *
 * It resolves the key against the ACTIVE version's `__spacefast/crons.json` and
 * runs that path through entrypoints/invoke.php's dispatch. That is the same
 * routing a visitor gets, static entries through Zero through the Functions
 * relay, with two headers added:
 *
 *   x-spacefast-cron   `<key>.<minute>.<hmac>`, asserted by this process and
 *                      stripped off every inbound request, so a handler that
 *                      sees it knows the platform made the call.
 *   authorization      `Bearer <CRON_SECRET>` when, and only when, the space
 *                      declared a `CRON_SECRET` runtime variable. That is the
 *                      half of the proof the TENANT owns and can compare.
 *
 * Exit codes are invoke.php's: 0 when the route answered < 400 (the provider's
 * `site-cron-results` webhook fires on anything else), 1 when it answered >= 400,
 * 2 on usage, 3 when the box, the host or the key does not resolve.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cron.php runs on the CLI only\n");
    exit(2);
}
ini_set('display_errors', 'stderr');

require_once dirname(__DIR__) . '/shared/bootstrap-config.php';
require_once dirname(__DIR__) . '/runtime/cli-invoke.php';
require_once dirname(__DIR__) . '/runtime/crons.php';

['flags' => $_stattic_flags] = _stattic_cli_flags(is_array($argv ?? null) ? $argv : []);

$_stattic_host = trim((string) ($_stattic_flags['host'] ?? ''));
$_stattic_key = trim((string) ($_stattic_flags['key'] ?? ''));
if ($_stattic_host === '' || $_stattic_key === '') {
    _stattic_cli_fail('usage: cron.php --host=<hostname> --key=<cron key> [--private-root=<path>]');
}

$_stattic_private_root = _stattic_cli_private_root(dirname(__DIR__), trim((string) ($_stattic_flags['private-root'] ?? '')));

$_stattic_target = _stattic_cron_target($_stattic_private_root, $_stattic_host);
if ($_stattic_target['kind'] === 'unavailable') {
    _stattic_cli_fail(
        'runtime configuration is temporarily unavailable for ' . $_stattic_host,
        STATTIC_RUNTIME_INVOKE_EXIT_FAILED
    );
}
if ($_stattic_target['kind'] === 'absent') {
    _stattic_cli_fail(
        'no active version serves ' . $_stattic_host,
        STATTIC_RUNTIME_INVOKE_EXIT_UNRESOLVED
    );
}

$_stattic_manifest = _stattic_cron_manifest(
    $_stattic_private_root,
    $_stattic_target['space_id'],
    $_stattic_target['version_id']
);
if ($_stattic_manifest === null) {
    _stattic_cli_fail(
        'version ' . $_stattic_target['version_id'] . ' declares no crons (' . STATTIC_CRONS_MANIFEST_PATH . ' is absent or unreadable)',
        STATTIC_RUNTIME_INVOKE_EXIT_UNRESOLVED
    );
}
if (!isset($_stattic_manifest[$_stattic_key])) {
    _stattic_cli_fail(
        'no cron named ' . $_stattic_key . ' in version ' . $_stattic_target['version_id'],
        STATTIC_RUNTIME_INVOKE_EXIT_UNRESOLVED
    );
}

$_stattic_secret = _stattic_cron_secret(
    $_stattic_private_root,
    $_stattic_target['space_id'],
    $_stattic_target['version_id']
);
if ($_stattic_secret['kind'] === 'unavailable') {
    _stattic_cli_fail(
        'cron runtime variables are temporarily unavailable',
        STATTIC_RUNTIME_INVOKE_EXIT_FAILED
    );
}

try {
    $_stattic_signature = _stattic_cron_signature(
        $_stattic_private_root,
        $_stattic_key,
        intdiv(time(), 60)
    );
} catch (RuntimeException) {
    _stattic_cli_fail('cron signing key is temporarily unavailable', STATTIC_RUNTIME_INVOKE_EXIT_FAILED);
}

_stattic_cli_invoke(
    $_stattic_private_root,
    $_stattic_host,
    'GET',
    $_stattic_manifest[$_stattic_key],
    // A space that declared no CRON_SECRET gets no Authorization at all, rather
    // than an empty bearer a handler could mistake for a presented credential.
    $_stattic_secret['kind'] === 'absent'
        ? []
        : ['HTTP_AUTHORIZATION' => 'Bearer ' . $_stattic_secret['value']],
    [
        STATTIC_CRON_REQUEST_PARAM => $_stattic_signature,
    ]
);
