<?php
declare(strict_types=1);

/**
 * The Space mail outbox: queued on the box, delivered from the box.
 *
 * `sf_email()` and a capsule's `ctx.email.send()` both land one row in
 * `_spacefast_email_outbox` (crates/stattic-zero-runner/src/db.rs), on the
 * invocation's own connection, so intent commits or rolls back with the write it
 * announces. This file is the other half: it takes those rows and hands them to
 * WordPress, which is the box's mail transport.
 *
 * Delivery is entirely local. The message — recipients, subject, body — never
 * leaves the site it was queued on, and nothing outside the box holds a lease on
 * it. That is the whole point: a box has working SMTP through `wp_mail()`
 * already, so a hosting control plane has no business carrying mail, and a
 * self-hosted deployment needs no control plane to send any.
 *
 * Durability is the lease, not the process. A claim marks its row `delivering`,
 * spends an attempt and takes a lease; a pass that dies mid-flight leaves the
 * lease to expire and the next pass re-serves the row. The final attempt is the
 * one exception: an expired final lease may have reached a recipient, so it
 * settles `ambiguous` for an operator rather than being sent again.
 *
 * Two passes exist, and both call `_stattic_mail_outbox_deliver_due()`:
 *
 *   * post-response, from the request that queued the mail
 *     (`_stattic_mail_outbox_deliver_after_response()`), so the common case
 *     leaves within milliseconds of being accepted;
 *   * `entrypoints/mail-outbox.php`, the CLI pass a box scheduler runs, which
 *     is what carries retries and the lanes a post-response pass cannot serve.
 */

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/db-broker.php';
// `_stattic_zero_runner_base_env()`: the engine's one provider-credential
// resolver. The CLI entrypoint loads only this file, so the dependency is
// declared here rather than assumed from a serving lane's include order.
require_once __DIR__ . '/artifacts.php';

const STATTIC_MAIL_OUTBOX_TABLE = '_spacefast_email_outbox';
const STATTIC_MAIL_OUTBOX_MAX_ATTEMPTS = 12;
const STATTIC_MAIL_OUTBOX_MAX_PAGE = 25;
const STATTIC_MAIL_OUTBOX_LEASE_SECONDS = 120;
const STATTIC_MAIL_OUTBOX_MAX_BACKOFF_SECONDS = 3600;

/**
 * The domain a queued message's `Message-ID` is minted under — the one
 * host-owned identifier the mail lane carries, and the only seam in this file a
 * white-label host has to feed.
 *
 * Everything else here is the sender's: the From address is a verified sender of
 * the Space, the body is the tenant's. This value belongs to whoever operates
 * the box, so it comes from the brand document (shared/brand.php `mail_domain`,
 * itself read through the standard config lane) rather than from a constant.
 *
 * The shape check stays here rather than in the document: a Message-ID is a
 * header, and a value carrying a space or a line break would be a header
 * injection. A domain this transport cannot put on the wire falls back to the
 * compiled-in default instead of taking the send down with it.
 */
function _stattic_mail_outbox_domain(): string
{
    require_once __DIR__ . '/brand.php';
    $configured = _stattic_brand_value('mail_domain');
    return preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', $configured) === 1
        ? strtolower($configured)
        : 'mail.spacefast.com';
}

function _stattic_mail_outbox_address(mixed $value): ?string
{
    if (!is_array($value) || !is_string($value['email'] ?? null)) {
        return null;
    }
    $email = trim($value['email']);
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    if (!array_key_exists('name', $value)) {
        return $email;
    }
    if (!is_string($value['name']) || $value['name'] === '' || preg_match('/[\r\n\0]/', $value['name']) === 1) {
        return null;
    }
    return $value['name'] . ' <' . $email . '>';
}

/** @return list<string>|null */
function _stattic_mail_outbox_addresses(mixed $value, bool $allowEmpty): ?array
{
    if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && count($value) === 0)) {
        return null;
    }
    $addresses = [];
    foreach ($value as $address) {
        $formatted = _stattic_mail_outbox_address($address);
        if ($formatted === null) {
            return null;
        }
        $addresses[] = $formatted;
    }
    return $addresses;
}

/**
 * One stored message as `wp_mail()` arguments, or null when the payload is not
 * one this transport can carry.
 *
 * Null is a verdict, not a failure: the row dead-letters instead of being
 * retried, because nothing about a later attempt would make an unaddressable
 * message deliverable.
 *
 * @return array{to:list<string>,subject:string,message:string,headers:list<string>,alt_body:?string}|null
 */
function _stattic_mail_outbox_wp_mail(array $payload, string $messageId): ?array
{
    $from = _stattic_mail_outbox_address($payload['from'] ?? null);
    $to = _stattic_mail_outbox_addresses($payload['to'] ?? null, false);
    $cc = array_key_exists('cc', $payload)
        ? _stattic_mail_outbox_addresses($payload['cc'], true)
        : [];
    $bcc = array_key_exists('bcc', $payload)
        ? _stattic_mail_outbox_addresses($payload['bcc'], true)
        : [];
    $replyTo = array_key_exists('replyTo', $payload)
        ? _stattic_mail_outbox_address($payload['replyTo'])
        : $from;
    $subject = is_string($payload['subject'] ?? null) ? $payload['subject'] : '';
    if (
        preg_match('/^msg_[a-f0-9]{32}$/', $messageId) !== 1
        || $from === null || $to === null || $cc === null || $bcc === null || $replyTo === null
        || $subject === '' || preg_match('/[\r\n\0]/', $subject) === 1
    ) {
        return null;
    }

    $text = is_string($payload['text'] ?? null) ? $payload['text'] : null;
    $html = is_string($payload['html'] ?? null) ? $payload['html'] : null;
    if ($html !== null) {
        $message = $html;
        $altBody = $text;
        $contentType = 'text/html; charset=UTF-8';
    } elseif ($text !== null) {
        $message = $text;
        $altBody = null;
        $contentType = null;
    } else {
        return null;
    }

    $headers = [
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
        'Message-ID: <' . $messageId . '@' . _stattic_mail_outbox_domain() . '>',
    ];
    if ($cc !== []) {
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }
    if ($bcc !== []) {
        $headers[] = 'Bcc: ' . implode(', ', $bcc);
    }
    if ($contentType !== null) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $reserved = ['from', 'to', 'cc', 'bcc', 'reply-to', 'content-type', 'message-id'];
    $custom = is_array($payload['headers'] ?? null) ? $payload['headers'] : [];
    foreach ($custom as $name => $value) {
        if (
            !is_string($name) || preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $name) !== 1
            || in_array(strtolower($name), $reserved, true)
            || !is_string($value) || preg_match('/[\r\n\0]/', $value) === 1
        ) {
            return null;
        }
        $headers[] = $name . ': ' . $value;
    }

    return [
        'to' => $to,
        'subject' => $subject,
        'message' => $message,
        'headers' => $headers,
        'alt_body' => $altBody,
    ];
}

/**
 * Lease one page of due messages.
 *
 * The final-attempt settle comes first and is the reason an expired lease is
 * safe to re-serve at all: every OTHER attempt may be repeated, because a send
 * that reached the transport without settling is indistinguishable from one that
 * never left, and at-least-once is the promise the outbox makes. The last
 * attempt has no next pass to correct it, so it stops as `ambiguous`.
 *
 * @return list<array{message_id:string,payload:array,attempt:int,lease_token:string,lease_expires_at:string}>
 */
function _stattic_mail_outbox_claim(mysqli $connection, int $limit, int $leaseSeconds): array
{
    $table = STATTIC_MAIL_OUTBOX_TABLE;
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('mail_outbox_transaction_failed');
    }
    try {
        $terminal = $connection->prepare(
            "UPDATE {$table}
                SET state = 'ambiguous', lease_token = NULL, lease_expires_at = NULL,
                    last_error_code = 'mail_outbox_final_lease_expired',
                    terminal_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
              WHERE state = 'delivering' AND accepted_at IS NULL
                AND attempt_count = ? AND lease_expires_at <= UTC_TIMESTAMP(6)"
        );
        if (!$terminal instanceof mysqli_stmt) {
            // 1146: this site has never accepted a message, so nothing can be due.
            if ($connection->errno === 1146) {
                $connection->rollback();
                return [];
            }
            throw new RuntimeException('mail_outbox_claim_prepare_failed');
        }
        $maxAttempts = STATTIC_MAIL_OUTBOX_MAX_ATTEMPTS;
        $terminal->bind_param('i', $maxAttempts);
        if (!$terminal->execute()) {
            throw new RuntimeException('mail_outbox_terminal_settle_failed');
        }

        $select = $connection->prepare(
            "SELECT message_id, payload_json, attempt_count
               FROM {$table}
              WHERE accepted_at IS NULL AND attempt_count < ?
                AND ((state IN ('queued', 'retry') AND available_at <= UTC_TIMESTAMP(6))
                  OR (state = 'delivering' AND lease_expires_at <= UTC_TIMESTAMP(6)))
              ORDER BY available_at, message_id
              LIMIT ? FOR UPDATE SKIP LOCKED"
        );
        if (!$select instanceof mysqli_stmt) {
            throw new RuntimeException('mail_outbox_claim_prepare_failed');
        }
        $select->bind_param('ii', $maxAttempts, $limit);
        if (!$select->execute()) {
            throw new RuntimeException('mail_outbox_claim_select_failed');
        }
        $result = $select->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('mail_outbox_claim_result_failed');
        }
        $claims = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $messageId = (string) ($row['message_id'] ?? '');
            $attempt = ((int) ($row['attempt_count'] ?? 0)) + 1;
            $leaseToken = 'lease_' . bin2hex(random_bytes(20));
            $leaseExpiresAt = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
            $update = $connection->prepare(
                "UPDATE {$table}
                    SET state = 'delivering', attempt_count = ?, lease_token = ?, lease_expires_at = ?,
                        updated_at = UTC_TIMESTAMP(6)
                  WHERE message_id = ?"
            );
            if (!$update instanceof mysqli_stmt) {
                throw new RuntimeException('mail_outbox_claim_update_prepare_failed');
            }
            $update->bind_param('isss', $attempt, $leaseToken, $leaseExpiresAt, $messageId);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('mail_outbox_claim_update_failed');
            }
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $claims[] = [
                'message_id' => $messageId,
                'payload' => is_array($payload) ? $payload : [],
                'attempt' => $attempt,
                'lease_token' => $leaseToken,
                'lease_expires_at' => $leaseExpiresAt,
            ];
        }
        if (!$connection->commit()) {
            throw new RuntimeException('mail_outbox_claim_commit_failed');
        }
        return $claims;
    } catch (Throwable $error) {
        $connection->rollback();
        throw $error;
    }
}

/** Bounded exponential backoff, in whole seconds from now. */
function _stattic_mail_outbox_retry_at(int $attempt): string
{
    $seconds = min(2 ** min(max($attempt, 1) - 1, 12), STATTIC_MAIL_OUTBOX_MAX_BACKOFF_SECONDS);
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

/**
 * Settle one claimed message under the lease this process holds.
 *
 * False means the lease was lost — the row moved on without us — and the caller
 * must discard its result rather than acting on it twice.
 *
 * @param array{message_id:string,attempt:int,lease_token:string,lease_expires_at:string} $claim
 */
function _stattic_mail_outbox_settle(
    mysqli $connection,
    array $claim,
    string $state,
    ?string $errorCode
): bool {
    $table = STATTIC_MAIL_OUTBOX_TABLE;
    if (!in_array($state, ['delivered', 'retry', 'dead-letter'], true)) {
        return false;
    }
    $messageId = $claim['message_id'];
    $attempt = $claim['attempt'];
    if ($state === 'retry' && $attempt >= STATTIC_MAIL_OUTBOX_MAX_ATTEMPTS) {
        $state = 'dead-letter';
    }
    $availableAt = $state === 'retry'
        ? _stattic_mail_outbox_retry_at($attempt)
        : gmdate('Y-m-d H:i:s');
    $terminal = $state === 'retry' ? 0 : 1;
    $providerMessageId = $state === 'delivered' ? $messageId : null;
    $lastError = $state === 'delivered' ? null : $errorCode;
    $statement = $connection->prepare(
        "UPDATE {$table}
            SET state = ?, available_at = ?, lease_token = NULL, lease_expires_at = NULL,
                provider_message_id = COALESCE(?, provider_message_id), last_error_code = ?,
                accepted_at = CASE WHEN ? = 'delivered' THEN COALESCE(accepted_at, UTC_TIMESTAMP(6)) ELSE accepted_at END,
                terminal_at = CASE WHEN ? = 1 THEN UTC_TIMESTAMP(6) ELSE NULL END,
                updated_at = UTC_TIMESTAMP(6)
          WHERE message_id = ? AND state = 'delivering' AND attempt_count = ?
            AND lease_token = ? AND lease_expires_at = ? AND lease_expires_at >= UTC_TIMESTAMP(6)"
    );
    if (!$statement instanceof mysqli_stmt) {
        return false;
    }
    $statement->bind_param(
        'sssssisiss',
        $state,
        $availableAt,
        $providerMessageId,
        $lastError,
        $state,
        $terminal,
        $messageId,
        $attempt,
        $claim['lease_token'],
        $claim['lease_expires_at']
    );
    return $statement->execute() && $statement->affected_rows === 1;
}

/**
 * Whether this site has a mail transport at all — one stat, no boot.
 *
 * Asked BEFORE anything is claimed, and that ordering is the point: a message
 * offered to nothing was never attempted, so a site with no WordPress must not
 * spend the outbox's twelve attempts discovering that. It stays queued until a
 * transport exists.
 */
function _stattic_mail_outbox_transport_present(string $publicRoot): bool
{
    return $publicRoot !== '' && is_file($publicRoot . '/wp-load.php');
}

/**
 * Boot WordPress once per process, for `wp_mail()` and nothing else.
 *
 * WordPress lives ABOVE the serving line: nothing on the request path needs it,
 * so it is loaded lazily and only after a message has actually been claimed.
 */
function _stattic_mail_outbox_wordpress(string $publicRoot): bool
{
    static $booted = null;
    if (is_bool($booted)) {
        return $booted;
    }
    if (!_stattic_mail_outbox_transport_present($publicRoot)) {
        return $booted = false;
    }
    require_once $publicRoot . '/wp-load.php';
    return $booted = function_exists('wp_mail');
}

/** The site root beside the private tree, where WordPress is installed. */
function _stattic_mail_outbox_public_root(string $privateRoot): string
{
    return dirname($privateRoot, 2);
}

/**
 * Whether this claim's lease has already lapsed against the wall clock.
 *
 * A page is leased up front but delivered one row at a time, so a slow earlier
 * send can outlast a later claim's lease before it is reached. `lease_expires_at`
 * is a UTC `Y-m-d H:i:s` stamp minted from `time()` at claim, so it is compared
 * back against `time()` here without dragging in the database clock.
 */
function _stattic_mail_outbox_lease_expired(array $claim): bool
{
    $deadline = strtotime(((string) ($claim['lease_expires_at'] ?? '')) . ' UTC');
    return $deadline === false || $deadline <= time();
}

/**
 * Credit a delivery bucket only when its transition actually committed.
 *
 * `_stattic_mail_outbox_settle()` returns false when the row moved on without us
 * — a lost or expired lease, or a failed write — and a pass that cannot commit a
 * transition must not report it as done, or a stuck backlog reads as success. An
 * uncommitted transition is counted `lost` instead, and the row stays for the
 * next pass to re-serve.
 */
function _stattic_mail_outbox_tally(array &$summary, string $bucket, bool $committed): void
{
    $summary[$committed ? $bucket : 'lost'] += 1;
}

/**
 * Deliver one bounded page of due mail through the box's own transport.
 *
 * @return array{claimed:int,delivered:int,retried:int,dead:int,lost:int,unavailable:bool}
 */
/**
 * Bind the box's own database for a drain that no request configured.
 *
 * `_stattic_zero_runner_base_env()` is the engine's single provider-credential
 * resolver: the reserved labelled name, then `DATABASE_URL`, then the provider's
 * `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` tuple. Passing no application
 * configuration is deliberate — the outbox belongs to the box, never to whichever
 * Space happens to be live, so a Space's own declared database must not be
 * selected here. This is the same binding the retired management mail route did.
 */
function _stattic_mail_outbox_bind_provider_database(): void
{
    $env = _stattic_zero_runner_base_env();
    _stattic_db_broker_bind(
        is_string($env['SPACEFAST_ZERO_DATABASE_URL'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL'] : null,
        is_string($env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] ?? null) ? $env['SPACEFAST_ZERO_DATABASE_URL_SOURCE'] : null
    );
}

function _stattic_mail_outbox_deliver_due(
    string $publicRoot,
    int $limit = STATTIC_MAIL_OUTBOX_MAX_PAGE,
    int $leaseSeconds = STATTIC_MAIL_OUTBOX_LEASE_SECONDS
): array {
    $summary = ['claimed' => 0, 'delivered' => 0, 'retried' => 0, 'dead' => 0, 'lost' => 0, 'unavailable' => false];
    if (!_stattic_mail_outbox_transport_present($publicRoot)) {
        $summary['unavailable'] = true;
        return $summary;
    }
    // The outbox is the BOX's own table, so the drain resolves the box's own
    // database — the same provider resolution every other engine lane runs. An
    // unbound broker reads only the reserved `SPACEFAST_ZERO_DATABASE_URL`, so on
    // a provider box that exposes the ordinary `DB_*` tuple this returned
    // `unavailable` before claiming anything and queued mail never left.
    _stattic_mail_outbox_bind_provider_database();
    $connection = _stattic_db_broker_connection();
    if (!$connection instanceof mysqli) {
        $summary['unavailable'] = true;
        return $summary;
    }
    try {
        $claims = _stattic_mail_outbox_claim($connection, max(1, min($limit, STATTIC_MAIL_OUTBOX_MAX_PAGE)), $leaseSeconds);
    } catch (Throwable $error) {
        error_log('spacefast mail outbox claim failed type=' . get_debug_type($error));
        $summary['unavailable'] = true;
        return $summary;
    }
    $summary['claimed'] = count($claims);

    foreach ($claims as $claim) {
        $mail = _stattic_mail_outbox_wp_mail($claim['payload'], $claim['message_id']);
        if ($mail === null) {
            _stattic_mail_outbox_tally(
                $summary,
                'dead',
                _stattic_mail_outbox_settle($connection, $claim, 'dead-letter', 'mail_payload_invalid'),
            );
            continue;
        }
        // Do not hand wp_mail a claim whose lease has already lapsed: the settle
        // would commit nothing and the row is about to be re-served, so sending
        // now would be a second delivery. Leave it for the pass that owns it.
        if (_stattic_mail_outbox_lease_expired($claim)) {
            $summary['lost'] += 1;
            continue;
        }
        if (!_stattic_mail_outbox_wordpress($publicRoot)) {
            _stattic_mail_outbox_tally(
                $summary,
                'retried',
                _stattic_mail_outbox_settle($connection, $claim, 'retry', 'mail_transport_unavailable'),
            );
            continue;
        }
        $configure = static function (mixed $mailer) use ($mail): void {
            if (is_string($mail['alt_body']) && is_object($mailer)) {
                $mailer->AltBody = $mail['alt_body'];
            }
        };
        if (function_exists('add_action')) {
            add_action('phpmailer_init', $configure);
        }
        try {
            $accepted = wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers']);
        } catch (Throwable $error) {
            error_log('spacefast mail outbox send failed type=' . get_debug_type($error));
            $accepted = false;
        } finally {
            if (function_exists('remove_action')) {
                remove_action('phpmailer_init', $configure);
            }
        }
        if ($accepted === true) {
            _stattic_mail_outbox_tally(
                $summary,
                'delivered',
                _stattic_mail_outbox_settle($connection, $claim, 'delivered', null),
            );
            continue;
        }
        $final = $claim['attempt'] >= STATTIC_MAIL_OUTBOX_MAX_ATTEMPTS;
        _stattic_mail_outbox_tally(
            $summary,
            $final ? 'dead' : 'retried',
            _stattic_mail_outbox_settle($connection, $claim, $final ? 'dead-letter' : 'retry', 'mail_rejected'),
        );
    }
    return $summary;
}

/**
 * Run a delivery pass after this request's response is on the wire.
 *
 * Registered by the lanes that bind the platform service broker with a sender
 * configured, so a request that could have queued mail is the one that ships it,
 * and a site that never sends never pays for the probe.
 *
 * Two requests cannot serve the same row (the claim leases it), and a request
 * whose PHP worker is already jailed to a Space's tree cannot read the site root
 * at all, so it declines and leaves the row to the scheduled pass. Post-response
 * work is best effort by construction; the row's state, never this call, is what
 * says whether a message left.
 */
function _stattic_mail_outbox_deliver_after_response(): void
{
    static $scheduled = false;
    if ($scheduled || PHP_SAPI === 'cli') {
        return;
    }
    $scheduled = true;
    _stattic_defer(static function (): void {
        // Tenant PHP pins `open_basedir` for the rest of the request and it can
        // only tighten, so this worker can no longer reach wp-load.php.
        if ((string) ini_get('open_basedir') !== '') {
            return;
        }
        $publicRoot = is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : '';
        if ($publicRoot === '') {
            return;
        }
        _stattic_mail_outbox_deliver_due($publicRoot);
    });
}
