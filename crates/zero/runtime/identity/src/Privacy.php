<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Privacy
{
    public function __construct(private DB $db, private Security $security) {}
    public function export(int $uid): array
    {
        return [
            "account" => Plugin::$accounts->profile($uid),
            "sessions" => $this->db->rows(
                "SELECT id,label,created_at,expires_at,revoked_at FROM {$this->db->prefix}sessions WHERE wp_user_id=%d",
                $uid,
            ),
            "organizations" => $this->db->rows(
                "SELECT o.id,o.name,m.role FROM {$this->db->prefix}members m JOIN {$this->db->prefix}organizations o ON o.id=m.organization_id WHERE m.wp_user_id=%d",
                $uid,
            ),
            "passkeys" => $this->db->rows(
                "SELECT name,created_at FROM {$this->db->prefix}passkeys WHERE wp_user_id=%d",
                $uid,
            ),
            "events" => $this->db->rows(
                "SELECT type,detail,created_at FROM {$this->db->prefix}events WHERE wp_user_id=%d",
                $uid,
            ),
        ];
    }
    public function requireTransferredOwnership(int $uid): void
    {
        $memberships = $this->db->rows(
            "SELECT organization_id FROM {$this->db->prefix}members WHERE wp_user_id=%d AND role='owner' FOR UPDATE",
            $uid,
        );
        foreach ($memberships as $m) {
            $this->db->row(
                "SELECT id FROM {$this->db->prefix}organizations WHERE id=%s FOR UPDATE",
                $m["organization_id"],
            );
            $owners = $this->db->rows(
                "SELECT wp_user_id FROM {$this->db->prefix}members WHERE organization_id=%s AND role='owner' FOR UPDATE",
                $m["organization_id"],
            );
            if (count($owners) < 2) {
                throw new Failure(
                    "last_owner",
                    "Transfer organization ownership before erasure.",
                    409,
                );
            }
        }
    }
    public function erase(int $uid): array
    {
        if (user_can($uid, "manage_options")) {
            throw new Failure(
                "administrator_deletion",
                "Transfer administration before erasure.",
                409,
            );
        }
        return $this->db->transaction(function () use ($uid) {
            $account = $this->db->row(
                "SELECT * FROM {$this->db->prefix}accounts WHERE wp_user_id=%d FOR UPDATE",
                $uid,
            );
            if (!$account || $account["status"] === "deleted") {
                return ["erased" => true];
            }
            if ($account["status"] !== "deletion_requested") {
                throw new Failure(
                    "deletion_not_requested",
                    "The account owner must request deletion first.",
                    409,
                );
            }
            $this->requireTransferredOwnership($uid);
            foreach (
                [
                    "emails",
                    "providers",
                    "sessions",
                    "challenges",
                    "codes",
                    "tokens",
                    "mfa",
                    "recovery_codes",
                    "passkeys",
                    "members",
                ]
                as $table
            ) {
                $this->db->run(
                    "DELETE FROM {$this->db->prefix}{$table} WHERE wp_user_id=%d",
                    $uid,
                );
            }
            $this->db->run(
                "DELETE d FROM {$this->db->prefix}deliveries d JOIN {$this->db->prefix}events e ON e.id=d.event_id WHERE e.wp_user_id=%d",
                $uid,
            );
            $this->db->run(
                "DELETE FROM {$this->db->prefix}events WHERE wp_user_id=%d",
                $uid,
            );
            $this->db->run(
                "UPDATE {$this->db->prefix}accounts SET primary_email_id=NULL,status='deleted' WHERE wp_user_id=%d",
                $uid,
            );
            $this->db->touch($uid);
            $this->db->run(
                "UPDATE {$this->db->wp->users} SET user_login=%s,user_nicename=%s,user_email='',user_url='',display_name='Deleted account',user_activation_key='' WHERE ID=%d",
                "deleted_" . $uid,
                "deleted-" . $uid,
                $uid,
            );
            wp_set_password(Security::token(48), $uid);
            $user = new \WP_User($uid);
            $user->set_role("");
            foreach (
                [
                    "first_name",
                    "last_name",
                    "nickname",
                    "description",
                    "session_tokens",
                ]
                as $key
            ) {
                delete_user_meta($uid, $key);
            }
            clean_user_cache($uid);
            $this->security->event(0, "account.erased");
            return ["erased" => true];
        });
    }
}
