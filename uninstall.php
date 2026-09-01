<?php
/**
 * Dodo Payments for WooCommerce - Uninstall.
 *
 * Removes plugin database tables and options when the plugin is deleted
 * via the WordPress admin.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

// If uninstall is not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$tables = array(
    'dodo_payments_product_mapping',
    'dodo_payments_payment_mapping',
    'dodo_payments_coupon_mapping',
    'dodo_payments_subscription_mappings',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
}

delete_option('dodo_payments_flush_rewrite_rules');
delete_option('dodo_payments_subscription_db_version');

// Gateway settings are stored under the WC settings option key.
delete_option('woocommerce_dodo_payments_settings');
