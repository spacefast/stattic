<?php

declare(strict_types=1);
namespace Zero\Identity\Legacy;

use Zero\Identity\{DB, Security, Sessions};

/** Remove after every imported session's original expires_at has elapsed. */
final class SessionUpgrade
{
    public static function find(DB $db, string $secret): ?array
    {
        if ($secret === '' || strlen($secret) > 512 || apply_filters('zero_identity_session_cookie_aliases', []) === []) { return null; }
        $legacy = $db->prefix . 'legacy_sessions';
        if (!$db->row('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $legacy)) { return null; }
        $hash = Security::hash($secret);
        $row = $db->row("SELECT s.* FROM {$db->prefix}sessions s JOIN `$legacy` l ON l.session_id=s.id AND l.pending=1 JOIN {$db->prefix}accounts a ON a.wp_user_id=s.wp_user_id WHERE s.secret_hash=%s AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'", $hash, time());
        if (!$row || !hash_equals((string) $row['secret_hash'], $hash)) { return null; }
        $digest = Sessions::digest($secret);
        // One statement changes both the imported hash and its eligibility. Revocation/expiry
        // are rechecked at the write, so concurrent logout cannot revive the session.
        $db->run("UPDATE {$db->prefix}sessions s JOIN `$legacy` l ON l.session_id=s.id JOIN {$db->prefix}accounts a ON a.wp_user_id=s.wp_user_id SET s.secret_hash=%s,l.pending=0 WHERE s.id=%s AND s.secret_hash=%s AND l.pending=1 AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'", $digest, $row['id'], $hash, time());
        $upgraded = $db->row("SELECT s.* FROM {$db->prefix}sessions s JOIN {$db->prefix}accounts a ON a.wp_user_id=s.wp_user_id WHERE s.secret_hash=%s AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'", $digest, time());
        return $upgraded && hash_equals((string) $upgraded['secret_hash'], $digest) ? $upgraded : null;
    }
}
