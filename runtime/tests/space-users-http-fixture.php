<?php
declare(strict_types=1);

use Spacefast\Identity\Plugin as Identity;

/** WP-CLI fixture on the test-owned site; only its unique per-run accounts are touched. */
function spacefast_users_http_fixture(string $marker, bool $cleanup): array
{
    if (!preg_match('/\Ausers-contract-[a-zA-Z0-9-]+\z/', $marker)) throw new InvalidArgumentException('Invalid fixture marker.');
    if ($cleanup) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach (get_users(['meta_key' => '_spacefast_users_contract', 'meta_value' => $marker]) as $user) {
            Identity::$db->run("UPDATE " . Identity::$db->prefix . "accounts SET status='deletion_requested' WHERE wp_user_id=%d AND status<>'deleted'", (int) $user->ID);
            (new \Spacefast\Identity\Privacy(Identity::$db, Identity::$security))->erase((int) $user->ID);
            delete_option(spacefast_space_users_subject_key((int) $user->ID));
            wp_delete_user((int) $user->ID);
            Identity::$db->run('DELETE FROM ' . Identity::$db->prefix . 'accounts WHERE wp_user_id=%d', (int) $user->ID);
        }
        $principal = spacefast_content_principal_authority('https://runtime-contract.spacefast.test/auth', $marker);
        foreach (get_users(['meta_key' => '_spacefast_principal_id', 'meta_value' => $principal]) as $user) wp_delete_user((int) $user->ID);
        delete_option('spacefast_principal_' . substr(hash('sha256', $principal), 0, 40));
        return [];
    }
    $accounts = [];
    foreach (['alice', 'bob', 'carol', 'dave'] as $name) {
        $id = Identity::$accounts->emailLogin($name . '-' . $marker . '@example.test');
        update_user_meta($id, '_spacefast_users_contract', $marker);
        $session = Identity::$sessions->create($id);
        $accounts[] = [
            'id' => $id, 'subject' => spacefast_space_users_subject($id),
            'cookie' => 'sfi_session=' . $session['secret'], 'csrf' => $session['session']['csrf'],
        ];
    }
    return $accounts;
}
