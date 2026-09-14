<?php
/**
 * The site-local store behind the `control-plane:content-source` journal sink.
 *
 * Two very different callers share this file and nothing else:
 *
 * - WordPress appends intent from `save_post`, on `$wpdb`'s connection, so the
 *   row commits or rolls back with the save that produced it.
 * - The management API drains it over a raw mysqli from the db broker, with no
 *   WordPress bootstrapped at all.
 *
 * So this file holds only what both sides must agree on — the table name, the
 * default sink name and the schema — and requires nothing. The claim/complete
 * half lives in shared/application-journal.php, which owns the delivery
 * protocol and the configured sink set.
 */
declare(strict_types=1);

const STATTIC_CONTENT_SOURCE_JOURNAL_TABLE = '_spacefast_content_source_journal';

/**
 * The DEFAULT sink this site serves, used when nothing configures the set
 * (`SPACEFAST_APPLICATION_JOURNAL_SINKS`, shared/application-journal.php).
 *
 * The TS twin is `APPLICATION_JOURNAL_CONTENT_SOURCE_SINK` in
 * packages/common/src/contracts/application-journal.ts: it is the name
 * Spacefast's own drainer sends, so on a default site the two spellings must
 * match or the drain gets an empty page.
 */
const STATTIC_APPLICATION_JOURNAL_CONTENT_SOURCE_SINK = 'control-plane:content-source';

/**
 * `open_binding_id` carries the binding id only while the row is still
 * unclaimed, and NULL forever after. MySQL treats NULLs as distinct in a unique
 * key, so that one column both coalesces an editor's burst of saves onto the
 * single pending entry for a binding and stops constraining the row once a
 * drainer has already read its payload.
 */
function _stattic_content_source_journal_ddl(): string
{
    return 'CREATE TABLE IF NOT EXISTS ' . STATTIC_CONTENT_SOURCE_JOURNAL_TABLE . ' (
        entry_id VARCHAR(512) NOT NULL PRIMARY KEY,
        space_id VARCHAR(128) NOT NULL,
        operation_id VARCHAR(64) NOT NULL,
        effect_index SMALLINT UNSIGNED NOT NULL,
        binding_id VARCHAR(200) NOT NULL,
        open_binding_id VARCHAR(200) NULL,
        state VARCHAR(24) NOT NULL,
        payload_json MEDIUMBLOB NOT NULL,
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME(6) NOT NULL,
        lease_token VARCHAR(80) NULL,
        lease_expires_at DATETIME(6) NULL,
        downstream_receipt VARCHAR(255) NULL,
        last_error_code VARCHAR(96) NULL,
        terminal_at DATETIME(6) NULL,
        created_at DATETIME(6) NOT NULL,
        updated_at DATETIME(6) NOT NULL,
        UNIQUE KEY uniq_open_binding (space_id, open_binding_id),
        KEY idx_due (state, available_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
}
