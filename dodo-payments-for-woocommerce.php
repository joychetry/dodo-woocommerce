<?php

/**
 * Plugin Name: Dodo Payments for WooCommerce
 * Plugin URI: https://github.com/joychetry/dodo-woocommerce
 * Short Description: Accept payments globally within minutes.
 * Description: Dodo Payments plugin for WooCommerce. Accept payments from your customers using Dodo Payments.
 * Version: 1.2.2
 * Author: Classic Monks
 * Author URI: https://classicmonks.com
 * Developer: Classic Monks
 * Text Domain: dodo-payments-for-woocommerce
 *
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * Requires PHP: 7.4
 * Requires at least: 7.1
 * Requires Plugins: woocommerce
 * Tested up to: 7.1.4
 * WC requires at least: 11.0
 * WC tested up to: 11.2.1
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
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

require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-checkout-settings.php';
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

// WooCommerce only saves a settings section when the posted form carries its own `save`
// button, so a submission from the "Reset checkout options" button has to be let through.
add_filter('woocommerce_save_settings_checkout_dodo_payments', function ($save) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence check only; WC_Admin_Settings::save() verifies the nonce.
    return $save || !empty($_POST[Dodo_Payments_Checkout_Settings::RESET_FIELD]);
});

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
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
});

// Register Dodo Payments with the WooCommerce checkout block.
add_action('woocommerce_blocks_loaded', function () {
    require_once DODO_PAYMENTS_PLUGIN_DIR . 'includes/class-dodo-payments-blocks.php';
    add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
        $registry->register(new Dodo_Payments_Blocks());
    });
    add_action('woocommerce_init', array('Dodo_Payments_Blocks', 'register_b2b_fields'));
    add_action('woocommerce_store_api_checkout_update_order_from_request', array('Dodo_Payments_Blocks', 'copy_b2b_fields_to_order'));
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
                    private string $brand_id;

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

/**
 * Keep free-trial subscription parent orders in "pending" for the full trial.
 *
 * WooCommerce's unpaid-order sweeper (woocommerce_cancel_unpaid_orders) cancels any
 * "pending" order whose last update is older than woocommerce_hold_stock_minutes
 * (~60 min by default). A trial parent order legitimately stays "pending" until the
 * trial ends — the $0 mandate webhook deliberately skips payment_complete() — so
 * without this exemption it gets cancelled within an hour while the subscription is
 * still in its trial.
 *
 * @since 1.1.0
 */
add_filter('woocommerce_cancel_unpaid_order', 'dodo_payments_keep_trial_orders_pending', 10, 2);
function dodo_payments_keep_trial_orders_pending($cancel, $order)
{
    if (!$cancel || $order->get_payment_method() !== 'dodo_payments') {
        return $cancel;
    }

    // The trial flag lives on the subscription, not the parent. Resolve it via the
    // _lm_subscription_id stored on each line item at subscription creation.
    foreach ($order->get_items() as $item) {
        $subscription_id = $item->get_meta('_lm_subscription_id', true);
        if (!$subscription_id) {
            continue;
        }
        $subscription = wc_get_order($subscription_id);
        if ($subscription && is_a($subscription, 'LicenseMonks_Subscription') && $subscription->has_trial()) {
            return false; // In free trial — do not auto-cancel.
        }
    }

    return $cancel;
}

/**
 * Syncs WooCommerce Subscriptions and License Monks status changes to Dodo Payments.
 *
 * Registered at plugin level because gateways are instantiated lazily: requests such as
 * cancelling an order from My Account (?cancel_order=true) never load them, so hooks added
 * in the gateway constructor would miss the change and Dodo would keep charging.
 *
 * @since 1.2.1
 */
foreach (array(
    'woocommerce_subscription_status_updated' => 'handle_subscription_status_updated',
    'woocommerce_subscription_dates_updated' => 'handle_subscription_date_change',
    'licensemonks/subscription/status/updated' => 'handle_lm_subscription_status_updated',
) as $dodo_payments_hook => $dodo_payments_method) {
    add_action($dodo_payments_hook, function ($subscription, ...$args) use ($dodo_payments_method) {
        $gateway = dodo_payments_get_gateway_for($subscription);
        if ($gateway) {
            $gateway->$dodo_payments_method($subscription, ...$args);
        }
    }, 10, 3);
}
unset($dodo_payments_hook, $dodo_payments_method);

/**
 * Loads the gateways on the order-received page, so the gateway's return handler
 * (capture_payment_id_from_return, template_redirect priority 10) runs there:
 * WooCommerce does not instantiate gateways on that page by itself.
 *
 * @since 1.2.1
 */
add_action('template_redirect', function () {
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
        WC()->payment_gateways();
    }
}, 9);

// Shown on the pay-for-order page after a declined or abandoned Dodo payment (see capture_payment_id_from_return).
add_action('before_woocommerce_pay', function () {
    if (!empty($_GET['dodo_payment_failed'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        wc_print_notice(__('Your payment was not completed. Please try again or use a different payment method.', 'dodo-payments-for-woocommerce'), 'error');
    }

    $order = wc_get_order(absint(get_query_var('order-pay')));
    $subscription = $order ? dodo_payments_get_trial_subscription($order) : null;
    if ($subscription) {
        $trial_end = $subscription->get_trial_end_date_utc();
        wc_print_notice(sprintf(
            // translators: %s: Trial end date
            __('You are on a free trial until %s. Paying now ends your trial early: the card you added at signup is charged right away and your first billing period starts today.', 'dodo-payments-for-woocommerce'),
            $trial_end ? wc_format_datetime($trial_end) : __('the end of your trial', 'dodo-payments-for-woocommerce')
        ), 'notice');
    }
});

/**
 * Labels the Pay action on free-trial orders, which ends the trial instead of starting a new checkout.
 *
 * @since 1.2.1
 */
add_filter('woocommerce_my_account_my_orders_actions', function ($actions, $order) {
    if (isset($actions['pay']) && dodo_payments_get_trial_subscription($order)) {
        $actions['pay']['name'] = __('End trial & pay now', 'dodo-payments-for-woocommerce');
    }
    return $actions;
}, 10, 2);

/**
 * The License Monks subscription of an unpaid Dodo Payments order that is still in its free trial.
 *
 * @since 1.2.1
 * @param WC_Order $order Parent order.
 * @return WC_Order|null
 */
function dodo_payments_get_trial_subscription($order)
{
    // Only pending: a failed order means the charge at trial end was declined.
    if ($order->get_payment_method() !== 'dodo_payments' || !$order->has_status('pending')) {
        return null;
    }
    foreach ($order->get_items() as $item) {
        $subscription = wc_get_order($item->get_meta('_lm_subscription_id', true));
        if ($subscription && $subscription->get_type() === 'lm_subscription' && $subscription->get_meta('_dodo_trial_active') === 'yes') {
            return $subscription;
        }
    }
    return null;
}

/**
 * Returns the gateway instance WooCommerce registered, if the subscription is paid with Dodo Payments.
 *
 * @since 1.2.1
 * @param WC_Order $subscription
 * @return Dodo_Payments_WC_Gateway|null
 */
function dodo_payments_get_gateway_for($subscription)
{
    // Check before loading gateways, so other payment methods pay nothing for this hook.
    if (!$subscription || $subscription->get_payment_method() !== 'dodo_payments' || !function_exists('WC')) {
        return null;
    }

    $gateway = WC()->payment_gateways()->payment_gateways()['dodo_payments'] ?? null;
    if (!$gateway) {
        wc_get_logger()->warning('Dodo Payments: gateway not found, subscription #' . $subscription->get_id() . ' change was not synced to Dodo Payments.', array('source' => 'dodo-payments'));
    }

    return $gateway;
}

/**
 * Registers the invoice admin hooks (orders-list action button, columns, order-edit section)
 * independently of the payment gateway being instantiated.
 *
 * WC_Payment_Gateways only instantiates gateways lazily (Settings page, bulk actions, checkout),
 * so the orders list page would otherwise render without the invoice action button. Registering
 * the hooks here (guarded by is_admin) makes the button appear consistently on every admin request.
 *
 * @since 1.0.1
 */
add_action('admin_init', 'dodo_payments_register_admin_invoice_hooks');
function dodo_payments_register_admin_invoice_hooks()
{
    if (!is_admin() || !class_exists('Dodo_Payments_WC_Gateway') || !method_exists('Dodo_Payments_WC_Gateway', 'add_admin_invoice_hooks')) {
        return;
    }

    // Build a minimal gateway instance to access the trait methods. The constructor reads settings
    // and instantiates the API client; that's fine in admin (cheap, no network until used).
    $gateway = new Dodo_Payments_WC_Gateway();
    $gateway->add_admin_invoice_hooks();
}

/**
 * Adds an Invoice link to each order's actions in the My Account orders list.
 *
 * An action rather than a column: themes that override myaccount/orders.php (e.g. lm-theme)
 * usually still print wc_get_account_orders_actions() but drop custom column hooks.
 *
 * @since 1.2.2
 */
add_filter('woocommerce_my_account_my_orders_actions', function ($actions, $order) {
    $gateway = dodo_payments_get_gateway_for($order);
    return $gateway ? $gateway->add_myaccount_invoice_action($actions, $order) : $actions;
}, 10, 2);

// Invoice section on the customer's view-order and order-received pages. Hooked to the page
// actions, not woocommerce_order_details_after_order_table: themes that replace the order
// details table (e.g. lm-theme's view-order.php) never fire that one.
foreach (array('woocommerce_view_order', 'woocommerce_thankyou') as $dodo_payments_hook) {
    add_action($dodo_payments_hook, function ($order_id) {
        $order = wc_get_order($order_id);
        $gateway = $order ? dodo_payments_get_gateway_for($order) : null;
        if ($gateway) {
            $gateway->display_invoice_link($order);
        }
    }, 20, 1);
}
unset($dodo_payments_hook);
