<?php

declare(strict_types=1);
namespace TypedPress\Identity\Legacy;

use TypedPress\Identity\{DB, Sessions};

/**
 * Sessions created before the rename to TypedPress are stored under the digest that build used
 * (`zero-identity-session-v1`). The first request that presents such a session's secret re-keys it
 * to TypedPress's digest, so a signed-in visitor stays signed in. A session nobody presents again simply
 * expires as it would have. Remove once every session created by a 0.3 runtime has expired (at most
 * one session lifetime after the last 0.3 site upgraded).
 */
final class ZeroSession
{
    public static function digest(string $secret): string
    {
        return hash('sha256', "zero-identity-session-v1\0" . $secret);
    }

    public static function find(DB $db, string $secret): ?array
    {
        if ($secret === '' || strlen($secret) > 512) {
            return null;
        }
        $old = self::digest($secret);
        $new = Sessions::digest($secret);
        // One statement re-keys it and rechecks revocation, expiry and account status at the write.
        $db->run("UPDATE {$db->prefix}sessions s JOIN {$db->prefix}accounts a ON a.wp_user_id=s.wp_user_id SET s.secret_hash=%s WHERE s.secret_hash=%s AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'", $new, $old, time());
        $row = $db->row("SELECT s.* FROM {$db->prefix}sessions s JOIN {$db->prefix}accounts a ON a.wp_user_id=s.wp_user_id WHERE s.secret_hash=%s AND s.revoked_at IS NULL AND s.expires_at>%d AND a.status='active'", $new, time());

        return $row && hash_equals((string) $row['secret_hash'], $new) ? $row : null;
    }
}
