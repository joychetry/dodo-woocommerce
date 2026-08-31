<?php

/**
 * Plugin Name: Dodo Payments for WooCommerce
 * Plugin URI: https://dodopayments.com
 * Short Description: Accept payments globally within minutes.
 * Description: Dodo Payments plugin for WooCommerce. Accept payments from your customers using Dodo Payments.
 * Version: 1.0.0
 * Author: Dodo Payments
 * Developer: Dodo Payments
 * Text Domain: dodo-payments-for-woocommerce
 *
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires PHP: 7.4
 * Requires at least: 6.1
 * Requires Plugins: woocommerce
 * Tested up to: 6.9.1
 * WC requires at least: 7.9
 * WC tested up to: 10.5.2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Plugin path constants — single source of truth for paths and URLs.
// Always reference these instead of __FILE__ inside includes/ traits, where
// __FILE__ would resolve to the includes/ directory.
if (!defined('DODO_PAYMENTS_PLUGIN_FILE')) {
    define('DODO_PAYMENTS_PLUGIN_FILE', __FILE__);
}
if (!defined('DODO_PAYMENTS_PLUGIN_DIR')) {
    define('DODO_PAYMENTS_PLUGIN_DIR', plugin_dir_path(DODO_PAYMENTS_PLUGIN_FILE));
}
if (!defined('DODO_PAYMENTS_PLUGIN_URL')) {
    define('DODO_PAYMENTS_PLUGIN_URL', plugin_dir_url(DODO_PAYMENTS_PLUGIN_FILE));
}
if (!defined('DODO_PAYMENTS_PLUGIN_BASENAME')) {
    define('DODO_PAYMENTS_PLUGIN_BASENAME', plugin_basename(DODO_PAYMENTS_PLUGIN_FILE));
}

// NOTE: Order of inclusion is important here. We want to include the DB classes before the API class.
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-product-db.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-payment-db.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-coupon-db.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-subscription-db.php';

require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-cart-exception.php';

require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-api.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-standard-webhook.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-invoice.php';

// Gateway traits: the Dodo_Payments_WC_Gateway class is assembled from these
// focused files so the plugin stays maintainable as it grows.
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/trait-dodo-payments-core.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/trait-dodo-payments-payment.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/trait-dodo-payments-subscriptions.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/trait-dodo-payments-invoices.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/trait-dodo-payments-b2b.php';
require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/trait-dodo-payments-webhooks.php';

// Create database tables on plugin activation
register_activation_hook(__FILE__, function () {
    Dodo_Payments_Product_DB::create_table();
    Dodo_Payments_Payment_DB::create_table();
    Dodo_Payments_Coupon_DB::create_table();
    Dodo_Payments_Subscription_DB::create_table();
    // Flag to flush rewrite rules on next init
    update_option('dodo_payments_flush_rewrite_rules', true);
});

// Make the plugin HPOS compatible
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('plugins_loaded', 'dodo_payments_init');

/**
 * Initializes the Dodo Payments payment gateway for WooCommerce.
 *
 * Registers the Dodo Payments gateway class if WooCommerce is active, enabling support for standard payments and subscriptions, including subscription lifecycle management and webhook handling.
 */
function dodo_payments_init()
{
    if (class_exists('WC_Payment_Gateway')) {
class Dodo_Payments_WC_Gateway extends WC_Payment_Gateway
{
                    public ?string $instructions;

                    // Properties set by parent WC_Payment_Gateway class
                    public $enable_coupons;

                    private bool $testmode;
                    private string $api_key;
                    private string $webhook_key;

                    protected Dodo_Payments_API $dodo_payments_api;

                    private string $global_tax_category;
                    private bool $global_tax_inclusive;
                    private bool $enable_tax_id_collection;
                    private bool $enable_overlay_checkout;


            use DodoPaymentsB2b;
            use DodoPaymentsCore;
            use DodoPaymentsInvoices;
            use DodoPaymentsPayment;
            use DodoPaymentsSubscriptions;
            use DodoPaymentsWebhooks;

}
    }
}

add_filter('woocommerce_payment_gateways', 'dodo_payments_add_gateway_class_to_woo');
function dodo_payments_add_gateway_class_to_woo($gateways)
{
    $gateways[] = 'Dodo_Payments_WC_Gateway';
    return $gateways;
}