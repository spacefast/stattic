<?php
declare(strict_types=1);

require_once __DIR__ . '/lock.php';

// THE append-only NDJSON log: line-delimited records appended under the private
// root. Its one remaining user is the operator journal, which the control plane
// drains forward through the events API — nothing scans these files backwards
// any more, because nothing but the journal is written here. A space's own
// runtime output goes to PHP's error log instead (shared/runtime-log.php), the
// only stream the provider ships off the box.
//
// Do not open-code file_put_contents(FILE_APPEND) at call sites; open a log.
// Every knob is a descriptor field, never a second implementation:
//   path       the file this append lands in; a caller that rotates by time
//              derives it per append
//   lock       the file appends serialize on, never the data file itself: a
//              rotation renames the data file out from under any handle held on
//              it, so the lock has to outlive the bytes it guards
//   max_bytes  ceiling on one file, 0 for unbounded
//   on_full    STATTIC_NDJSON_LOG_ROTATE rolls the file aside and keeps
//              appending; STATTIC_NDJSON_LOG_DROP refuses the append, which is
//              what keeps a tenant in a log loop from filling the disk
//   rotated    sprintf template for the rolled-aside name, %s a sortable unique
//              stamp; ROTATE without one degrades to DROP
//
// Bounding one file is not bounding the history: rolled-aside and time-rotated
// files are reclaimed by the one retention registry, admin/retention.php.

const STATTIC_NDJSON_LOG_ROTATE = 'rotate';
const STATTIC_NDJSON_LOG_DROP = 'drop';
const STATTIC_NDJSON_LOG_READ_CHUNK_BYTES = 262144;

function _stattic_ndjson_log(string $path, array $descriptor = []): array
{
    return $descriptor + [
        'path' => $path,
        'lock' => $path . '.lock',
        'max_bytes' => 0,
        'on_full' => STATTIC_NDJSON_LOG_DROP,
        'rotated' => null,
    ];
}

// $blocking = false is the serve-path mode: a contended append is skipped
// rather than making a visitor request wait for the lock. Best-effort either
// way — an unwritable root loses the records, it never fails the request.
function _stattic_ndjson_log_append(array $log, array $records, bool $blocking = true): void
{
    $lines = '';
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $encoded = json_encode($record, JSON_UNESCAPED_SLASHES);
        if (is_string($encoded)) {
            $lines .= $encoded . "\n";
        }
    }
    if ($lines === '' || !_stattic_runtime_mkdir_soft(dirname($log['path']))) {
        return;
    }
    _stattic_lock_with(
        $log['lock'],
        $blocking ? STATTIC_LOCK_WAIT : STATTIC_LOCK_TRY,
        null,
        static function () use ($log, $lines): void {
            if (!_stattic_ndjson_log_admits($log)) {
                return;
            }
            file_put_contents($log['path'], $lines, FILE_APPEND);
        },
    );
}

function _stattic_ndjson_log_admits(array $log): bool
{
    if ($log['max_bytes'] <= 0) {
        return true;
    }
    // Appends within one request would otherwise all read the size the first one
    // saw, and rotate at the cap forever.
    clearstatcache(true, $log['path']);
    if (!is_file($log['path']) || (int) filesize($log['path']) < $log['max_bytes']) {
        return true;
    }
    if ($log['on_full'] !== STATTIC_NDJSON_LOG_ROTATE || !is_string($log['rotated'])) {
        return false;
    }
    $target = sprintf($log['rotated'], gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)));
    _stattic_runtime_assert_private_path($target);
    return rename($log['path'], $target) || !is_file($log['path']);
}
