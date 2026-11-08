<?php
declare(strict_types=1);

// The bulk storage lane's disk report: the bytes and inodes each Space holds.
// Nothing here deletes. A Space's bytes leave the box only through an explicit
// delete the control plane asks for.
require_once __DIR__ . '/../shared/context.php';
require_once __DIR__ . '/../shared/storage.php';

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
