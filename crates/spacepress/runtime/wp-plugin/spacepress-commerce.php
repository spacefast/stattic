<?php
/** Commerce installation policy. Loaded before Woo, including its first activation. */
declare(strict_types=1);
defined('ABSPATH') || exit;
global $wpdb;
if (strlen($wpdb->prefix) > 20) {
    throw new RuntimeException('WooCommerce requires a WordPress table prefix of at most 20 characters');
}
add_filter('woocommerce_create_pages', '__return_empty_array', PHP_INT_MAX);
add_filter('woocommerce_enable_hpos_by_default_for_new_shops', '__return_false', PHP_INT_MAX);
// Never switch an existing store's authoritative order storage on its behalf. A managed build stops
// here, before a host exposes it. As a must-use plugin this file must not throw for it: that would
// take wp-admin down with the shop, so the runtime reports it there (Commerce\Module::onInit).
if (!wp_installing() && class_exists(\SpacePress\Loader\Loader::class, false) && \SpacePress\Loader\Loader::loaded() !== null) {
    // Both options in one statement, from the database rather than the option cache.
    $values = $wpdb->get_col("SELECT option_value FROM {$wpdb->options} WHERE option_name IN ('woocommerce_custom_orders_table_enabled', 'woocommerce_custom_orders_table_data_sync_enabled')");
    if ($wpdb->last_error !== '') {
        throw new RuntimeException('Unable to verify WooCommerce order storage');
    }
    if (in_array('yes', $values, true)) {
        throw new RuntimeException('SpacePress commerce requires legacy order storage with HPOS synchronization disabled. Change storage in WooCommerce after completing synchronization.');
    }
}
