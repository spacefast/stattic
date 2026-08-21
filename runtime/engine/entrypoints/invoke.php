<?php
declare(strict_types=1);

/**
 * Run one request through the engine from the shell, CLI only:
 *
 *   php engine/entrypoints/invoke.php \
 *       --host=example.spacefast.io --path=/api/report [--method=POST] \
 *       [--header='content-type: application/json'] [--private-root=…]
 *
 * WP.Cloud prepends a runtime bootstrap to every PHP process — disable it:
 *   php -d auto_prepend_file= …/engine/entrypoints/invoke.php …
 *
 * Contract:
 *   stdin   the request body, verbatim. The CLI entrypoint stages it for the
 *           visitor lane, so the shell's own redirect is the body flag.
 *   stdout  the response body, unmodified.
 *   stderr  one `spacefast-invoke-result: {…}` line carrying the final status,
 *           plus whatever the dispatched route logged. Response HEADERS are not
 *           in it: the CLI SAPI discards `header()` outright, so reporting them
 *           would mean inventing them.
 *   exit    0 when the final status is < 400, 1 when it is not — the signal the
 *           provider's `site-cron-results` webhook keys on. 2 is a usage error
 *           and 3 an unprovisioned box; neither ran a request.
 *
 * This is never mounted as a public alias: it is in the manifest's `files` and
 * in none of its `aliases`.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "invoke.php runs on the CLI only\n");
    exit(2);
}
// stdout carries only the response body, so notices must not land there.
ini_set('display_errors', 'stderr');

require_once dirname(__DIR__) . '/shared/bootstrap-config.php';
require_once dirname(__DIR__) . '/runtime/cli-invoke.php';

['flags' => $_stattic_flags, 'headers' => $_stattic_headers] = _stattic_cli_flags(is_array($argv ?? null) ? $argv : []);

$_stattic_host = trim((string) ($_stattic_flags['host'] ?? ''));
$_stattic_path = trim((string) ($_stattic_flags['path'] ?? ''));
if ($_stattic_host === '' || $_stattic_path === '') {
    _stattic_cli_fail('usage: invoke.php --host=<hostname> --path=</path> [--method=GET] [--header="name: value"] [--private-root=<path>]');
}

_stattic_cli_invoke(
    _stattic_cli_private_root(dirname(__DIR__), trim((string) ($_stattic_flags['private-root'] ?? ''))),
    $_stattic_host,
    strtoupper(trim((string) ($_stattic_flags['method'] ?? 'GET'))),
    $_stattic_path,
    _stattic_cli_request_params($_stattic_headers)
);
