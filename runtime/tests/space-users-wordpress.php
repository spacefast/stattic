<?php
declare(strict_types=1);

/** Run with wp eval requiring this file against an isolated database and installed runtime kernel. */
use Spacefast\Identity\Plugin as Identity;

function users_expect(mixed $actual, mixed $expected, string $behavior): void
{
    if ($actual !== $expected) throw new RuntimeException($behavior . ': ' . wp_json_encode($actual));
    echo "PASS $behavior\n";
}

ob_start();
$run = bin2hex(random_bytes(8));
$space = spacefast_content_space_id();
$uid = Identity::$accounts->emailLogin("first-$run@example.test");
Identity::$db->transaction(static fn () => Identity::$accounts->claim($uid, "second-$run@example.test", 'email_code'));
$login = Identity::$sessions->create($uid);
$_COOKIE['sfi_session'] = $login['secret'];
$subject = spacefast_space_users_subject($uid);
require_once dirname(__DIR__) . '/engine/runtime/zero.php';
require_once dirname(__DIR__) . '/engine/runtime/space-users.php';
$verify = static fn () => _stattic_space_users_verify_session(
    $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'], $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'], 'localhost:18280',
    $GLOBALS['SPACEFAST_PAGE_SERVING']['users'], $login['secret']
);
users_expect($verify()['userId'], $subject, 'bounded WordPress child verifies the live app session');
users_expect(preg_match('/\Ausr_[a-f0-9]{64}\z/', $subject), 1, 'app identity is opaque');
spacefast_space_users_join($uid);
users_expect(spacefast_space_users_subject($uid), $subject, 'repeat login preserves app identity');
$GLOBALS['SPACEFAST_CONTENT_PUBLIC_ORIGIN'] = 'https://custom.example.test';
users_expect(spacefast_space_users_auth()['userId'], $subject, 'custom hostname preserves account identity');
users_expect(array_column(spacefast_space_users_list(['search' => "second-$run"])['users'], 'id'), [$uid], 'secondary verified email finds the same account');
users_expect(count(spacefast_space_users_account(['id' => $uid])['emails']), 2, 'account projection includes both verified emails');

$authority = spacefast_content_principal_authority('https://platform.example.test/v1/auth', $run);
$principal = ['kind' => 'user', 'issuer' => 'https://platform.example.test/v1/auth', 'subject' => $run, 'principal_id' => $authority];
$native = spacefast_content_principal_ensure_user($principal);
users_expect(Identity::$accounts->provider($principal['issuer'], $run, null, 'Native account'), $native, 'first native login reuses canonical WP author');
users_expect(is_wp_error(spacefast_space_users_account(['id' => $native])), true, 'owner read does not enroll a platform account');
users_expect(get_user_meta($native, '_spacefast_app_user', true), '', 'directory read leaves app membership absent');
Identity::$api->providerLogin($native);
users_expect(spacefast_space_users_account(['id' => $native])['id'], $native, 'explicit native login enrolls the same WP author');
$linkSubject = 'linked-' . $run;
spacefast_space_users_link_native('https://platform.example.test/v1/auth', $linkSubject, 'Linked account', $login['session']);
$linkedNative = spacefast_content_principal_ensure_user([
    'kind' => 'user', 'issuer' => 'https://platform.example.test/v1/auth', 'subject' => $linkSubject,
    'principal_id' => spacefast_content_principal_authority('https://platform.example.test/v1/auth', $linkSubject),
]);
users_expect($linkedNative, $uid, 'explicit native link then native login preserves the app WP account');
users_expect(Identity::$accounts->provider('https://platform.example.test/v1/auth', $linkSubject, null, 'Linked account'), $uid, 'native provider and author mapping remain consistent');


// Observe a real competing connection waiting on the native tuple before committing the link.
$raceSubject = 'race-' . $run;
$raceIssuer = 'https://platform.example.test/v1/auth';
$process = null;
$pipes = [];
try {
    spacefast_space_users_identity_lock($raceIssuer, $raceSubject, static function () use ($raceIssuer, $raceSubject, $login, $space, &$process, &$pipes): void {
        $identity = ['kind' => 'user', 'issuer' => $raceIssuer, 'subject' => $raceSubject, 'principal_id' => spacefast_content_principal_authority($raceIssuer, $raceSubject)];
        $code = 'while (ob_get_level()) ob_end_clean(); fwrite(STDOUT, $GLOBALS["wpdb"]->get_var("SELECT CONNECTION_ID()") . "\\n"); echo json_encode(spacefast_content_principal_ensure_user(' . var_export($identity, true) . '));';
        $process = proc_open(['wp', '--path=' . ABSPATH, '--require=' . dirname(__DIR__) . '/engine/entrypoints/space-users-session.php', 'eval', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('race_child_failed');
        fwrite($pipes[0], wp_json_encode(['privateRoot' => $GLOBALS['SPACEFAST_CONTENT_PRIVATE_ROOT'], 'spaceId' => $space, 'host' => 'localhost:18280', 'scheme' => 'http', 'settings' => $GLOBALS['SPACEFAST_PAGE_SERVING']['users'], 'cookie' => '']));
        fclose($pipes[0]);
        stream_set_timeout($pipes[1], 5);
        $connection = (int) fgets($pipes[1]);
        $deadline = microtime(true) + 3;
        do {
            $waiter = Identity::$db->row('SELECT STATE FROM information_schema.PROCESSLIST WHERE ID=%d', $connection);
        } while (strtolower((string) ($waiter['STATE'] ?? '')) !== 'user lock' && microtime(true) < $deadline);
        users_expect(strtolower((string) ($waiter['STATE'] ?? '')), 'user lock', 'concurrent native author waits for the same identity tuple');
        spacefast_space_users_link_native($raceIssuer, $raceSubject, 'Race account', $login['session']);
    });
    users_expect(json_decode((string) stream_get_contents($pipes[1]), true), $uid, 'concurrent native creation converges on the linked app account');
} finally {
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
}

$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $space . '-other';
users_expect(spacefast_space_users_auth(), null, 'Space A session cannot authenticate Space B');
users_expect($verify(), null, 'child verifier preserves the Space boundary');
users_expect(spacefast_space_users_list(['search' => $run])['users'], [], 'Space B directory excludes Space A accounts');
$GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $space;
$GLOBALS['SPACEFAST_PAGE_SERVING']['users']['enabled'] = false;
users_expect(spacefast_space_users_auth(), null, 'disabled Users rejects an existing session');
$GLOBALS['SPACEFAST_PAGE_SERVING']['users']['enabled'] = true;

// A real app-cookie principal must not inherit a previously assigned WordPress content role.
update_user_meta($uid, '_spacefast_native_role_' . $space, 'administrator');
$GLOBALS['SPACEFAST_SPACE_USERS_WP_SESSION_USER'] = $uid;
wp_set_current_user($uid);
users_expect(current_user_can('spacefast_manage_content'), false, 'app credentials cannot acquire owner management authority');
unset($GLOBALS['SPACEFAST_SPACE_USERS_WP_SESSION_USER']);
delete_user_meta($uid, '_spacefast_native_role_' . $space);

// Exercise the exact WordPress Abilities HTTP route used by the control plane.
$GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'] = $principal;
$GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE'] = 'administrator';
wp_set_current_user($native);
$request = new WP_REST_Request('GET', '/wp-abilities/v1/abilities/zero/wp-users-account-list/run');
$request->set_param('input', ['search' => "second-$run"]);
$response = rest_do_request($request);
users_expect($response->get_status(), 200, 'canonical owner ability route executes');
users_expect(array_column($response->get_data()['users'], 'id'), [$uid], 'owner HTTP directory returns only matching app accounts');
unset($GLOBALS['SPACEFAST_CONTENT_PRINCIPAL'], $GLOBALS['SPACEFAST_CONTENT_WORDPRESS_ROLE']);

spacefast_space_users_revoke(['id' => $uid, 'sessionId' => $login['session']['id']]);
users_expect(spacefast_space_users_auth(), null, 'revocation rejects the next protected request');
users_expect($verify(), null, 'child verifier observes revocation immediately');
$login = Identity::$sessions->create($uid);
$_COOKIE['sfi_session'] = $login['secret'];
spacefast_space_users_suspend(['id' => $uid, 'suspended' => true]);
users_expect(spacefast_space_users_auth(), null, 'suspension rejects existing app sessions');
spacefast_space_users_suspend(['id' => $uid, 'suspended' => false]);
users_expect(spacefast_space_users_auth(), null, 'reactivation never revives revoked sessions');
users_expect(spacefast_space_users_subject($uid), $subject, 'suspension and reactivation preserve data ownership');
Identity::$db->run('UPDATE ' . Identity::$db->prefix . 'accounts SET status=%s WHERE wp_user_id=%d', 'deletion_requested', $uid);
users_expect(spacefast_space_users_suspend(['id' => $uid, 'suspended' => false])->get_error_code(), 'space_users_deletion_pending', 'owner cannot reactivate pending deletion');
users_expect(spacefast_space_users_complete_deletion(['id' => $uid]), ['deleted' => true], 'owner completes requested erasure');
users_expect(spacefast_space_users_complete_deletion(['id' => $uid]), ['deleted' => true], 'erasure completion is idempotent');
users_expect(spacefast_space_users_account(['id' => $uid])['status'], 'deleted', 'erasure preserves anonymized author with canonical state');
users_expect(spacefast_space_users_list(['search' => "second-$run"])['users'], [], 'erased account leaves searchable directory');

ob_end_flush();
