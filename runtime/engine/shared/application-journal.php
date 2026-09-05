<?php
declare(strict_types=1);

require_once __DIR__ . '/db-broker.php';
require_once __DIR__ . '/content-source-journal.php';

const STATTIC_APPLICATION_JOURNAL_MAX_ATTEMPTS = 12;
const STATTIC_APPLICATION_JOURNAL_MAX_PAGE = 50;
const STATTIC_APPLICATION_JOURNAL_MAIL_SINK = 'control-plane:mail';

function _stattic_application_journal_iso(string $mysqlTimestamp): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $mysqlTimestamp, new DateTimeZone('UTC'));
    if (!$parsed instanceof DateTimeImmutable) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlTimestamp, new DateTimeZone('UTC'));
    }
    return $parsed instanceof DateTimeImmutable ? $parsed->format('Y-m-d\TH:i:s.u\Z') : '';
}

function _stattic_application_journal_operation_id(string $messageId): string
{
    return 'op_' . substr($messageId, 4);
}

function _stattic_application_journal_entry_id(array $row): string
{
    return (string) ($row['space_id'] ?? '') . ':'
        . _stattic_application_journal_operation_id((string) ($row['message_id'] ?? '')) . ':'
        . (string) ((int) ($row['effect_index'] ?? 0));
}

function _stattic_application_journal_message_id(string $entryId): string
{
    return preg_match('/^[^:]+:op_([a-f0-9]{32}):[0-9]+$/', $entryId, $matches) === 1
        ? 'msg_' . $matches[1]
        : '';
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

function _stattic_application_journal_mail_payload(array $row): array
{
    $stored = json_decode((string) ($row['payload_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($stored)) {
        throw new RuntimeException('application_journal_payload_invalid');
    }
    $text = is_string($stored['text'] ?? null) ? $stored['text'] : null;
    $html = is_string($stored['html'] ?? null) ? $stored['html'] : null;
    if ($text !== null && $html !== null) {
        $body = ['kind' => 'multipart', 'text' => $text, 'html' => $html];
    } elseif ($text !== null) {
        $body = ['kind' => 'text', 'text' => $text];
    } elseif ($html !== null) {
        $body = ['kind' => 'html', 'html' => $html];
    } else {
        throw new RuntimeException('application_journal_payload_invalid');
    }
    $payload = [];
    foreach (['from', 'to', 'cc', 'bcc', 'subject', 'replyTo', 'headers'] as $field) {
        if (array_key_exists($field, $stored)) {
            $payload[$field] = $stored[$field];
        }
    }
    $payload['body'] = $body;
    $payload['messageId'] = (string) ($row['message_id'] ?? '');
    return $payload;
}

/** @return list<array> */
function _stattic_application_journal_claim(mysqli $connection, string $sink, int $limit, int $leaseSeconds): array
{
    if ($sink === STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK) {
        return _stattic_content_source_journal_claim($connection, $limit, $leaseSeconds);
    }
    if ($sink !== STATTIC_APPLICATION_JOURNAL_MAIL_SINK) {
        return [];
    }
    if (!$connection->begin_transaction()) {
        throw new RuntimeException('application_journal_transaction_failed', $connection->errno);
    }
    try {
        // wp_mail returned success, but the control plane did not settle its
        // receipt. The site-local marker is authoritative and prevents resend.
        if (!$connection->query(
            "UPDATE _spacefast_email_outbox
                SET state = 'delivered', lease_token = NULL, lease_expires_at = NULL,
                    terminal_at = COALESCE(terminal_at, accepted_at), updated_at = UTC_TIMESTAMP(6)
              WHERE state = 'delivering' AND accepted_at IS NOT NULL"
        )) {
            // The first mail producer creates the outbox; until then it is empty.
            if ($connection->errno === 1146) {
                $connection->rollback();
                return [];
            }
            throw new RuntimeException('application_journal_acceptance_reconcile_failed', $connection->errno);
        }
        $terminal = $connection->prepare(
            "UPDATE _spacefast_email_outbox
                SET state = 'ambiguous', lease_token = NULL, lease_expires_at = NULL,
                    last_error_code = 'application_journal_final_lease_expired',
                    terminal_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)
              WHERE state = 'delivering' AND accepted_at IS NULL
                AND attempt_count = ? AND lease_expires_at <= UTC_TIMESTAMP(6)"
        );
        if (!$terminal instanceof mysqli_stmt) {
            throw new RuntimeException('application_journal_claim_prepare_failed', $connection->errno);
        }
        $maxAttempts = STATTIC_APPLICATION_JOURNAL_MAX_ATTEMPTS;
        $terminal->bind_param('i', $maxAttempts);
        if (!$terminal->execute()) {
            throw new RuntimeException('application_journal_terminal_settle_failed', $terminal->errno);
        }

        $select = $connection->prepare(
            "SELECT message_id, space_id, invocation_id, effect_index, payload_json,
                    created_at, attempt_count
               FROM _spacefast_email_outbox
              WHERE accepted_at IS NULL AND attempt_count < ?
                AND ((state IN ('queued', 'retry') AND available_at <= UTC_TIMESTAMP(6))
                  OR (state = 'delivering' AND lease_expires_at <= UTC_TIMESTAMP(6)))
              ORDER BY available_at, message_id
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
            $messageId = (string) ($row['message_id'] ?? '');
            $attempt = ((int) ($row['attempt_count'] ?? 0)) + 1;
            $leaseId = 'lease_' . bin2hex(random_bytes(20));
            $leaseExpiresAt = gmdate('Y-m-d H:i:s', time() + $leaseSeconds);
            $update = $connection->prepare(
                "UPDATE _spacefast_email_outbox
                    SET state = 'delivering', attempt_count = ?, lease_token = ?, lease_expires_at = ?,
                        updated_at = UTC_TIMESTAMP(6)
                  WHERE message_id = ?"
            );
            if (!$update instanceof mysqli_stmt) {
                throw new RuntimeException('application_journal_claim_update_prepare_failed', $connection->errno);
            }
            $update->bind_param('isss', $attempt, $leaseId, $leaseExpiresAt, $messageId);
            if (!$update->execute() || $update->affected_rows !== 1) {
                throw new RuntimeException('application_journal_claim_update_failed', $update->errno);
            }
            $payload = _stattic_application_journal_mail_payload($row);
            $entryId = _stattic_application_journal_entry_id($row);
            $operationId = _stattic_application_journal_operation_id($messageId);
            $claims[] = [
                'entry' => [
                    'format' => 'spacefast.application-journal',
                    'version' => 1,
                    'id' => $entryId,
                    'spaceId' => (string) ($row['space_id'] ?? ''),
                    'operationId' => $operationId,
                    'effectOrdinal' => (int) ($row['effect_index'] ?? 0),
                    'store' => 'lakebed',
                    'kind' => 'mail',
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

function _stattic_application_journal_complete(mysqli $connection, array $receipt): bool
{
    $fence = is_array($receipt['fence'] ?? null) ? $receipt['fence'] : [];
    if (($fence['sink'] ?? null) === STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK) {
        return _stattic_content_source_journal_complete($connection, $receipt);
    }
    $entryId = is_string($fence['entryId'] ?? null) ? $fence['entryId'] : '';
    $messageId = _stattic_application_journal_message_id($entryId);
    $sink = is_string($fence['sink'] ?? null) ? $fence['sink'] : '';
    $attempt = is_int($fence['attempt'] ?? null) ? $fence['attempt'] : 0;
    $leaseId = is_string($fence['leaseId'] ?? null) ? $fence['leaseId'] : '';
    $leaseExpiresAt = is_string($fence['leaseExpiresAt'] ?? null)
        ? gmdate('Y-m-d H:i:s', (int) strtotime($fence['leaseExpiresAt']))
        : '';
    $status = is_string($receipt['status'] ?? null) ? $receipt['status'] : '';
    if (
        $messageId === '' || $sink !== STATTIC_APPLICATION_JOURNAL_MAIL_SINK
        || $attempt < 1 || $leaseId === '' || $leaseExpiresAt === ''
        || !in_array($status, ['delivered', 'retry', 'dead-letter', 'ambiguous'], true)
        || ($status === 'retry' && $attempt >= STATTIC_APPLICATION_JOURNAL_MAX_ATTEMPTS)
        || ($receipt['idempotencyKey'] ?? null) !== $entryId . ':' . $sink
    ) {
        return false;
    }
    $availableAt = $status === 'retry' && is_string($receipt['retryAt'] ?? null)
        ? gmdate('Y-m-d H:i:s', (int) strtotime($receipt['retryAt']))
        : gmdate('Y-m-d H:i:s');
    $providerMessageId = $status === 'delivered' && is_string($receipt['downstreamReceipt'] ?? null)
        ? $receipt['downstreamReceipt']
        : null;
    $problem = is_array($receipt['problem'] ?? null) ? $receipt['problem'] : [];
    $errorCode = $status === 'delivered'
        ? null
        : (is_string($problem['code'] ?? null) ? $problem['code'] : 'application_journal_delivery_failed');
    $terminalInt = in_array($status, ['delivered', 'dead-letter', 'ambiguous'], true) ? 1 : 0;
    $statement = $connection->prepare(
        "UPDATE _spacefast_email_outbox
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
        $status,
        $availableAt,
        $providerMessageId,
        $errorCode,
        $status,
        $terminalInt,
        $messageId,
        $attempt,
        $leaseId,
        $leaseExpiresAt
    );
    return $statement->execute() && $statement->affected_rows === 1;
}

/** @return array{state:string,message_id:string}|null */
function _stattic_application_journal_mail_claim_state(mysqli $connection, array $claim, string $spaceId): ?array
{
    $entry = is_array($claim['entry'] ?? null) ? $claim['entry'] : [];
    $fence = is_array($claim['fence'] ?? null) ? $claim['fence'] : [];
    $messageId = is_array($entry['payload'] ?? null) && is_string($entry['payload']['messageId'] ?? null)
        ? $entry['payload']['messageId']
        : '';
    if (
        ($entry['spaceId'] ?? null) !== $spaceId || ($entry['kind'] ?? null) !== 'mail'
        || ($fence['sink'] ?? null) !== STATTIC_APPLICATION_JOURNAL_MAIL_SINK
        || ($fence['entryId'] ?? null) !== ($entry['id'] ?? null)
        || ($claim['idempotencyKey'] ?? null) !== ($entry['id'] ?? '') . ':' . STATTIC_APPLICATION_JOURNAL_MAIL_SINK
        || _stattic_application_journal_message_id((string) ($entry['id'] ?? '')) !== $messageId
    ) {
        return null;
    }
    $statement = $connection->prepare(
        "SELECT state, attempt_count, lease_token, lease_expires_at, accepted_at
           FROM _spacefast_email_outbox WHERE message_id = ? AND space_id = ?"
    );
    if (!$statement instanceof mysqli_stmt) {
        return null;
    }
    $statement->bind_param('ss', $messageId, $spaceId);
    if (!$statement->execute()) {
        return null;
    }
    $result = $statement->get_result();
    if (!$result instanceof mysqli_result) {
        return null;
    }
    $row = $result->fetch_assoc();
    if (!is_array($row)) {
        return null;
    }
    if (is_string($row['accepted_at'] ?? null)) {
        return ['state' => 'accepted', 'message_id' => $messageId];
    }
    $leaseExpiresAt = strtotime((string) ($row['lease_expires_at'] ?? ''));
    $live = ($row['state'] ?? null) === 'delivering'
        && (int) ($row['attempt_count'] ?? 0) === (int) ($fence['attempt'] ?? 0)
        && ($row['lease_token'] ?? null) === ($fence['leaseId'] ?? null)
        && $leaseExpiresAt !== false && $leaseExpiresAt >= time();
    return $live ? ['state' => 'ready', 'message_id' => $messageId] : null;
}

function _stattic_application_journal_record_mail_accepted(mysqli $connection, string $messageId): bool
{
    $statement = $connection->prepare(
        "UPDATE _spacefast_email_outbox
            SET accepted_at = COALESCE(accepted_at, UTC_TIMESTAMP(6)),
                provider_message_id = COALESCE(provider_message_id, ?), updated_at = UTC_TIMESTAMP(6)
          WHERE message_id = ?"
    );
    if (!$statement instanceof mysqli_stmt) {
        return false;
    }
    $statement->bind_param('ss', $messageId, $messageId);
    return $statement->execute() && $statement->affected_rows === 1;
}

/**
 * Claim editor changes waiting to reach a Space's repository.
 *
 * The same lease discipline as the mail lane, over a different table: the whole
 * fence is compared on completion, an expired final attempt settles as
 * `ambiguous` rather than being re-served, and claiming clears
 * `open_binding_id` so a save landing during delivery opens a fresh entry
 * instead of mutating a payload a drainer already read.
 *
 * A Space that has never bound a document has no table. That is an empty page,
 * not a fault: creating it here would run DDL on a connection the drain shares.
 *
 * @return list<array>
 */
function _stattic_content_source_journal_claim(mysqli $connection, int $limit, int $leaseSeconds): array
{
    $table = STATTIC_CONTENT_SOURCE_JOURNAL_TABLE;
    $sink = STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK;
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
                    'kind' => 'content-source-changed',
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
    $sink = STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK;
    $fence = is_array($receipt['fence'] ?? null) ? $receipt['fence'] : [];
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
