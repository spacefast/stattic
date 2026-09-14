<?php
declare(strict_types=1);

/**
 * Deliver one page of the Space mail outbox, CLI only. This is the command a box
 * scheduler carries — a wp.cloud crontab entry, a systemd timer, plain cron on a
 * self-hosted box:
 *
 *   php -d auto_prepend_file= …/engine/entrypoints/mail-outbox.php
 *
 * It contacts nothing but this site's own database and this site's own
 * WordPress. There is no credential to hold and no host to reach: a message
 * queued on the box is sent from the box, through the transport WordPress
 * already has.
 *
 * The post-response pass (shared/mail-outbox.php) ships the common case within
 * milliseconds of a send being accepted. This one exists for what that pass
 * cannot serve: retries, a message queued by a worker whose PHP process was
 * jailed, and anything left behind when a worker died mid-flight.
 *
 * Exit codes: 0 when the pass ran (including a pass with nothing due), 1 when
 * the outbox could not be reached, 2 on usage, 3 when storage is unprovisioned.
 * A page is bounded, so a backlog drains over successive runs rather than in one
 * unbounded process.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "mail-outbox.php runs on the CLI only\n");
    exit(2);
}
ini_set('display_errors', 'stderr');

require_once dirname(__DIR__) . '/shared/bootstrap-config.php';
require_once dirname(__DIR__) . '/runtime/cli-invoke.php';
require_once dirname(__DIR__) . '/shared/mail-outbox.php';

['flags' => $_stattic_flags] = _stattic_cli_flags(is_array($argv ?? null) ? $argv : []);

$_stattic_private_root = _stattic_cli_private_root(
    dirname(__DIR__),
    trim((string) ($_stattic_flags['private-root'] ?? ''))
);

$_stattic_limit = (int) ($_stattic_flags['limit'] ?? STATTIC_MAIL_OUTBOX_MAX_PAGE);
if ($_stattic_limit < 1 || $_stattic_limit > STATTIC_MAIL_OUTBOX_MAX_PAGE) {
    _stattic_cli_fail('usage: mail-outbox.php [--limit=1..' . STATTIC_MAIL_OUTBOX_MAX_PAGE . '] [--private-root=<path>]');
}

$_stattic_summary = _stattic_mail_outbox_deliver_due(
    _stattic_mail_outbox_public_root($_stattic_private_root),
    $_stattic_limit
);

fwrite(STDOUT, json_encode($_stattic_summary, JSON_UNESCAPED_SLASHES) . "\n");
exit($_stattic_summary['unavailable'] ? STATTIC_RUNTIME_INVOKE_EXIT_FAILED : 0);
