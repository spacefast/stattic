<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

// Only host-owned code calls this writer. The runtime journal carries receipts
// through the existing authenticated drain and acknowledgement protocol.
function _stattic_record_retail_usage(string $privateRoot, string $spaceId, string $meter, int $quantity): void
{
    if ($privateRoot === '' || $spaceId === '' || $quantity < 0) {
        return;
    }
    $now = time();
    _stattic_runtime_record_management_event($privateRoot, [], [
        'event' => 'retail.usage',
        'space_id' => $spaceId,
        'occurrence' => bin2hex(random_bytes(16)),
        'meter' => $meter,
        'quantity' => (string) $quantity,
        'bucket_start' => gmdate('c', $now),
        'bucket_end' => gmdate('c', $now + 1),
    ]);
}

function _stattic_retail_cpu_microseconds(array $usage): int
{
    return (int) ($usage['ru_utime.tv_sec'] ?? 0) * 1000000
        + (int) ($usage['ru_utime.tv_usec'] ?? 0)
        + (int) ($usage['ru_stime.tv_sec'] ?? 0) * 1000000
        + (int) ($usage['ru_stime.tv_usec'] ?? 0);
}
