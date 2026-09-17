<?php
declare(strict_types=1);

/**
 * The application journal's delivery protocol: lease a page of intent to the
 * drainer that names a sink, settle it against the fence that drainer was given.
 *
 * A SINK IS A DESTINATION, NAMED. The store behind it is the site's own; the
 * name says who is entitled to drain it. Spacefast's own drainer names
 * `control-plane:content-source` and that is the default, but the set is site
 * configuration, not a constant baked into the engine: a deployment that ships
 * its editor changes somewhere else names its own sink through the standard
 * config lane and the same store serves it.
 *
 * Mail is deliberately NOT a sink. A queued message is delivered by the box
 * itself, through the transport WordPress already has, and never travels to a
 * drainer at all — see shared/mail-outbox.php.
 */

require_once __DIR__ . '/context.php';
require_once __DIR__ . '/db-broker.php';
require_once __DIR__ . '/content-source-journal.php';

const STATTIC_APPLICATION_JOURNAL_MAX_ATTEMPTS = 12;
const STATTIC_APPLICATION_JOURNAL_MAX_PAGE = 50;

/**
 * The sink names this site serves, in the order they were configured.
 *
 * `SPACEFAST_APPLICATION_JOURNAL_SINKS` is a comma-separated list read through
 * `_stattic_config_value` (wp-config constant, environment, or the provider's
 * persistent data), and it REPLACES the default rather than extending it: a
 * deployment that names its own drainer is stating the whole set it will answer.
 * An unreadable or empty value is the default, never an empty set, so a
 * mis-set constant cannot silently strand a site's content sync.
 *
 * @return list<string>
 */
function _stattic_application_journal_sinks(): array
{
    static $sinks = null;
    if (is_array($sinks)) {
        return $sinks;
    }
    $configured = [];
    foreach (explode(',', _stattic_config_value('SPACEFAST_APPLICATION_JOURNAL_SINKS')) as $name) {
        $name = trim($name);
        if ($name !== '' && preg_match('/^[A-Za-z0-9:_-]{1,160}$/', $name) === 1) {
            $configured[] = $name;
        }
    }
    return $sinks = $configured === []
        ? [STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK]
        : $configured;
}

function _stattic_application_journal_sink_configured(string $sink): bool
{
    // The rolling control-plane drainer still owns this old wire name. Never
    // let it lease editor changes, even if configuration repeats that name.
    return $sink !== 'control-plane:mail'
        && in_array($sink, _stattic_application_journal_sinks(), true);
}

function _stattic_application_journal_iso(string $mysqlTimestamp): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $mysqlTimestamp, new DateTimeZone('UTC'));
    if (!$parsed instanceof DateTimeImmutable) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlTimestamp, new DateTimeZone('UTC'));
    }
    return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d\TH:i:s.u\Z') : '';
}

function _stattic_application_journal_canonical_json(mixed $value): string
{
    $canonicalize = static function (mixed $entry) use (&$canonicalize): mixed {
        if (!is_array($entry)) {
            return $entry;
        }
        if (array_is_list($entry)) {
            return array_map($canonicalize, $entry);
        }
        ksort($entry, SORT_STRING);
        foreach ($entry as $key => $child) {
            $entry[$key] = $canonicalize($child);
        }
        return $entry;
    };
    return (string) json_encode(
        $canonicalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
}

/**
 * Lease one page for the sink a drainer named.
 *
 * A sink this site does not serve gets an empty page rather than a refusal: a
 * drainer holding a stale name learns there is nothing for it without learning
 * anything about what the site does carry.
 *
 * @return list<array>
 */
function _stattic_application_journal_claim(mysqli $connection, string $sink, int $limit, int $leaseSeconds): array
{
    return _stattic_application_journal_sink_configured($sink)
        ? _stattic_content_source_journal_claim($connection, $sink, $limit, $leaseSeconds)
        : [];
}

function _stattic_application_journal_complete(mysqli $connection, array $receipt): bool
{
    $fence = is_array($receipt['fence'] ?? null) ? $receipt['fence'] : [];
    $sink = is_string($fence['sink'] ?? null) ? $fence['sink'] : '';
    return _stattic_application_journal_sink_configured($sink)
        && _stattic_content_source_journal_complete($connection, $receipt);
}

/**
 * Claim editor changes waiting to reach a Space's repository.
 *
 * The whole fence is compared on completion, an expired final attempt settles as
 * `ambiguous` rather than being re-served, and claiming clears
 * `open_binding_id` so a save landing during delivery opens a fresh entry
 * instead of mutating a payload a drainer already read.
 *
 * The sink is the caller's, not a constant: it names the destination this page
 * is being leased to, and the same name has to come back on the receipt.
 *
 * A Space that has never bound a document has no table. That is an empty page,
 * not a fault: creating it here would run DDL on a connection the drain shares.
 *
 * @return list<array>
 */
function _stattic_content_source_journal_claim(mysqli $connection, string $sink, int $limit, int $leaseSeconds): array
{
    $table = STATTIC_CONTENT_SOURCE_JOURNAL_TABLE;
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('application_journal_transaction_failed', $connection->errno);
    }
    try {
        $terminal = $connection->prepare(
            "UPDATE {$table}
                SET state = 'ambiguous', lease_token = NULL, lease_expires_at = NULL,
                    last_error_code = 'application_journal_final_lease_expired',
                    terminal_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
              WHERE state = 'delivering' AND attempt_count = ? AND lease_expires_at <= UTC_TIMESTAMP(6)"
        );
        if (!$terminal instanceof mysqli_stmt) {
            // 1146: this site has never bound a document, so nothing can be due.
            if ($connection->errno === 1146) {
                $connection->rollback();
                return [];
            }
            throw new RuntimeException('application_journal_claim_prepare_failed', $connection->errno);
        }
        $maxAttempts = STATTIC_APPLICATION_JOURNAL_MAX_ATTEMPTS;
        $terminal->bind_param('i', $maxAttempts);
        if (!$terminal->execute()) {
            throw new RuntimeException('application_journal_terminal_settle_failed', $terminal->errno);
        }

        $select = $connection->prepare(
            "SELECT entry_id, space_id, operation_id, effect_index, payload_json, created_at, attempt_count
               FROM {$table}
              WHERE attempt_count < ?
                AND ((state IN ('queued', 'retry') AND available_at <= UTC_TIMESTAMP(6))
                  OR (state = 'delivering' AND lease_expires_at <= UTC_TIMESTAMP(6)))
              ORDER BY available_at, entry_id
              LIMIT ? FOR UPDATE SKIP LOCKED"
        );
        if (!$select instanceof mysqli_stmt) {
            throw new RuntimeException('application_journal_claim_prepare_failed', $connection->errno);
        }
        $select->bind_param('ii', $maxAttempts, $limit);
        if (!$select->execute()) {
            throw new RuntimeException('application_journal_claim_select_failed', $select->errno);
        }
        $result = $select->get_result();
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException('application_journal_claim_result_failed', $select->errno);
        }
        $claims = [];
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $entryId = (string) ($row['entry_id'] ?? '');
            $attempt = ((int) ($row['attempt_count'] ?? 0)) + 1;
            $leaseId = 'lease_' . bin2hex(random_bytes(20));
            $leaseExpiresAt = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
            $update = $connection->prepare(
                "UPDATE {$table}
                    SET state = 'delivering', attempt_count = ?, lease_token = ?, lease_expires_at = ?,
                        open_binding_id = NULL, updated_at = UTC_TIMESTAMP(6)
                  WHERE entry_id = ?"
            );
            if (!$update instanceof mysqli_stmt) {
                throw new RuntimeException('application_journal_claim_update_prepare_failed', $connection->errno);
            }
            $update->bind_param('isss', $attempt, $leaseId, $leaseExpiresAt, $entryId);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('application_journal_claim_update_failed', $update->errno);
            }
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new RuntimeException('application_journal_payload_invalid');
            }
            $claims[] = [
                'entry' => [
                    'format' => 'spacefast.application-journal',
                    'version' => 1,
                    'id' => $entryId,
                    'spaceId' => (string) ($row['space_id'] ?? ''),
                    'operationId' => (string) ($row['operation_id'] ?? ''),
                    'effectOrdinal' => (int) ($row['effect_index'] ?? 0),
                    'store' => 'wordpress',
                    // The payload says which: a change to a bound source names
                    // the binding it reconciles, a materialization has none to
                    // name and carries the resource whose file it needs minted.
                    'kind' => isset($payload['bindingId'])
                        ? 'content-source-changed'
                        : 'content-source-materialized',
                    'payloadDigest' => 'sha256:' . hash('sha256', _stattic_application_journal_canonical_json($payload)),
                    'payload' => $payload,
                    'createdAt' => _stattic_application_journal_iso((string) ($row['created_at'] ?? '')),
                ],
                'fence' => [
                    'entryId' => $entryId,
                    'sink' => $sink,
                    'attempt' => $attempt,
                    'leaseId' => $leaseId,
                    'leaseExpiresAt' => _stattic_application_journal_iso($leaseExpiresAt),
                ],
                'idempotencyKey' => $entryId . ':' . $sink,
            ];
        }
        if (!$connection->commit()) {
            throw new RuntimeException('application_journal_claim_commit_failed', $connection->errno);
        }
        return $claims;
    } catch (Throwable $error) {
        $connection->rollback();
        throw $error;
    }
}

function _stattic_content_source_journal_complete(mysqli $connection, array $receipt): bool
{
    $table = STATTIC_CONTENT_SOURCE_JOURNAL_TABLE;
    $fence = is_array($receipt['fence'] ?? null) ? $receipt['fence'] : [];
    $sink = is_string($fence['sink'] ?? null) ? $fence['sink'] : '';
    $entryId = is_string($fence['entryId'] ?? null) ? $fence['entryId'] : '';
    $attempt = is_int($fence['attempt'] ?? null) ? $fence['attempt'] : 0;
    $leaseId = is_string($fence['leaseId'] ?? null) ? $fence['leaseId'] : '';
    $leaseExpiresAt = is_string($fence['leaseExpiresAt'] ?? null)
        ? gmdate('Y-m-d H:i:s', (int) strtotime($fence['leaseExpiresAt']))
        : '';
    $status = is_string($receipt['status'] ?? null) ? $receipt['status'] : '';
    if (
        $entryId === '' || $attempt < 1 || $leaseId === '' || $leaseExpiresAt === ''
        || !in_array($status, ['delivered', 'retry', 'dead-letter', 'ambiguous'], true)
        || ($status === 'retry' && $attempt >= STATTIC_APPLICATION_JOURNAL_MAX_ATTEMPTS)
        || ($receipt['idempotencyKey'] ?? null) !== $entryId . ':' . $sink
    ) {
        return false;
    }
    $availableAt = $status === 'retry' && is_string($receipt['retryAt'] ?? null)
        ? gmdate('Y-m-d H:i:s', (int) strtotime($receipt['retryAt']))
        : gmdate('Y-m-d H:i:s');
    $downstreamReceipt = $status === 'delivered' && is_string($receipt['downstreamReceipt'] ?? null)
        ? substr($receipt['downstreamReceipt'], 0, 255)
        : null;
    $problem = is_array($receipt['problem'] ?? null) ? $receipt['problem'] : [];
    $errorCode = $status === 'delivered'
        ? null
        : (is_string($problem['code'] ?? null) ? $problem['code'] : 'application_journal_delivery_failed');
    $terminalInt = in_array($status, ['delivered', 'dead-letter', 'ambiguous'], true) ? 1 : 0;
    $statement = $connection->prepare(
        "UPDATE {$table}
            SET state = ?, available_at = ?, lease_token = NULL, lease_expires_at = NULL,
                downstream_receipt = COALESCE(?, downstream_receipt), last_error_code = ?,
                terminal_at = CASE WHEN ? = 1 THEN UTC_TIMESTAMP(6) ELSE NULL END,
                updated_at = UTC_TIMESTAMP(6)
          WHERE entry_id = ? AND state = 'delivering' AND attempt_count = ?
            AND lease_token = ? AND lease_expires_at = ? AND lease_expires_at >= UTC_TIMESTAMP(6)"
    );
    if (!$statement instanceof mysqli_stmt) {
        return false;
    }
    $statement->bind_param(
        'ssssisiss',
        $status,
        $availableAt,
        $downstreamReceipt,
        $errorCode,
        $terminalInt,
        $entryId,
        $attempt,
        $leaseId,
        $leaseExpiresAt
    );
    return $statement->execute() && $statement->affected_rows === 1;
}
