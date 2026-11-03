<?php
declare(strict_types=1);

// Release together with the control-plane hold, through code review.
const STATTIC_EVIDENCE_RETENTION_HOLD = true;

function _stattic_evidence_deletion_held(string $owner, int $blockedCount): bool
{
    if (!STATTIC_EVIDENCE_RETENTION_HOLD) {
        return false;
    }
    if ($blockedCount > 0) {
        error_log((string) json_encode([
            'event' => 'evidence.deletion_held',
            'owner' => $owner,
            'blockedCount' => $blockedCount,
        ]));
    }
    return true;
}

function _stattic_assert_evidence_deletion_allowed(string $owner): void
{
    if (_stattic_evidence_deletion_held($owner, 1)) {
        _stattic_problem_response(503, 'evidence_retention_hold', 'Deletion is paused while evidence retention is active.');
    }
}
