<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Mfa
{
    public function __construct(private DB $db, private Security $security) {}
    public function enabled(int $uid): bool
    {
        return (bool) $this->db->row(
            "SELECT wp_user_id FROM {$this->db->prefix}mfa WHERE wp_user_id=%d AND enabled=1",
            $uid,
        );
    }
    public function setup(int $uid, string $label): array
    {
        $otp = \Zero\Identity\Vendor\OTPHP\TOTP::generate();
        $otp->setLabel($label);
        $otp->setIssuer(get_bloginfo("name") ?: "Identity");
        $this->db->transaction(function () use ($uid, $otp) {
            $this->db->lock($uid);
            if ($this->enabled($uid)) {
                throw new Failure(
                    "mfa_enabled",
                    "Remove the existing authenticator first.",
                    409,
                );
            }
            $this->db->run(
                "INSERT INTO {$this->db->prefix}mfa (wp_user_id,secret) VALUES (%d,%s) ON DUPLICATE KEY UPDATE secret=VALUES(secret),last_step=0",
                $uid,
                Security::seal($otp->getSecret()),
            );
        });
        return [
            "secret" => $otp->getSecret(),
            "uri" => $otp->getProvisioningUri(),
        ];
    }
    public function verify(
        int $uid,
        string $code,
        bool $enrolling = false,
    ): bool {
        return $this->db->transaction(function () use (
            $uid,
            $code,
            $enrolling,
        ) {
            $this->db->lock($uid);
            $row = $this->db->row(
                "SELECT * FROM {$this->db->prefix}mfa WHERE wp_user_id=%d FOR UPDATE",
                $uid,
            );
            if (!$row || (!$enrolling && !(int) $row["enabled"])) {
                return false;
            }
            if (
                !$enrolling &&
                $this->db->run(
                    "UPDATE {$this->db->prefix}recovery_codes SET used_at=%d WHERE id=%s AND wp_user_id=%d AND used_at IS NULL",
                    time(),
                    Security::hash($code),
                    $uid,
                ) === 1
            ) {
                $this->security->event($uid, "mfa.recovery_used");
                return true;
            }
            $otp = \Zero\Identity\Vendor\OTPHP\TOTP::createFromSecret(
                Security::open($row["secret"]),
            );
            $step = intdiv(time(), 30);
            if (
                (int) $row["last_step"] >= $step ||
                !$otp->verify($code, null, 0)
            ) {
                return false;
            }
            $this->db->run(
                "UPDATE {$this->db->prefix}mfa SET last_step=%d,enabled=1 WHERE wp_user_id=%d",
                $step,
                $uid,
            );
            return true;
        });
    }
    public function recoveryCodes(int $uid): array
    {
        return $this->db->transaction(function () use ($uid) {
            $this->db->lock($uid);
            $this->db->run(
                "DELETE FROM {$this->db->prefix}recovery_codes WHERE wp_user_id=%d",
                $uid,
            );
            $codes = [];
            for ($i = 0; $i < 10; $i++) {
                $code = Security::token(12);
                $codes[] = $code;
                $this->db->insert("recovery_codes", [
                    "id" => Security::hash($code),
                    "wp_user_id" => $uid,
                ]);
            }
            $this->security->event($uid, "mfa.recovery_regenerated");
            return $codes;
        });
    }
}
