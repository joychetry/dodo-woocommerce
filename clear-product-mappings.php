<?php
/**
 * Clear Dodo Payments Product Mappings
 * 
 * Run this script once to clear stale product mappings.
 * This is useful when:
 * - Switching between Test and Live modes
 * - Products have been deleted from Dodo Payments dashboard
 * - Product IDs are out of sync
 * 
 * Usage: 
 * 1. Upload this file to your plugin directory
 * 2. Access it via: https://yoursite.com/wp-content/plugins/dodo-payments-for-woocommerce/clear-product-mappings.php
 * 3. Delete this file after running (for security)
 * 
 * @package Dodo_Payments_For_WooCommerce
 * @version 0.4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // Load WordPress
    require_once('../../../../../wp-load.php');
}

// Security check - only allow admin users
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized access');
}

// Load the database class
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-product-db.php';

global $wpdb;
$table_name = $wpdb->prefix . 'dodo_payments_products';

// Clear all product mappings
$result = $wpdb->query("TRUNCATE TABLE {$table_name}");

if ($result !== false) {
    echo '<div style="padding: 20px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 4px; margin: 20px;">';
    echo '<h2>✅ Success!</h2>';
    echo '<p>All product mappings have been cleared.</p>';
    echo '<p>Products will be automatically re-synced on the next checkout.</p>';
    echo '<p><strong>Important:</strong> Please delete this file (clear-product-mappings.php) for security.</p>';
    echo '</div>';
} else {
    echo '<div style="padding: 20px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px; margin: 20px;">';
    echo '<h2>❌ Error</h2>';
    echo '<p>Failed to clear product mappings.</p>';
    echo '<p>Error: ' . esc_html($wpdb->last_error) . '</p>';
    echo '</div>';
}

// Show current mode
$gateway = new WC_Gateway();
$gateway_settings = get_option('woocommerce_dodo_payments_settings', array());
$testmode = isset($gateway_settings['testmode']) && $gateway_settings['testmode'] === 'yes';

echo '<div style="padding: 20px; background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; border-radius: 4px; margin: 20px;">';
echo '<h3>Current Configuration</h3>';
echo '<p><strong>Mode:</strong> ' . ($testmode ? 'Test Mode' : 'Live Mode') . '</p>';
echo '<p><strong>Next Steps:</strong></p>';
echo '<ol>';
echo '<li>Ensure you have products created in Dodo Payments ' . ($testmode ? 'Test' : 'Live') . ' dashboard</li>';
echo '<li>Add a product to cart and proceed to checkout</li>';
echo '<li>Products will be automatically synced to Dodo Payments</li>';
echo '<li>Check debug.log to verify successful sync</li>';
echo '</ol>';
echo '</div>';

