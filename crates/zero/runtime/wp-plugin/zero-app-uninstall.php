<?php
/**
 * Deleting the app plugin in wp-admin (or `wp plugin uninstall`) runs this without loading the plugin.
 *
 * Removed: the app's options (schema hash) and every capability WordPress derives from its post types
 * on every role (whatever granted them).
 * Kept: content (posts and their fields, `editor: false` tables, globals, media, private files), the
 * app's migration ledger rows, and its queued jobs with their Action Scheduler deliveries, so
 * installing the app again picks everything straight back up and runs the queued work. To drop queued
 * work for good, `wp zero jobs cancel <id>` before deleting the plugin; drop content with a database
 * tool. (The jobs tables are shared and record no owning app, so uninstall can't safely pick rows.)
 */
declare(strict_types=1);
defined('WP_UNINSTALL_PLUGIN') || exit;

$schema = require __DIR__ . '/zero/app/schema.php';
$app = (string) ($schema['name'] ?? '');
if ($app === '') {
    return;
}

delete_option('zero_' . $app . '_schema');

// Exactly the capability names WordPress derives from this app's `capability_type` ([<type>_item,
// <type>]): the meta caps on the singular and the primitive caps on the plural. Matched whole, so
// another plugin's `edit_acme_zero_posts` is never touched, whoever granted what.
$caps = [];
foreach ((array) ($schema['collections'] ?? []) as $collection) {
    if (($collection['editor'] ?? true) && isset($collection['wp']['postType'])) {
        $type = (string) $collection['wp']['postType'];
        foreach (['edit_%s', 'read_%s', 'delete_%s'] as $meta) {
            $caps[] = sprintf($meta, $type . '_item');
        }
        foreach (['edit_%s', 'edit_others_%s', 'edit_private_%s', 'edit_published_%s', 'publish_%s', 'read_private_%s',
            'delete_%s', 'delete_private_%s', 'delete_published_%s', 'delete_others_%s'] as $primitive) {
            $caps[] = sprintf($primitive, $type);
        }
    }
}
foreach (wp_roles()->role_objects as $role) {
    foreach ($caps as $cap) {
        if (isset($role->capabilities[$cap])) {
            $role->remove_cap($cap);
        }
    }
}
