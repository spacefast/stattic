<?php
/**
 * Deleting the Zero Runtime plugin runs this without loading it.
 *
 * Removed: the runtime's options (system-tables version, private-files signing secret and probe
 * verdict) and its recurring maintenance action. Kept: the shared system tables (jobs, outbox,
 * receipts, private file records, revisions, the migration ledger) and private files on disk; they
 * belong to the apps' content. A reinstalled runtime re-checks its tables on the first request.
 */
declare(strict_types=1);
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['zero_runtime_tables', 'zero_runtime_secret', 'zero_runtime_private_ok', 'zero_runtime_private_checked'] as $option) {
    delete_option($option);
}
// Through Action Scheduler's API when it is active, else directly in its tables (kept on deactivation).
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('zero_jobs_maintain', [], 'zero');
    as_unschedule_all_actions('zero.prune-guests', [], 'zero');
} else {
    global $wpdb;
    $actions = $wpdb->prefix . 'actionscheduler_actions';
    $groups = $wpdb->prefix . 'actionscheduler_groups';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($actions))) === $actions) {
        $wpdb->query($wpdb->prepare("UPDATE `$actions` a JOIN `$groups` g ON g.group_id = a.group_id SET a.status = 'canceled' WHERE a.status = 'pending' AND g.slug = %s AND a.hook IN (%s, %s)", 'zero', 'zero_jobs_maintain', 'zero.prune-guests'));
    }
}
