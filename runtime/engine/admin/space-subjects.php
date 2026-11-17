<?php
declare(strict_types=1);

// The one-time move of a Space's box from account ids to the Space's own
// subjects. The control plane drives it through two engine jobs on the bulk
// lane: `space_subjects_inventory` lists the account ids this box keys records
// on, and `space_subjects_rewrite` swaps a batch of them for the subjects the
// control plane minted. Both are idempotent, rewrite keys in place, and delete
// nothing. The Space comes from the signed job scope, never the payload.
//
// What a box keys on a Spacefast account (issuer + account id):
// - a WordPress principal: user meta `_spacefast_principal_subject` and
//   `_spacefast_principal_id`, and the `spacefast_principal_<hash>` link option
//   (wordpress/content-principals.php). A Zero v2 Space keeps these in its own
//   `sfz_<hash>_` tables;
// - a Space Users link to a Spacefast sign-in: the identity plugin's
//   `providers` row (issuer, subject, identity_key);
// - storage uploads: `uploaderId` = `account:<id>` (runtime/storage.php).
// Session cookies carry the principal too; the control plane signs the Space's
// sessions out when it marks the Space moved, so none is rewritten here.

require_once __DIR__ . '/../shared/record-store.php';
require_once __DIR__ . '/../shared/lock.php';

const STATTIC_SPACE_SUBJECTS_PREFIX = 'ps1_';
const STATTIC_SPACE_SUBJECTS_ACCOUNT = '/\A[A-Za-z0-9_.-]{1,128}\z/';
const STATTIC_SPACE_SUBJECTS_SUBJECT = '/\Aps1_[A-Za-z0-9_-]{16,60}\z/';

/** The signed payload: the account issuer, and for a rewrite the batch. */
function _stattic_space_subjects_payload(array $job, bool $rewrite): array
{
    $spaceId = is_string($job['space_id'] ?? null) ? $job['space_id'] : '';
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
    $issuer = is_string($payload['issuer'] ?? null) ? $payload['issuer'] : '';
    if ($spaceId === '' || !_stattic_id_valid($spaceId) || preg_match('~\Ahttps?://[^\s/]+(/[^\s]*)?\z~', $issuer) !== 1) {
        throw new StatticJobFatal('space_subjects_scope_invalid');
    }
    $map = [];
    if ($rewrite) {
        $subjects = $payload['subjects'] ?? null;
        if (!is_array($subjects) || $subjects === [] || array_is_list($subjects)) {
            throw new StatticJobFatal('space_subjects_batch_invalid');
        }
        foreach ($subjects as $account => $subject) {
            if (!is_string($account) || preg_match(STATTIC_SPACE_SUBJECTS_ACCOUNT, $account) !== 1
                || str_starts_with($account, STATTIC_SPACE_SUBJECTS_PREFIX)
                || !is_string($subject) || preg_match(STATTIC_SPACE_SUBJECTS_SUBJECT, $subject) !== 1) {
                throw new StatticJobFatal('space_subjects_batch_invalid');
            }
            $map[$account] = $subject;
        }
    }
    return ['space_id' => $spaceId, 'issuer' => $issuer, 'map' => $map];
}

/** An account id this move would rewrite; a subject or anything else is left alone. */
function _stattic_space_subjects_is_account(string $value): bool
{
    return preg_match(STATTIC_SPACE_SUBJECTS_ACCOUNT, $value) === 1
        && !str_starts_with($value, STATTIC_SPACE_SUBJECTS_PREFIX);
}

/**
 * WordPress for this Space, booted the way a component stage boots it. A box
 * without WordPress has no principals to move.
 */
function _stattic_space_subjects_wordpress(string $privateRoot, string $spaceId): bool
{
    if (!function_exists('get_option')) {
        $GLOBALS['SPACEFAST_CONTENT_SPACE_ID'] = $spaceId;
        $wpLoad = dirname(dirname($privateRoot)) . '/wp-load.php';
        if (!is_file($wpLoad)) {
            return false;
        }
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        require_once $wpLoad;
    }
    return function_exists('get_option') && isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']);
}

/**
 * The table sets that may hold this Space's principals: the site's own, and the
 * Space's Zero v2 tables when they exist.
 *
 * @return list<array{prefix:string, scoped:bool}>
 */
function _stattic_space_subjects_table_sets(object $wpdb, string $spaceId): array
{
    $sets = [['prefix' => (string) $wpdb->base_prefix, 'scoped' => false]];
    $scope = 'sfz_' . substr(hash('sha256', $spaceId), 0, 24) . '_';
    if (_stattic_space_subjects_table_exists($wpdb, $scope . 'usermeta')) {
        $sets[] = ['prefix' => $scope, 'scoped' => true];
    }
    return $sets;
}

function _stattic_space_subjects_table_exists(object $wpdb, string $table): bool
{
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
}

/**
 * The Space's WordPress principals for this issuer, as
 * [user id, subject] pairs. Membership is the Space's own `_spacefast_space_id`
 * (or, for Space Users, `_spacefast_app_user`) meta, so another Space's users
 * on a shared site are never touched.
 *
 * @return list<array{user_id:int, subject:string, shared:bool}>
 */
function _stattic_space_subjects_principals(object $wpdb, string $prefix, string $spaceId, string $issuer): array
{
    $meta = $prefix . 'usermeta';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.user_id, s.meta_value AS subject,"
        . " EXISTS(SELECT 1 FROM {$meta} o WHERE o.user_id = s.user_id AND o.meta_key IN ('_spacefast_space_id', '_spacefast_app_user') AND o.meta_value <> %s) AS shared"
        . " FROM {$meta} s"
        . " JOIN {$meta} i ON i.user_id = s.user_id AND i.meta_key = '_spacefast_principal_issuer' AND i.meta_value = %s"
        . " JOIN {$meta} k ON k.user_id = s.user_id AND k.meta_key = '_spacefast_principal_kind' AND k.meta_value = 'user'"
        . " WHERE s.meta_key = '_spacefast_principal_subject'"
        . " AND EXISTS(SELECT 1 FROM {$meta} m WHERE m.user_id = s.user_id AND m.meta_key IN ('_spacefast_space_id', '_spacefast_app_user') AND m.meta_value = %s)"
        . " ORDER BY s.user_id",
        $spaceId, $issuer, $spaceId
    ), ARRAY_A);
    return array_map(static fn (array $row): array => [
        'user_id' => (int) $row['user_id'],
        'subject' => (string) $row['subject'],
        'shared' => (int) $row['shared'] === 1,
    ], is_array($rows) ? $rows : []);
}

/**
 * The Space Users links to a Spacefast sign-in, fenced to accounts that belong
 * to this Space. The identity plugin names its tables `sfi_` on the site and
 * `zero_identity_` in a Zero v2 Space's own tables.
 *
 * @return list<array{table:string, id:int, user_id:int, subject:string}>
 */
function _stattic_space_subjects_providers(object $wpdb, string $prefix, bool $scoped, string $spaceId, string $issuer): array
{
    $table = $prefix . ($scoped ? 'zero_identity_' : 'sfi_') . 'providers';
    if (!_stattic_space_subjects_table_exists($wpdb, $table)) {
        return [];
    }
    $meta = $prefix . 'usermeta';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT p.id, p.wp_user_id, p.subject FROM {$table} p"
        . " WHERE p.issuer = %s"
        . ($scoped ? '' : " AND EXISTS(SELECT 1 FROM {$meta} m WHERE m.user_id = p.wp_user_id AND m.meta_key IN ('_spacefast_space_id', '_spacefast_app_user') AND m.meta_value = %s)")
        . " ORDER BY p.id",
        ...($scoped ? [$issuer] : [$issuer, $spaceId])
    ), ARRAY_A);
    return array_map(static fn (array $row): array => [
        'table' => $table,
        'id' => (int) $row['id'],
        'user_id' => (int) $row['wp_user_id'],
        'subject' => (string) $row['subject'],
    ], is_array($rows) ? $rows : []);
}

function _stattic_space_subjects_uploads_store(string $privateRoot, string $spaceId): array
{
    return _stattic_record_store(_stattic_space_root($privateRoot, $spaceId) . '/uploads/objects');
}

/** @return list<string> account ids that uploaded to this Space */
function _stattic_space_subjects_uploaders(string $privateRoot, string $spaceId): array
{
    $store = _stattic_space_subjects_uploads_store($privateRoot, $spaceId);
    $accounts = [];
    foreach (_stattic_record_store_ids($store) as $id) {
        $uploader = _stattic_record_store_get($store, $id)['uploaderId'] ?? null;
        if (is_string($uploader) && str_starts_with($uploader, 'account:')
            && _stattic_space_subjects_is_account(substr($uploader, strlen('account:')))) {
            $accounts[] = substr($uploader, strlen('account:'));
        }
    }
    return $accounts;
}

/** The account ids this box still keys records on for the Space. */
function _stattic_space_subjects_inventory(string $privateRoot, array $job): array
{
    ['space_id' => $spaceId, 'issuer' => $issuer] = _stattic_space_subjects_payload($job, false);
    $accounts = _stattic_space_subjects_uploaders($privateRoot, $spaceId);
    $wordpress = _stattic_space_subjects_wordpress($privateRoot, $spaceId);
    if ($wordpress) {
        $wpdb = $GLOBALS['wpdb'];
        foreach (_stattic_space_subjects_table_sets($wpdb, $spaceId) as $set) {
            foreach (_stattic_space_subjects_principals($wpdb, $set['prefix'], $spaceId, $issuer) as $principal) {
                if (_stattic_space_subjects_is_account($principal['subject'])) $accounts[] = $principal['subject'];
            }
            foreach (_stattic_space_subjects_providers($wpdb, $set['prefix'], $set['scoped'], $spaceId, $issuer) as $provider) {
                if (_stattic_space_subjects_is_account($provider['subject'])) $accounts[] = $provider['subject'];
            }
        }
    }
    $accounts = array_values(array_unique($accounts));
    sort($accounts, SORT_STRING);
    return ['done' => true, 'result' => ['accounts' => $accounts, 'wordpress' => $wordpress]];
}

/**
 * Rewrite one batch. Every store is checked first and rewritten only when the
 * new key is free: a subject another user already holds is a conflict this
 * job reports and leaves alone, never a merge.
 */
function _stattic_space_subjects_rewrite(string $privateRoot, array $job): array
{
    ['space_id' => $spaceId, 'issuer' => $issuer, 'map' => $map] = _stattic_space_subjects_payload($job, true);
    $report = ['principals' => 0, 'providers' => 0, 'uploads' => 0, 'conflicts' => [], 'shared' => []];

    if (_stattic_space_subjects_wordpress($privateRoot, $spaceId)) {
        $wpdb = $GLOBALS['wpdb'];
        foreach (_stattic_space_subjects_table_sets($wpdb, $spaceId) as $set) {
            _stattic_space_subjects_rewrite_principals($wpdb, $set, $spaceId, $issuer, $map, $report);
            _stattic_space_subjects_rewrite_providers($wpdb, $set, $spaceId, $issuer, $map, $report);
            if ($set['scoped'] && function_exists('wp_cache_delete')) {
                // A Zero v2 Space caches under a generation kept in its scope
                // group; dropping it makes the next request read fresh rows.
                wp_cache_delete('generation', $set['prefix']);
            }
        }
    }
    _stattic_space_subjects_rewrite_uploads($privateRoot, $spaceId, $map, $report);
    return ['done' => true, 'result' => $report];
}

/** The pointer content-principals.php follows from a principal to its user. */
function _stattic_space_subjects_authority(string $issuer, string $subject): string
{
    return 'external:' . hash('sha256', $issuer . "\0" . $subject);
}

function _stattic_space_subjects_rewrite_principals(object $wpdb, array $set, string $spaceId, string $issuer, array $map, array &$report): void
{
    $prefix = $set['prefix'];
    $meta = $prefix . 'usermeta';
    $options = $prefix . 'options';
    foreach (_stattic_space_subjects_principals($wpdb, $prefix, $spaceId, $issuer) as $principal) {
        $subject = $map[$principal['subject']] ?? null;
        if ($subject === null) continue;
        if ($principal['shared']) {
            // One WordPress user in two Spaces would need two subjects.
            $report['shared'][] = $principal['user_id'];
            continue;
        }
        $principalId = _stattic_space_subjects_authority($issuer, $subject);
        $linkOption = 'spacefast_principal_' . substr(hash('sha256', $principalId), 0, 40);
        $taken = $wpdb->get_var($wpdb->prepare(
            "SELECT s.user_id FROM {$meta} s JOIN {$meta} i ON i.user_id = s.user_id AND i.meta_key = '_spacefast_principal_issuer' AND i.meta_value = %s"
            . " WHERE s.meta_key = '_spacefast_principal_subject' AND s.meta_value = %s AND s.user_id <> %d LIMIT 1",
            $issuer, $subject, $principal['user_id']
        ));
        if ($taken !== null) {
            $report['conflicts'][] = ['store' => 'principal', 'user_id' => $principal['user_id']];
            continue;
        }
        // The pointer first: until the meta moves, the old pointer still finds
        // the same user, so no request in between mints a second one.
        $link = serialize([
            'version' => 1, 'principal_id' => $principalId, 'kind' => 'user',
            'issuer' => $issuer, 'subject' => $subject, 'user_id' => $principal['user_id'],
        ]);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')"
            . ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)',
            $linkOption, $link
        ));
        foreach (['_spacefast_principal_subject' => $subject, '_spacefast_principal_id' => $principalId] as $key => $value) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$meta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s",
                $value, $principal['user_id'], $key
            ));
        }
        if (!$set['scoped'] && function_exists('clean_user_cache')) {
            clean_user_cache($principal['user_id']);
            wp_cache_delete($linkOption, 'options');
            wp_cache_delete('notoptions', 'options');
        }
        $report['principals']++;
    }
}

function _stattic_space_subjects_rewrite_providers(object $wpdb, array $set, string $spaceId, string $issuer, array $map, array &$report): void
{
    foreach (_stattic_space_subjects_providers($wpdb, $set['prefix'], $set['scoped'], $spaceId, $issuer) as $provider) {
        $subject = $map[$provider['subject']] ?? null;
        if ($subject === null) continue;
        $key = hash('sha256', $issuer . "\0" . $subject);
        $taken = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$provider['table']} WHERE identity_key = %s AND id <> %d LIMIT 1",
            $key, $provider['id']
        ));
        if ($taken !== null) {
            $report['conflicts'][] = ['store' => 'provider', 'user_id' => $provider['user_id']];
            continue;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$provider['table']} SET subject = %s, identity_key = %s WHERE id = %d",
            $subject, $key, $provider['id']
        ));
        $report['providers']++;
    }
}

function _stattic_space_subjects_rewrite_uploads(string $privateRoot, string $spaceId, array $map, array &$report): void
{
    $store = _stattic_space_subjects_uploads_store($privateRoot, $spaceId);
    // Under the Space's write lock, the one every upload commit holds.
    _stattic_space_write_lock_with($privateRoot, $spaceId, STATTIC_LOCK_WAIT, static function (): never {
        throw new StatticJobRetry('space_subjects_storage_busy');
    }, static function () use ($store, $map, &$report): void {
        foreach (_stattic_record_store_ids($store) as $id) {
            $record = _stattic_record_store_get($store, $id);
            $uploader = is_array($record) && is_string($record['uploaderId'] ?? null) ? $record['uploaderId'] : '';
            if (!str_starts_with($uploader, 'account:')) continue;
            $subject = $map[substr($uploader, strlen('account:'))] ?? null;
            if ($subject === null) continue;
            $record['uploaderId'] = 'account:' . $subject;
            _stattic_record_store_put($store, $id, $record);
            $report['uploads']++;
        }
    });
}
