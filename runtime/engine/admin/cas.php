<?php
declare(strict_types=1);

// The bulk storage lane: blob GC and the disk report. The CAS is the only
// byte store.
//
// No file time on a BLOB is ever read as a clock: its mtime is content-derived
// and IS the accel-lane validator. Every clock here is a record we wrote. The
// declarations carry publish time, gc/marks.json candidacy time.
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/lock.php';
require_once __DIR__ . '/../shared/storage.php';

const STATTIC_CAS_GC_DELETE_BATCH = 200;

function _stattic_cas_gc_grace_seconds(): int
{
    // min=0: tests and ops pin this to 0 to collect on the spot.
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_GRACE_SECONDS', 3600, 0);
}

// The floor the undeclared/garbage lane keeps when the grace knob above is
// pinned to 0. It covers the finalizer's install->declare window, where "no
// declaration names this blob" means "not yet" rather than "never".
function _stattic_cas_gc_undeclared_min_grace_seconds(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_UNDECLARED_MIN_GRACE_SECONDS', 300, 0);
}

function _stattic_cas_gc_scan_interval_seconds(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_SCAN_INTERVAL_SECONDS', 3600, 0);
}

// A declaration's own timestamp, for the shas it names; its file mtime is the
// fallback (a record file is not a blob, so the validator rule does not apply).
function _stattic_cas_record_time(mixed $decoded, string $path): int
{
    if (is_array($decoded)) {
        foreach (['generatedAt', 'created_at', 'createdAt', 'updated_at', 'updatedAt'] as $field) {
            $at = _stattic_record_store_timestamp($decoded[$field] ?? null);
            if ($at !== null) {
                return $at;
            }
        }
    }
    $mtime = filemtime($path);
    return $mtime === false ? 0 : (int) $mtime;
}

// Every 64-hex string anywhere in a decoded declaration. Shape-agnostic rather
// than a list of key paths: the finalizer owns the declarations that name
// blobs, and a GC edited in lockstep with them eventually deletes live bytes.
// Over-inclusion is free.
function _stattic_cas_collect_shas(mixed $value, array &$shas, int $at, int $depth = 0): void
{
    if (is_string($value)) {
        if (_stattic_is_sha256_hex($value)) {
            $shas[$value] = max($shas[$value] ?? 0, $at);
        }
        return;
    }
    if (!is_array($value) || $depth > 12) {
        return;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && _stattic_is_sha256_hex($key)) {
            $shas[$key] = max($shas[$key] ?? 0, $at);
        }
        _stattic_cas_collect_shas($child, $shas, $at, $depth + 1);
    }
}

/** @return int|null the declaration's timestamp, or null when it is unreadable. */
function _stattic_cas_collect_shas_from_json(string $path, array &$shas): ?int
{
    $decoded = _stattic_runtime_read_json($path);
    if (!is_array($decoded)) {
        return null;
    }
    $at = _stattic_cas_record_time($decoded, $path);
    _stattic_cas_collect_shas($decoded, $shas, $at);
    return $at;
}

// The response tables name blobs no other declaration has to mention. Reading
// their BYTES, never including them, keeps the live set independent of whether
// a compiler change mirrored a ref into metadata.json.
function _stattic_cas_collect_shas_from_artifact(string $path, array &$shas, int $at): bool
{
    error_clear_last();
    $source = file_get_contents($path, false, null, 0, 4194304);
    if (!is_string($source)) {
        _sf_runtime_log_read_failure('blob_gc_artifact_read_failed', $path);
        return false;
    }
    if (preg_match_all('/[a-f0-9]{64}/', $source, $matches) < 1) {
        return true;
    }
    foreach ($matches[0] as $sha) {
        $shas[$sha] = max($shas[$sha] ?? 0, $at);
    }
    return true;
}

/**
 * Every sha this space must still be able to answer, mapped to the timestamp of
 * the newest declaration that names it: retained versions' `metadata.json` and
 * response tables, the live overlay, space storage, publish sessions in
 * flight.
 *
 * Null means one live declaration was unreadable, so this space cannot be
 * reasoned about and the caller touches nothing in it.
 *
 * $budgetDeadline makes running out of clock mean the same thing for THIS
 * tick. The set is a WHOLE-SPACE answer — a partial one reads as "nothing
 * declares these bytes" and is a delete-live-data hazard — so a walk cut short
 * never returns what it had. It does keep it: the walk checkpoints in
 * gc/live-set.json and the next tick resumes where this one stopped, instead
 * of starting over and never finishing a space with thousands of versions
 * (the dashboard Space's pass timed out on every run for three weeks that way).
 *
 * A completed scan serves every pass of its Space until the box-wide run
 * completes. Each tick folds the declarations written since the last fold —
 * versions the scan never saw, version roots rewritten since, declaration
 * records touched since, the overlay — into the checkpoint and advances its
 * watermark, so the answer the pass decides against is still complete and
 * nothing is read twice; a fold the budget interrupts resumes from its own
 * ledger. The run that completes every Space discards the checkpoints
 * (_stattic_cas_local_blob_gc_run), and the next run scans afresh, so what
 * retention deleted in between leaves the live set then. A completed scan
 * older than _stattic_cas_live_set_max_age_seconds() is discarded for the
 * same reason when a run cannot complete.
 *
 * @return array{shas:array<string,int>,version_ids:list<string>}|null
 */
function _stattic_cas_space_live_set(string $privateRoot, string $spaceId, ?float $budgetDeadline = null, ?callable $budgetClock = null): ?array
{
    $budgetClock ??= static fn (): float => microtime(true);
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $checkpointPath = _stattic_cas_live_set_checkpoint_path($privateRoot, $spaceId);
    $state = _stattic_cas_live_set_load($checkpointPath);
    // A completed scan whose pass has not finished in all this time is a stale
    // picture: what retention deleted since is still in it. Read disk afresh.
    if ($state !== null && $state['phase'] === 'complete'
        && (int) $state['completed_at'] + _stattic_cas_live_set_max_age_seconds() < time()) {
        _stattic_cas_live_set_discard_path($checkpointPath);
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'blob_gc_live_set_expired',
            'space_id' => $spaceId,
            'completed_at' => $state['completed_at'],
        ]);
        $state = null;
    }
    if ($state === null) {
        $state = [
            'version' => STATTIC_CAS_LIVE_SET_CHECKPOINT_VERSION,
            'started_at' => time(),
            'completed_at' => null,
            'delta_since' => null,
            'phase' => 'versions',
            'cursor' => null,
            'fold_started_at' => null,
            'fold_seen' => [],
            'shas' => [],
            'version_ids' => [],
        ];
    }
    // Declarations read this tick, across the scan and the fold: the step
    // limit lets a test drive both without a clock.
    $stepLimit = _stattic_cas_live_set_step_limit();
    $steps = 0;
    $spent = static function () use ($budgetDeadline, $budgetClock, $stepLimit, &$steps): bool {
        return ($budgetDeadline !== null && $budgetClock() >= $budgetDeadline)
            || ($stepLimit > 0 && $steps >= $stepLimit);
    };
    if ($state['phase'] !== 'complete') {
        $outcome = _stattic_cas_live_set_scan($state, $spaceRoot, $privateRoot, $spaceId, $spent, $steps);
        if ($outcome === 'complete') {
            $state['completed_at'] = time();
            // Everything written before the scan began was ahead of its walk
            // and read; what was written since has a file time at or after it,
            // less the second a coarse kernel clock can lag the one time() read.
            $state['delta_since'] = (int) $state['started_at'] - 1;
        }
        // Progress is kept on every outcome. An unreadable declaration leaves the
        // cursor before it, so the next tick retries that one file rather than
        // the whole space, and the pass stays unfinished until it reads.
        _stattic_cas_live_set_write($checkpointPath, $state);
        if ($outcome !== 'complete') {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'blob_gc_live_set_yielded',
                'space_id' => $spaceId,
                'phase' => $state['phase'],
                'reason' => $outcome,
                'versions' => count($state['version_ids']),
            ]);
            return null;
        }
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'blob_gc_live_set_complete',
            'space_id' => $spaceId,
            'versions' => count($state['version_ids']),
            'shas' => count($state['shas']),
            'scan_seconds' => max(0, (int) $state['completed_at'] - (int) $state['started_at']),
        ]);
    }
    $before = [$state['fold_started_at'], $state['fold_seen'], $state['delta_since']];
    $fold = _stattic_cas_live_set_fold_delta($state, $spaceRoot, $privateRoot, $spaceId, $spent, $steps);
    if ($fold['folded'] > 0 || $before !== [$state['fold_started_at'], $state['fold_seen'], $state['delta_since']]) {
        // Whatever moved is kept: what a cut-short fold read (over-inclusion
        // is safe), what it has seen, and a finished fold's cleared ledger and
        // advanced watermark. A stale ledger left on disk would be resumed
        // forever, skipping everything it names.
        _stattic_cas_live_set_write($checkpointPath, $state);
    }
    if ($fold['outcome'] !== 'complete') {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'blob_gc_live_set_yielded',
            'space_id' => $spaceId,
            'phase' => 'fold',
            'reason' => $fold['outcome'],
            'versions' => count($state['version_ids']),
        ]);
        return null;
    }
    $versionIds = $state['version_ids'];
    ksort($versionIds, SORT_STRING);
    return ['shas' => $state['shas'], 'version_ids' => array_keys($versionIds)];
}

const STATTIC_CAS_LIVE_SET_CHECKPOINT_VERSION = 1;

function _stattic_cas_live_set_checkpoint_path(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/gc/live-set.json';
}

function _stattic_cas_live_set_discard(string $privateRoot, string $spaceId): void
{
    _stattic_cas_live_set_discard_path(_stattic_cas_live_set_checkpoint_path($privateRoot, $spaceId));
}

// Declarations a scan collects per tick before it yields, so a test can drive
// the checkpoint without a clock. 0, the default, means budget-bounded only.
function _stattic_cas_live_set_step_limit(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_LIVE_SET_STEP_LIMIT', 0, 0);
}

// How long a completed scan may serve when the box-wide run does not finish.
// The live set only grows while it serves (folds add, nothing removes), so a
// run that cannot complete would otherwise never collect what retention deleted.
function _stattic_cas_live_set_max_age_seconds(): int
{
    return _stattic_config_int('SPACEFAST_LOCAL_BLOB_GC_LIVE_SET_MAX_AGE_SECONDS', 21600, 1);
}

/**
 * The checkpoint on disk, or null when there is none worth resuming. Like the
 * prefix cursor, it is an optimization about where to resume, never a safety
 * property: anything unreadable or malformed costs a restart, not a wrong answer.
 */
function _stattic_cas_live_set_load(string $path): ?array
{
    $stored = _stattic_runtime_read_json($path);
    if (!is_array($stored)
        || ($stored['version'] ?? null) !== STATTIC_CAS_LIVE_SET_CHECKPOINT_VERSION
        || !is_int($stored['started_at'] ?? null)
        || !(is_int($stored['completed_at'] ?? null) || ($stored['completed_at'] ?? null) === null)
        || !(is_int($stored['delta_since'] ?? null) || ($stored['delta_since'] ?? null) === null)
        || !(is_int($stored['fold_started_at'] ?? null) || ($stored['fold_started_at'] ?? null) === null)
        || !is_array($stored['fold_seen'] ?? null)
        || !in_array($stored['phase'] ?? null, ['versions', 'declarations', 'complete'], true)
        || (($stored['phase'] ?? null) === 'complete') !== is_int($stored['completed_at'] ?? null)
        || !(is_string($stored['cursor'] ?? null) || ($stored['cursor'] ?? null) === null)
        || !is_array($stored['shas'] ?? null)
        || !is_array($stored['version_ids'] ?? null)) {
        if ($stored !== null) {
            _stattic_cas_live_set_discard_path($path);
        }
        return null;
    }
    foreach ($stored['shas'] as $sha => $at) {
        if (!is_string($sha) || !_stattic_is_sha256_hex($sha) || !is_int($at)) {
            _stattic_cas_live_set_discard_path($path);
            return null;
        }
    }
    return [
        'version' => STATTIC_CAS_LIVE_SET_CHECKPOINT_VERSION,
        'started_at' => $stored['started_at'],
        'completed_at' => $stored['completed_at'],
        'delta_since' => $stored['delta_since'],
        'phase' => $stored['phase'],
        'cursor' => $stored['cursor'],
        'fold_started_at' => $stored['fold_started_at'],
        'fold_seen' => $stored['fold_seen'],
        'shas' => $stored['shas'],
        'version_ids' => $stored['version_ids'],
    ];
}

// The ledger is keyed by entry and read back as a map, so an empty one must
// encode as {} rather than PHP's [] for an empty array.
function _stattic_cas_live_set_write(string $path, array $state): void
{
    $state['fold_seen'] = _stattic_runtime_json_object($state['fold_seen']);
    _stattic_runtime_write_json_atomic($path, $state);
}

function _stattic_cas_live_set_discard_path(string $path): void
{
    clearstatcache(true, $path);
    if (is_file($path)) {
        unlink($path);
    }
}

/** The version ids under a space's versions root, ascending, with their roots. */
function _stattic_cas_live_set_version_roots(string $spaceRoot): ?array
{
    $versionRoots = _stattic_runtime_directory_entries($spaceRoot . '/versions');
    if ($versionRoots === null) {
        return null;
    }
    $versions = [];
    foreach ($versionRoots as $versionRoot) {
        $versionId = basename($versionRoot);
        if (!is_dir($versionRoot) || !_stattic_runtime_id_valid($versionId) || !str_starts_with($versionId, 'ver_')) {
            continue;
        }
        $versions[$versionId] = $versionRoot;
    }
    ksort($versions, SORT_STRING);
    return $versions;
}

/** One version's declarations: its catalog and its response tables, on its own clock. */
function _stattic_cas_live_set_collect_version(string $versionRoot, array &$shas, string $privateRoot, string $spaceId): bool
{
    // The tables were published with their version, so they share its clock.
    $publishedAt = _stattic_cas_collect_shas_from_json($versionRoot . '/metadata.json', $shas);
    if ($publishedAt === null) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'blob_gc_declaration_gap',
            'space_id' => $spaceId,
            'version_id' => basename($versionRoot),
            'reason' => 'version_metadata_unreadable',
        ]);
        return false;
    }
    $versionEntries = _stattic_runtime_directory_entries($versionRoot);
    if ($versionEntries === null) {
        return false;
    }
    foreach ($versionEntries as $table) {
        if (fnmatch('responses-*.php', basename($table)) && !_stattic_cas_collect_shas_from_artifact($table, $shas, $publishedAt)) {
            return false;
        }
    }
    return true;
}

// A record store keeps locks and markers beside its records; those are touched
// on every tick and declare nothing, so they are not walked, counted or
// remembered.
function _stattic_cas_live_set_is_declaration(string $path): bool
{
    return is_dir($path) || (is_file($path) && str_ends_with($path, '.json'));
}

/** One entry of a declarations root: a record, or a directory of records. */
function _stattic_cas_live_set_collect_declaration(string $path, array &$shas): bool
{
    if (is_file($path)) {
        return !str_ends_with($path, '.json') || _stattic_cas_collect_shas_from_json($path, $shas) !== null;
    }
    if (!is_dir($path)) {
        return true;
    }
    $nestedEntries = _stattic_runtime_directory_entries($path);
    if ($nestedEntries === null) {
        return false;
    }
    foreach ($nestedEntries as $nestedPath) {
        if (is_file($nestedPath) && str_ends_with($nestedPath, '.json') && _stattic_cas_collect_shas_from_json($nestedPath, $shas) === null) {
            return false;
        }
    }
    return true;
}

// The live overlay names blobs no version declares: a tag/SDK body too large
// to sit inline in the opcached artifact lives in the CAS with only the
// overlay pointing at it.
function _stattic_cas_live_set_collect_overlay(string $spaceRoot, array &$shas): bool
{
    $spacePointer = _stattic_runtime_read_json($spaceRoot . '/space.json');
    if ($spacePointer === false) {
        return false;
    }
    $overlayName = is_array($spacePointer) && is_string($spacePointer['overlay'] ?? null)
        ? $spacePointer['overlay']
        : null;
    if ($overlayName === null || strpos($overlayName, '..') !== false) {
        return true;
    }
    $overlayPath = $spaceRoot . '/' . $overlayName;
    return _stattic_cas_collect_shas_from_artifact($overlayPath, $shas, _stattic_cas_record_time(null, $overlayPath));
}

const STATTIC_CAS_LIVE_SET_DECLARATION_ROOTS = ['uploads', 'publish-sessions', 'commerce-assets'];

/**
 * Advance the scan within this tick's budget. Returns 'complete', 'yielded'
 * (budget or step limit spent, cursor after the last declaration collected)
 * or 'unreadable' (cursor before the declaration that could not be read).
 */
function _stattic_cas_live_set_scan(array &$state, string $spaceRoot, string $privateRoot, string $spaceId, callable $spent, int &$steps): string
{
    if ($state['phase'] === 'versions') {
        $versions = _stattic_cas_live_set_version_roots($spaceRoot);
        if ($versions === null) {
            return 'unreadable';
        }
        foreach ($versions as $versionId => $versionRoot) {
            if ($state['cursor'] !== null && strcmp($versionId, $state['cursor']) <= 0) {
                continue;
            }
            if ($spent()) {
                return 'yielded';
            }
            if (!_stattic_cas_live_set_collect_version($versionRoot, $state['shas'], $privateRoot, $spaceId)) {
                return 'unreadable';
            }
            $state['version_ids'][$versionId] = true;
            $state['cursor'] = $versionId;
            $steps++;
        }
        $state['phase'] = 'declarations';
        $state['cursor'] = null;
    }
    foreach (STATTIC_CAS_LIVE_SET_DECLARATION_ROOTS as $index => $name) {
        $entries = _stattic_runtime_directory_entries($spaceRoot . '/' . $name);
        if ($entries === null) {
            return 'unreadable';
        }
        sort($entries, SORT_STRING);
        foreach ($entries as $path) {
            // Roots are walked in declaration order, so a key sorts by root first.
            $key = $index . ':' . basename($path);
            if ($state['cursor'] !== null && strcmp($key, $state['cursor']) <= 0) {
                continue;
            }
            if (!_stattic_cas_live_set_is_declaration($path)) {
                continue;
            }
            if ($spent()) {
                return 'yielded';
            }
            if (!_stattic_cas_live_set_collect_declaration($path, $state['shas'])) {
                return 'unreadable';
            }
            $state['cursor'] = $key;
            $steps++;
        }
    }
    if (!_stattic_cas_live_set_collect_overlay($spaceRoot, $state['shas'])) {
        return 'unreadable';
    }
    $state['phase'] = 'complete';
    $state['cursor'] = null;
    return 'complete';
}

/**
 * Fold everything declared since the checkpoint's watermark into it. A fold
 * the budget cuts short keeps what it read (over-inclusion is safe — the live
 * set may only grow) and a ledger of what it saw, each entry at the file time
 * it was read with. The next tick resumes by reading what the ledger does not
 * name and what it names with a newer file time, so a fold that walks to the
 * end has read everything written up to then and its answer is whole,
 * whether it took one tick or several. Only then does the watermark move, to
 * when the fold began less a second of clock slack, so a declaration written
 * while it walked is read once more by the next fold, never missed. File
 * times are whole seconds: a rewrite inside the second an entry was read
 * with waits for the next fold, which the slack guarantees reads it.
 * Returns 'complete', 'yielded' or 'unreadable' with the count folded.
 *
 * @return array{outcome:string,folded:int}
 */
function _stattic_cas_live_set_fold_delta(array &$state, string $spaceRoot, string $privateRoot, string $spaceId, callable $spent, int &$steps): array
{
    if (!is_int($state['fold_started_at'])) {
        $state['fold_started_at'] = time();
        $state['fold_seen'] = [];
    }
    $since = is_int($state['delta_since']) ? $state['delta_since'] : (int) $state['started_at'];
    $folded = 0;
    // The file time to read an entry with, or null when this fold has read it as it is.
    $due = static function (string $key, string $path) use (&$state, $since): ?int {
        clearstatcache(true, $path);
        $mtime = filemtime($path);
        if ($mtime === false) {
            return null;
        }
        $seen = $state['fold_seen'][$key] ?? null;
        return (is_int($seen) ? $mtime > $seen : $mtime >= $since) ? $mtime : null;
    };
    $versions = _stattic_cas_live_set_version_roots($spaceRoot);
    if ($versions === null) {
        return ['outcome' => 'unreadable', 'folded' => $folded];
    }
    foreach ($versions as $versionId => $versionRoot) {
        $key = '0:' . $versionId;
        $mtime = $due($key, $versionRoot);
        // A root the scan saw changes only when something is written into it
        // (a finalize artifact, a sidecar); a root it never saw is read whole.
        if ($mtime === null && (isset($state['version_ids'][$versionId]) || isset($state['fold_seen'][$key]))) {
            continue;
        }
        if ($spent()) {
            return ['outcome' => 'yielded', 'folded' => $folded];
        }
        if (!_stattic_cas_live_set_collect_version($versionRoot, $state['shas'], $privateRoot, $spaceId)) {
            return ['outcome' => 'unreadable', 'folded' => $folded];
        }
        $state['version_ids'][$versionId] = true;
        $state['fold_seen'][$key] = $mtime ?? (int) (filemtime($versionRoot) ?: 0);
        $steps++;
        $folded++;
    }
    foreach (STATTIC_CAS_LIVE_SET_DECLARATION_ROOTS as $index => $name) {
        $entries = _stattic_runtime_directory_entries($spaceRoot . '/' . $name);
        if ($entries === null) {
            return ['outcome' => 'unreadable', 'folded' => $folded];
        }
        foreach ($entries as $path) {
            if (!_stattic_cas_live_set_is_declaration($path)) {
                continue;
            }
            $key = '1:' . $index . ':' . basename($path);
            $mtime = $due($key, $path);
            if ($mtime === null) {
                continue;
            }
            if ($spent()) {
                return ['outcome' => 'yielded', 'folded' => $folded];
            }
            if (!_stattic_cas_live_set_collect_declaration($path, $state['shas'])) {
                return ['outcome' => 'unreadable', 'folded' => $folded];
            }
            $state['fold_seen'][$key] = $mtime;
            $steps++;
            $folded++;
        }
    }
    // One artifact: cheaper to re-read than to reason about its pointer moving.
    if (!_stattic_cas_live_set_collect_overlay($spaceRoot, $state['shas'])) {
        return ['outcome' => 'unreadable', 'folded' => $folded];
    }
    $state['delta_since'] = (int) $state['fold_started_at'] - 1;
    $state['fold_started_at'] = null;
    $state['fold_seen'] = [];
    return ['outcome' => 'complete', 'folded' => $folded];
}

/** @return array<string,int>|null sha => publish timestamp */
function _stattic_cas_space_live_shas(string $privateRoot, string $spaceId, ?float $budgetDeadline = null, ?callable $budgetClock = null): ?array
{
    $live = _stattic_cas_space_live_set($privateRoot, $spaceId, $budgetDeadline, $budgetClock);
    return $live === null ? null : $live['shas'];
}

/**
 * Null means a pin file exists that this process cannot read: the space's
 * deletions are then skipped entirely rather than guessed at, because an
 * unreadable pin is indistinguishable from one protecting everything.
 *
 * @return array<string,true>|null
 */
function _stattic_cas_space_pinned_shas(
    string $privateRoot,
    string $spaceId,
    int $now,
    ?float $budgetDeadline = null,
    ?callable $budgetClock = null
): ?array {
    $budgetClock ??= static fn (): float => microtime(true);
    $spaceRoot = _stattic_space_root($privateRoot, $spaceId);
    $pinned = [];
    $pinEntries = _stattic_runtime_directory_entries($spaceRoot . '/pins');
    if ($pinEntries === null) {
        return null;
    }
    foreach ($pinEntries as $path) {
        // Same whole-answer rule as the live set: a pin the budget cut off is
        // indistinguishable from no pin at all, and that difference is bytes.
        if ($budgetDeadline !== null && $budgetClock() >= $budgetDeadline) {
            return null;
        }
        if (!is_file($path) || !str_ends_with($path, '.json')) {
            continue;
        }
        $pin = _stattic_runtime_read_json($path);
        if (!is_array($pin)) {
            _stattic_runtime_append_journal($privateRoot, [
                'event' => 'blob_gc_pin_unreadable',
                'space_id' => $spaceId,
                'pin' => basename($path),
            ]);
            return null;
        }
        // Unparseable (or absent) expiry is treated as EXPIRED, never as
        // "forever": a pin suppresses collection, so dropping one we cannot
        // reason about is the failure that costs nothing.
        $expiresAt = _stattic_record_store_timestamp($pin['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt < $now) {
            unlink($path);
            continue;
        }
        foreach (is_array($pin['shas'] ?? null) ? $pin['shas'] : [] as $sha) {
            if (is_string($sha) && _stattic_is_sha256_hex($sha)) {
                $pinned[$sha] = true;
            }
        }
    }
    return $pinned;
}

// The CAS of a real space holds hundreds of thousands of blobs (431k files in
// one measured production space), so nothing here may materialize one array
// entry per blob of the space: that map exhausts the 512MB request limit. Scans
// stream one 2-hex prefix directory (1/256 of the CAS) at a time.

function _stattic_cas_space_blobs_root(string $privateRoot, string $spaceId): string
{
    $blobsRoot = _stattic_space_root($privateRoot, $spaceId) . '/blobs';
    _stattic_runtime_assert_private_path($blobsRoot);
    return $blobsRoot;
}

/**
 * The 2-hex prefix directories present in one space's CAS, ascending.
 *
 * @return list<string>|null null when the CAS root exists but is unreadable.
 */
function _stattic_cas_space_blob_prefixes(string $privateRoot, string $spaceId): ?array
{
    $blobsRoot = _stattic_cas_space_blobs_root($privateRoot, $spaceId);
    $entries = _stattic_runtime_directory_entries($blobsRoot);
    if ($entries === null) {
        return null;
    }
    $prefixes = [];
    foreach ($entries as $path) {
        $entry = basename($path);
        if (preg_match('/^[a-f0-9]{2}$/', $entry) === 1 && is_dir($path)) {
            $prefixes[] = $entry;
        }
    }
    return $prefixes;
}

/**
 * One prefix directory of a space's CAS, as {sha => size}. Only the size is
 * kept, never the full stat record, because this map exists once per prefix
 * and its callers need nothing else per blob.
 *
 * @return array<string, int>|null
 */
function _stattic_cas_prefix_blobs(string $blobsRoot, string $prefix): ?array
{
    $entries = scandir($blobsRoot . '/' . $prefix);
    if (!is_array($entries)) {
        return null;
    }
    $blobs = [];
    foreach ($entries as $sha) {
        if (!_stattic_is_sha256_hex($sha) || !str_starts_with($sha, $prefix)) {
            continue;
        }
        $stat = stat($blobsRoot . '/' . $prefix . '/' . $sha);
        if (is_array($stat) && (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000) {
            $blobs[$sha] = (int) ($stat['size'] ?? 0);
        }
    }
    return $blobs;
}

function _stattic_cas_gc_marks_path(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/gc/marks.json';
}

/**
 * Where a truncated GC pass says it stopped, so the next one resumes there.
 *
 * Without this the walk restarts at the first prefix every tick: a pass that a
 * 20 ms budget cut short observed nothing durable, and the prefixes behind the
 * obstruction were never reached at ANY number of equal-budget ticks. The
 * per-space file holds the 2-hex prefix to resume at; the box-wide one holds the
 * space id, for the same reason one level up.
 */
function _stattic_cas_gc_cursor_path(string $privateRoot, string $spaceId): string
{
    return _stattic_space_root($privateRoot, $spaceId) . '/gc/cursor.json';
}

function _stattic_cas_read_cursor(string $path): ?string
{
    $stored = _stattic_runtime_read_json($path);
    if (!is_array($stored)) {
        // Absent OR unreadable: a cursor is an optimization about where to
        // resume, never a safety property, so losing one costs a restart.
        return null;
    }
    $at = $stored['at'] ?? null;
    return is_string($at) && $at !== '' ? $at : null;
}

function _stattic_cas_write_cursor(string $path, ?string $at): void
{
    if ($at === null) {
        if (is_file($path)) {
            unlink($path);
        }
        return;
    }
    _stattic_runtime_write_json_atomic($path, ['at' => $at]);
}

/**
 * The ascending walk order rotated to start at $cursor and wrap back around to
 * the entries before it, so one tick's resume point still yields a whole pass
 * when the budget allows. A cursor whose entry is gone starts over.
 *
 * @param list<string> $items
 * @return list<string>
 */
function _stattic_cas_resume_order(array $items, ?string $cursor): array
{
    if ($cursor === null || $items === []) {
        return $items;
    }
    $start = null;
    foreach ($items as $index => $item) {
        if ($item >= $cursor) {
            $start = $index;
            break;
        }
    }
    if ($start === null || $start === 0) {
        return $items;
    }
    return [...array_slice($items, $start), ...array_slice($items, 0, $start)];
}

/**
 * Pass 2. Every deletion in $deletions is `[sha, path, size]`, applied in
 * batches under one short per-space write lock each, so the O(all blobs) scan
 * above never holds the lock a publish needs. Nothing is journaled from inside
 * the lock; the caller emits one record for the pass.
 *
 * @return array{collected: list<string>, bytes: int, complete: bool}
 */
function _stattic_cas_gc_apply_deletions(
    string $privateRoot,
    string $spaceId,
    array $deletions,
    ?float $budgetDeadline = null,
    ?callable $budgetClock = null
): array {
    if (_stattic_evidence_deletion_held('runtime.blobs', count($deletions))) {
        return ['collected' => [], 'bytes' => 0, 'complete' => true];
    }
    $budgetClock ??= static fn (): float => microtime(true);
    $collected = [];
    $bytes = 0;
    $complete = true;
    foreach (array_chunk($deletions, STATTIC_CAS_GC_DELETE_BATCH) as $batch) {
        // A batch is the bounded unit here: it is one short lock hold, and a
        // batch this pass never applied simply keeps its mark, so the next pass
        // re-decides it against a freshly read live set.
        if ($budgetDeadline !== null && $budgetClock() >= $budgetDeadline) {
            $complete = false;
            break;
        }
        $applied = _stattic_space_write_lock_with(
            $privateRoot,
            $spaceId,
            STATTIC_LOCK_TRY,
            null,
            static function () use ($batch, &$collected, &$bytes): bool {
                foreach ($batch as $deletion) {
                    [$sha, $path, $size] = $deletion;
                    if (is_file($path) && !unlink($path)) {
                        continue;
                    }
                    $collected[] = $sha;
                    $bytes += $size;
                }
                return true;
            },
        );
        if ($applied !== true) {
            // Contended: try-once by contract, so this batch retries next scan.
            $complete = false;
        }
    }
    return ['collected' => $collected, 'bytes' => $bytes, 'complete' => $complete];
}

/**
 * One space's full GC pass, streamed one CAS prefix at a time. Deleting is a
 * two-tick decision: a blob is removed only after it was ALREADY a candidate
 * (in gc/marks.json) one grace period ago, so a publish that declares a blob
 * between two ticks cannot lose it to an earlier scan.
 *
 * A pass the budget cuts short must do two things AT ONCE, and doing only the
 * first is how a GC stops collecting anything at all:
 *
 *   - it must not publish a partial replacement mark set. The map it built
 *     covers only the prefixes it walked, so writing it as-is would erase the
 *     elapsed grace of every sha behind the truncation point and restart their
 *     clocks — a mark map that can be reset is a mark map that never matures.
 *   - it must still persist what it DID observe. Discarding every new
 *     observation means no garbage ever acquires a first-seen time, the grace
 *     period never starts, and repeating the same budget collects nothing
 *     forever (measured: three 20 ms passes over 12,000 blobs, two logical days
 *     apart, deleted 0 and wrote no marks at all).
 *
 * So marks are MERGED: visited prefixes take this pass's observations, unvisited
 * prefixes keep their stored ones untouched. gc/cursor.json then carries where
 * the walk stopped, so the next tick starts there instead of re-walking the same
 * head of the CAS — without it a populated early prefix starves every prefix
 * behind it at any number of equal budgets. Deletion still revalidates the live
 * set and pins on every pass; the cursor only decides walk ORDER.
 *
 * @return array{complete: bool, deleted: int, bytes: int}
 */
function _stattic_cas_space_blob_gc(
    string $privateRoot,
    string $spaceId,
    int $now,
    int $grace,
    ?float $budgetDeadline = null,
    ?callable $budgetClock = null
): array {
    $budgetClock ??= static fn (): float => microtime(true);
    $skipped = ['complete' => false, 'deleted' => 0, 'bytes' => 0];
    $prefixes = _stattic_cas_space_blob_prefixes($privateRoot, $spaceId);
    if ($prefixes === null) {
        return $skipped;
    }
    $live = _stattic_cas_space_live_shas($privateRoot, $spaceId, $budgetDeadline, $budgetClock);
    if ($live === null) {
        return $skipped;
    }
    $pinned = _stattic_cas_space_pinned_shas($privateRoot, $spaceId, $now, $budgetDeadline, $budgetClock);
    if ($pinned === null) {
        return $skipped;
    }

    $blobsRoot = _stattic_cas_space_blobs_root($privateRoot, $spaceId);
    $marksPath = _stattic_cas_gc_marks_path($privateRoot, $spaceId);
    $stored = _stattic_runtime_read_json($marksPath);
    if ($stored === false) {
        return $skipped;
    }
    $storedMarks = is_array($stored) ? $stored : [];
    $cursorPath = _stattic_cas_gc_cursor_path($privateRoot, $spaceId);
    $order = _stattic_cas_resume_order($prefixes, _stattic_cas_read_cursor($cursorPath));
    // With grace pinned to 0 both passes run inside one tick, so the two-pass
    // rule closes to nothing and the GC would delete bytes a publish is mid-way
    // through committing. The undeclared lane keeps a floor of its own.
    $undeclaredDeadline = $now - max(0, $grace, _stattic_cas_gc_undeclared_min_grace_seconds());

    $marks = [];
    $visited = [];
    $resumeAt = null;
    $collected = [];
    $bytes = 0;
    $complete = true;
    foreach ($order as $prefix) {
        // One CAS prefix is the bounded unit: it is enumerated whole, so the
        // marks for it are a whole answer about it.
        if ($budgetDeadline !== null && $budgetClock() >= $budgetDeadline) {
            $resumeAt = $prefix;
            $complete = false;
            break;
        }
        $blobs = _stattic_cas_prefix_blobs($blobsRoot, $prefix);
        if ($blobs === null) {
            // Skip it unvisited, so the merge keeps its stored marks while the
            // readable prefixes still collect. Incomplete, but not truncated:
            // no cursor, or the next pass would start at the failure again.
            $complete = false;
            continue;
        }
        $deletions = [];
        foreach ($blobs as $sha => $size) {
            // Live bytes are never candidates. A stored mark whose blob became
            // live again or left the disk is dropped by construction, never
            // re-added.
            if (isset($live[$sha])) {
                continue;
            }
            // Candidacy: every present non-live sha carries a mark, its stored
            // first-seen time if it already had one, $now otherwise.
            $storedSeen = $storedMarks[$sha] ?? null;
            $firstSeen = is_int($storedSeen) ? $storedSeen : $now;
            $marks[$sha] = $firstSeen;
            if (isset($pinned[$sha])) {
                continue;
            }
            // Garbage: nothing declares it, so the mark clock is the only
            // clock. The two-pass rule plus the undeclared floor is the
            // protection. A hot PREVIEW blob is not spared; the next request
            // regenerates it.
            if ($firstSeen <= $undeclaredDeadline) {
                $deletions[] = [$sha, $blobsRoot . '/' . $prefix . '/' . $sha, $size];
            }
        }
        // The prefix is walked, so this pass's marks for it are authoritative —
        // including the shas it did NOT re-observe, whose stored marks are meant
        // to be dropped.
        $visited[$prefix] = true;
        if ($deletions === []) {
            continue;
        }
        $applied = _stattic_cas_gc_apply_deletions($privateRoot, $spaceId, $deletions, $budgetDeadline, $budgetClock);
        $collected = [...$collected, ...$applied['collected']];
        $bytes += $applied['bytes'];
        $complete = $complete && $applied['complete'];
    }

    if ($collected !== []) {
        _stattic_runtime_append_journal($privateRoot, [
            'event' => 'local_blob_gc',
            'space_id' => $spaceId,
            'bytes' => $bytes,
            'collected' => $collected,
        ]);
    }
    // Only shas this pass removed lose their mark: a batch the lock refused
    // keeps its elapsed grace, so contention delays a deletion by one scan
    // instead of restarting its clock.
    foreach ($collected as $sha) {
        unset($marks[$sha]);
    }
    if (!$complete) {
        // The merge, and the whole reason an incomplete pass may write at all: a
        // sha whose prefix this pass did not walk (budget cut or unreadable)
        // keeps exactly the mark it had. Nothing about it was observed, so
        // nothing about it is replaced.
        foreach ($storedMarks as $sha => $seen) {
            if (!is_string($sha) || !is_int($seen) || isset($visited[substr($sha, 0, 2)])) {
                continue;
            }
            $marks[$sha] ??= $seen;
        }
    }
    if ($marks !== $storedMarks && !($marks === [] && !is_array($stored))) {
        _stattic_runtime_write_json_atomic($marksPath, $marks);
    }
    _stattic_cas_write_cursor($cursorPath, $resumeAt);
    return ['complete' => $complete, 'deleted' => count($collected), 'bytes' => $bytes];
}

function _stattic_cas_space_ids(string $privateRoot): array|false
{
    _stattic_runtime_assert_private_path($privateRoot . '/spaces');
    $roots = _stattic_runtime_space_roots($privateRoot);
    if ($roots === null) {
        return false;
    }
    $spaceIds = [];
    foreach ($roots as $path) {
        $entry = basename($path);
        if (_stattic_runtime_id_valid($entry)) {
            $spaceIds[] = $entry;
        }
    }
    // Ascending, because the GC's resume cursor is a position in this order.
    sort($spaceIds);
    return $spaceIds;
}

/**
 * True when this call left no collectable byte behind: either the scan was not
 * due (a deliberate cadence, nothing owed) or it walked every space and every
 * prefix. False is the honest answer the throttle alone cannot give — a space
 * tree it could not enumerate, or a per-space pass that a held write lock or an
 * unreadable declaration stopped — and the pass carrying it must not report a
 * step it did not finish.
 */
function _stattic_cas_local_blob_gc_run(
    string $privateRoot,
    int $now,
    int $grace,
    int $scanInterval,
    ?callable $clock = null,
    ?float $budgetDeadline = null,
    ?callable $budgetClock = null
): bool {
    $budgetClock ??= static fn (): float => microtime(true);
    $marker = $privateRoot . '/runtime/blob-gc.marker';
    _stattic_runtime_assert_private_path($marker);
    _stattic_runtime_mkdir_soft(dirname($marker));

    // The same resume rule the per-space walk keeps for its prefixes, one level
    // up: without it the first Space on the box is re-walked every tick and the
    // Spaces behind it are collected only on a tick whose budget covers all of
    // them at once.
    $spaceCursorPath = $privateRoot . '/runtime/blob-gc-cursor.json';
    _stattic_runtime_assert_private_path($spaceCursorPath);

    $complete = true;
    _stattic_sweep_throttled(
        $marker,
        // Grace zero means "collect on every call" (test/ops pin).
        $grace === 0 ? 0 : max(0, $scanInterval),
        static function () use ($privateRoot, $now, $grace, $budgetDeadline, $budgetClock, $spaceCursorPath, &$complete): bool {
            $spaceIds = _stattic_cas_space_ids($privateRoot);
            if (!is_array($spaceIds)) {
                $complete = false;
                return false;
            }
            $order = _stattic_cas_resume_order($spaceIds, _stattic_cas_read_cursor($spaceCursorPath));
            $resumeAt = null;
            foreach ($order as $spaceId) {
                if ($budgetDeadline !== null && $budgetClock() >= $budgetDeadline) {
                    $resumeAt = $spaceId;
                    $complete = false;
                    break;
                }
                if (
                    !_stattic_cas_space_blob_gc($privateRoot, $spaceId, $now, $grace, $budgetDeadline, $budgetClock)['complete']
                ) {
                    $complete = false;
                }
            }
            _stattic_cas_write_cursor($spaceCursorPath, $resumeAt);
            if ($complete) {
                // Every scan has served its run. The next run starts from what
                // is on disk then, so what retention deleted leaves the live set.
                foreach ($spaceIds as $spaceId) {
                    _stattic_cas_live_set_discard($privateRoot, $spaceId);
                }
            }
            return $complete;
        },
        STATTIC_SWEEP_ADVANCE_ON_COMPLETE,
        $now,
        $clock,
    );
    return $complete;
}

function _stattic_runtime_job_housekeeping_local_blob_gc(string $privateRoot, array $claims, float $deadline): bool
{
    $lockPath = $privateRoot . '/runtime/blob-gc.lock';
    _stattic_runtime_mkdir(dirname($lockPath));
    // Try-once by contract. A lock held elsewhere means this pass collected
    // nothing, so it reports a skipped step rather than a clean sweep.
    return _stattic_lock_with(
        $lockPath,
        STATTIC_LOCK_TRY,
        static fn (): bool => false,
        static fn (): bool => _stattic_cas_local_blob_gc_run(
            $privateRoot,
            time(),
            _stattic_cas_gc_grace_seconds(),
            _stattic_cas_gc_scan_interval_seconds(),
            null,
            // The tick's clock, so the CAS walk stops on it rather than running
            // every Space's prefixes to the end inside the bulk lane lock.
            $deadline,
        ),
    );
}

/** Decode and validate an untrusted disk-scan checkpoint before resuming it. */
function _stattic_cas_disk_scan_checkpoint(mixed $saved, string $spaceRoot): ?array
{
    if (!is_array($saved) || ($saved['version'] ?? null) !== 1
        || !is_string($saved['root'] ?? null)
        || base64_decode($saved['root'], true) !== $spaceRoot
        || !is_array($saved['stack'] ?? null) || !array_is_list($saved['stack']) || $saved['stack'] === []
        || !is_array($saved['seen'] ?? null)
        || !is_int($saved['bytes'] ?? null) || $saved['bytes'] < 0
        || !is_int($saved['inodes'] ?? null) || $saved['inodes'] < 0) {
        return null;
    }
    $parent = null;
    foreach ($saved['stack'] as $index => $frame) {
        if (!is_array($frame) || !is_string($frame['path'] ?? null) || !is_string($frame['after'] ?? null)) return null;
        $path = base64_decode($frame['path'], true);
        $after = base64_decode($frame['after'], true);
        if ($path === false || $after === false || str_contains($path, "\0") || str_contains($after, "\0")
            || str_contains($after, '/') || $after === '.' || $after === '..') return null;
        if ($index === 0) {
            if ($path !== '') return null;
        } else {
            $prefix = $parent === '' ? '' : $parent . '/';
            if (!str_starts_with($path, $prefix)) return null;
            $name = substr($path, strlen($prefix));
            if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) return null;
        }
        $saved['stack'][$index] = ['path' => $path, 'after' => $after];
        $parent = $path;
    }
    foreach ($saved['seen'] as $device => $inodes) {
        if (!ctype_digit((string) $device) || !is_array($inodes)) return null;
        foreach ($inodes as $inode => $seen) {
            if (!ctype_digit((string) $inode) || $seen !== true) return null;
        }
    }
    return $saved;
}

/**
 * A Space's whole file tree; hardlinked files count once.
 *
 * With $checkpointPath, a walk the budget cuts short saves its cursor there and
 * the next call resumes it. A checkpoint that fails validation is discarded.
 *
 * `complete` is false when $budgetDeadline stopped the walk or a directory
 * could not be listed. A partial total is not a smaller total — it is a
 * wrong one — so the caller reports nothing rather than an under-count.
 *
 * @return array{bytes:int, inodes:int, complete:bool}
 */
function _stattic_cas_space_disk_usage(string $spaceRoot, ?float $budgetDeadline = null, ?string $checkpointPath = null, ?callable $budgetClock = null): array
{
    $budgetClock ??= static fn (): float => microtime(true);
    if (!is_dir($spaceRoot)) {
        return ['bytes' => 0, 'inodes' => 0, 'complete' => true];
    }
    _stattic_runtime_assert_private_path($spaceRoot);
    $saved = $checkpointPath === null ? null : _stattic_runtime_read_json($checkpointPath);
    $state = _stattic_cas_disk_scan_checkpoint($saved, $spaceRoot);
    if ($state === null) {
        if ($checkpointPath !== null && $saved !== null) @unlink($checkpointPath);
        $state = ['stack' => [['path' => '', 'after' => '']], 'seen' => [], 'bytes' => 0, 'inodes' => 0];
    }
    // Directory cursors retain the last name rather than an offset: removing an
    // earlier entry between ticks must not skip a later file.
    $entriesByDirectory = [];
    while ($state['stack'] !== []) {
        if ($budgetDeadline !== null && $budgetClock() >= $budgetDeadline) {
            if ($checkpointPath !== null) {
                $encoded = $state;
                $encoded['version'] = 1;
                $encoded['root'] = base64_encode($spaceRoot);
                $encoded['stack'] = array_map(static fn (array $frame): array => [
                    'path' => base64_encode($frame['path']),
                    'after' => base64_encode($frame['after']),
                ], $state['stack']);
                _stattic_runtime_write_private_string($checkpointPath, json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            }
            return ['bytes' => $state['bytes'], 'inodes' => $state['inodes'], 'complete' => false];
        }
        $index = count($state['stack']) - 1;
        $frame = $state['stack'][$index];
        $relative = $frame['path'];
        $directory = $spaceRoot . ($relative === '' ? '' : '/' . $relative);
        _stattic_runtime_assert_private_path($directory);
        if (!isset($entriesByDirectory[$directory])) {
            $entries = _stattic_runtime_directory_entries($directory);
            if ($entries === null) {
                return ['bytes' => $state['bytes'], 'inodes' => $state['inodes'], 'complete' => false];
            }
            rsort($entries, SORT_STRING);
            // Filter once per directory on resume, rather than walking the
            // already-counted prefix for every remaining file.
            $entriesByDirectory[$directory] = array_values(array_filter($entries, static fn (string $entry): bool => strcmp(basename($entry), $frame['after']) > 0));
        }
        // Pop in ascending order without shifting a large directory each time.
        $entry = array_pop($entriesByDirectory[$directory]);
        if ($entry === null) {
            array_pop($state['stack']);
            unset($entriesByDirectory[$directory]);
            continue;
        }
        $name = basename($entry);
        $state['stack'][$index]['after'] = $name;
        // One lstat per entry: symlinks are never followed, directories are
        // descended, and only regular files count. An entry that vanished since
        // the listing is skipped.
        $stat = @lstat($entry);
        $type = is_array($stat) ? ((int) $stat['mode']) & 0170000 : null;
        if ($type === 0040000) {
            $state['stack'][] = ['path' => $relative === '' ? $name : $relative . '/' . $name, 'after' => ''];
            continue;
        }
        if ($type !== 0100000) {
            continue;
        }
        // Only hardlinked files need retained inode identities. Ordinary files
        // cannot repeat in this traversal, so a million-file tree does not need
        // a million-element checkpoint just for deduplication.
        if (($stat['nlink'] ?? 1) > 1) {
            $dev = (int) $stat['dev'];
            $ino = (int) $stat['ino'];
            if (isset($state['seen'][$dev][$ino])) {
                continue;
            }
            $state['seen'][$dev][$ino] = true;
        }
        $state['bytes'] += (int) $stat['size'];
        $state['inodes'] += 1;
    }
    if ($checkpointPath !== null && is_file($checkpointPath)) {
        unlink($checkpointPath);
    }
    return ['bytes' => $state['bytes'], 'inodes' => $state['inodes'], 'complete' => true];
}

const STATTIC_CAS_DISK_REPORT_INTERVAL_SECONDS = 21600;

function _stattic_runtime_job_housekeeping_disk_report(string $privateRoot, array $claims, float $deadline): bool
{
    $now = time();
    // null = unenumerable this tick; report nothing rather than "no spaces",
    // and say the step did not run rather than let an empty walk read as one.
    $spaceRoots = _stattic_runtime_space_roots($privateRoot);
    if ($spaceRoots === null) {
        return false;
    }
    $complete = true;
    foreach ($spaceRoots as $spaceRoot) {
        // One Space is the bounded unit: its report is a single total, so the
        // budget is spent between Spaces and inside one walk, never on half a
        // figure.
        if (microtime(true) >= $deadline) {
            return false;
        }
        _stattic_runtime_assert_private_path($spaceRoot);
        $spaceId = basename($spaceRoot);
        _stattic_sweep_throttled(
            $spaceRoot . '/disk-report.marker',
            STATTIC_CAS_DISK_REPORT_INTERVAL_SECONDS,
            static function () use ($privateRoot, $spaceRoot, $spaceId, $claims, $now, $deadline, &$complete): bool {
                $usage = _stattic_cas_space_disk_usage($spaceRoot, $deadline, $privateRoot . '/runtime/disk-reports/' . $spaceId . '.json');
                if (!$usage['complete']) {
                    // An under-count is worse than no report: the control plane
                    // would bank it as this Space's size for the whole interval.
                    $complete = false;
                    return false;
                }
                _stattic_runtime_record_management_event($privateRoot, $claims, [
                    'event' => 'space.disk.report',
                    'spaceId' => $spaceId,
                    'bytes' => $usage['bytes'],
                    'inodes' => $usage['inodes'],
                    'generatedAt' => gmdate('c', $now),
                ]);
                return true;
            },
            // The six-hourly cadence advances only for a Space this pass really
            // measured; a walk the budget cut short is due again next tick.
            STATTIC_SWEEP_ADVANCE_ON_COMPLETE,
            $now,
        );
    }
    return $complete;
}
