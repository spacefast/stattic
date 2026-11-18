<?php
/**
 * Deleting the TypedPress Runtime plugin runs this without loading it.
 *
 * Removed: the runtime's options (system-tables version, prepared job groups, private-files signing secret and probe
 * verdict) and its recurring maintenance action. Kept: the shared system tables (jobs, the outbox
 * that earlier runtimes wrote, receipts, private file records, revisions, the migration ledger) and private files on disk; they
 * belong to the apps' content. A reinstalled runtime re-checks its tables on the first request.
 */
declare(strict_types=1);
defined('WP_UNINSTALL_PLUGIN') || exit;

foreach (['tp_runtime_tables', 'tp_jobs_prepared', 'tp_runtime_secret', 'tp_runtime_private_ok', 'tp_runtime_private_checked'] as $option) {
    delete_option($option);
}
// Through Action Scheduler's API when it is active, else directly in its tables (kept on deactivation).
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('tp_jobs_maintain', [], 'typedpress');
    as_unschedule_all_actions('typedpress.prune-guests', [], 'typedpress');
} else {
    global $wpdb;
    $actions = $wpdb->prefix . 'actionscheduler_actions';
    $groups = $wpdb->prefix . 'actionscheduler_groups';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($actions))) === $actions) {
        $wpdb->query($wpdb->prepare("UPDATE `$actions` a JOIN `$groups` g ON g.group_id = a.group_id SET a.status = 'canceled' WHERE a.status = 'pending' AND g.slug = %s AND a.hook IN (%s, %s)", 'typedpress', 'tp_jobs_maintain', 'typedpress.prune-guests'));
    }
}
