<?php
/**
 * Core gateway logic: constructor, API initialization, settings form fields, and debug logging.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Core gateway logic: constructor, API initialization, settings form fields, and debug logging.
 *
 * @since 0.7.0
 */
trait DodoPaymentsCore
{

public function __construct()
{
    $this->id = 'dodo_payments';
    $this->icon = DODO_PAYMENTS_PLUGIN_URL . 'assets/logo.png';
    $this->has_fields = false;

    $this->method_title = __('Dodo Payments', 'dodo-payments-for-woocommerce');
    $this->method_description = __('Accept payments via Dodo Payments.', 'dodo-payments-for-woocommerce');

    // Declare subscription support
    $this->supports = array(
        'products',
        'subscriptions',
        'subscription_cancellation',
        'subscription_suspension',
        'subscription_reactivation',
    );

    $this->enabled = $this->get_option('enabled');
    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->instructions = $this->get_option('instructions');

    $this->testmode = 'yes' === $this->get_option('testmode');
    $this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('live_api_key');
    $this->webhook_key = $this->testmode ? $this->get_option('test_webhook_key') : $this->get_option('live_webhook_key');

    $this->global_tax_category = $this->get_option('global_tax_category');
    $this->global_tax_inclusive = 'yes' === $this->get_option('global_tax_inclusive');
    $this->enable_tax_id_collection = 'yes' === $this->get_option('enable_tax_id_collection');
    $this->enable_overlay_checkout = 'yes' === $this->get_option('enable_overlay_checkout');

    $this->init_form_fields();
    $this->init_settings();

    $this->init_dodo_payments_api();

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

    add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page'));

    // Capture payment_id from return URL after checkout session completion
    add_action('template_redirect', array($this, 'capture_payment_id_from_return'), 10);

    // webhook to http://<site-host>/wc-api/dodo_payments
    add_action('woocommerce_api_' . $this->id, array($this, 'webhook'));

    // Invoice display in My Account
    add_action('woocommerce_order_details_after_order_table', array($this, 'display_invoice_link'), 10, 1);
    add_action('init', array($this, 'add_invoice_endpoint'));
    add_action('init', array($this, 'maybe_flush_rewrite_rules'));

    // Invoice display in My Account orders table (frontend for customers)
    add_filter('woocommerce_account_orders_columns', array($this, 'add_myaccount_invoice_column'), 10, 1);
    add_action('woocommerce_account_orders_column_invoice', array($this, 'render_myaccount_invoice_column'), 10, 1);

    // Invoice display in admin (HPOS compatible)
    add_action('admin_init', array($this, 'add_admin_invoice_hooks'));

    // Overlay checkout scripts
    add_action('wp_enqueue_scripts', array($this, 'enqueue_overlay_checkout_scripts'));
    
    // AJAX handler to clear checkout session URL
    add_action('wp_ajax_dodo_clear_checkout_session', array($this, 'ajax_clear_checkout_session'));
    add_action('wp_ajax_nopriv_dodo_clear_checkout_session', array($this, 'ajax_clear_checkout_session'));

    // AJAX: extend trial period for a subscription (admin only)
    add_action('wp_ajax_dodo_extend_trial_period', array($this, 'ajax_extend_trial_period'));
    // Clear session after payment completion
    add_action('woocommerce_thankyou_' . $this->id, array($this, 'clear_checkout_session_after_payment'), 5);

    // "Purchasing as a business" fields on the WooCommerce checkout.
    // Only registered when Tax ID Collection is enabled — otherwise the
    // toggle/Tax ID inputs would show even though the setting is off.
    if ($this->enable_tax_id_collection) {
        add_action('woocommerce_after_checkout_billing_form', array($this, 'add_buy_as_company_fields'));
        add_action('woocommerce_checkout_process', array($this, 'validate_buy_as_company_fields'));
        add_action('woocommerce_checkout_update_order_meta', array($this, 'save_buy_as_company_fields'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_company_fields_script'));
    }

    // Subscription-related actions
    if (class_exists('WC_Subscriptions')) {
        add_action('woocommerce_subscription_status_updated', array($this, 'handle_subscription_status_updated'), 10, 3);
        add_action('woocommerce_subscription_dates_updated', array($this, 'handle_subscription_date_change'), 10, 2);
    }

    if (class_exists('LicenseMonks_Subscription')) {
        add_action('licensemonks/subscription/status/updated', array($this, 'handle_lm_subscription_status_updated'), 10, 3);
        // Date changes sync not supported for LM either yet
    }
}

private function init_dodo_payments_api()
{
    $this->dodo_payments_api = new Dodo_Payments_API(array(
        'testmode' => $this->testmode,
        'api_key' => $this->api_key,
        'global_tax_category' => $this->global_tax_category,
        'global_tax_inclusive' => $this->global_tax_inclusive,
    ));
}

/**
 * Logs debug messages only when WP_DEBUG is enabled
 *
 * @param string $message The message to log
 * @return void
 * @since 0.4.1
 */
private function log_debug($message)
{
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $logger->debug('Dodo Payments: ' . $message, array('source' => 'dodo-payments'));
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        // Fallback to error log if WooCommerce logger is not available
        error_log('Dodo Payments: ' . $message);
    }
}

/**
 * Initializes the form fields for Dodo Payments settings page
 *
 * @return void
 *
 * @since 0.1.0
 */
public function init_form_fields()
{
    $webhook_url = add_query_arg('wc-api', $this->id, trailingslashit(home_url()));
    $webhook_help_description = '<p>' .
        __('Webhook endpoint for Dodo Payments. Use the below URL when generating a webhook signing key on Dodo Payments Dashboard.', 'dodo-payments-for-woocommerce')
        . '</p><p><code>' . $webhook_url . '</code></p>';
    ;

    $this->form_fields = array(
        'enabled' => array(
            'title' => __('Enable/Disable', 'dodo-payments-for-woocommerce'),
            'type' => 'checkbox',
            'label' => __('Enable Dodo Payments', 'dodo-payments-for-woocommerce'),
            'default' => 'no'
        ),
        'title' => array(
            'title' => __('Title', 'dodo-payments-for-woocommerce'),
            'type' => 'text',
            'default' => __('Dodo Payments', 'dodo-payments-for-woocommerce'),
            'desc_tip' => false,
            'description' => __('Title for our payment method that the user will see on the checkout page.', 'dodo-payments-for-woocommerce'),
        ),
        'description' => array(
            'title' => __('Description', 'dodo-payments-for-woocommerce'),
            'type' => 'textarea',
            'default' => __('Pay via Dodo Payments', 'dodo-payments-for-woocommerce'),
            'desc_tip' => false,
            'description' => __('Description for our payment method that the user will see on the checkout page.', 'dodo-payments-for-woocommerce'),
        ),
        'instructions' => array(
            'title' => __('Instructions', 'dodo-payments-for-woocommerce'),
            'type' => 'textarea',
            'default' => '',
            'desc_tip' => false,
            'description' => __('Instructions that will be added to the thank you page and emails.', 'dodo-payments-for-woocommerce'),
        ),
        'testmode' => array(
            'title' => __('Test Mode', 'dodo-payments-for-woocommerce'),
            'type' => 'checkbox',
            'label' => __('Enable Test Mode, <b>No actual payments will be made, always remember to disable this when you are ready to go live</b>', 'dodo-payments-for-woocommerce'),
            'default' => 'no'
        ),
        'live_api_key' => array(
            'title' => __('Live API Key', 'dodo-payments-for-woocommerce'),
            'type' => 'text',
            'default' => '',
            'desc_tip' => false,
            'description' => __('Your Live API Key. Required to receive payments. Generate one from <b>Dodo Payments (Live Mode) &gt; Developer &gt; API Keys</b>', 'dodo-payments-for-woocommerce'),
        ),
        'live_webhook_key' => array(
            'title' => __('Live Webhook Signing Key', 'dodo-payments-for-woocommerce'),
            'type' => 'text',
            'default' => '',
            'desc_tip' => false,
            'description' => __('Your Live Webhook Signing Key. Required to sync status for payments, recommended for setup. Generate one from <b>Dodo Payments (Live Mode) &gt; Developer &gt; Webhooks</b>, use the URL at the bottom of this page as the webhook URL.', 'dodo-payments-for-woocommerce'),
        ),
        'test_api_key' => array(
            'title' => __('Test API Key', 'dodo-payments-for-woocommerce'),
            'type' => 'text',
            'default' => '',
            'desc_tip' => false,
            'description' => __('Your Test API Key. Optional, only required if you want to receive test payments. Generate one from <b>Dodo Payments (Test Mode) &gt; Developer &gt; API Keys</b>', 'dodo-payments-for-woocommerce'),
        ),
        'test_webhook_key' => array(
            'title' => __('Test Webhook Signing Key', 'dodo-payments-for-woocommerce'),
            'type' => 'text',
            'default' => '',
            'desc_tip' => false,
            'description' => __('Your Test Webhook Signing Key. Optional, only required if you want to receive test payments. Generate one from <b>Dodo Payments (Test Mode) &gt; Developer &gt; Webhooks</b>, use the URL at the bottom of this page as the webhook URL.', 'dodo-payments-for-woocommerce'),
        ),
        'global_tax_category' => array(
            'title' => __('Global Tax Category', 'dodo-payments-for-woocommerce'),
            'type' => 'select',
            'options' => array(
                'digital_products' => __('Digital Products', 'dodo-payments-for-woocommerce'),
                'saas' => __('SaaS', 'dodo-payments-for-woocommerce'),
                'e_book' => __('E-Book', 'dodo-payments-for-woocommerce'),
                'edtech' => __('EdTech', 'dodo-payments-for-woocommerce'),
            ),
            'default' => 'digital_products',
            'desc_tip' => false,
            'description' => __('Select the tax category for all products. You can override this on a per-product basis on Dodo Payments Dashboard.', 'dodo-payments-for-woocommerce'),
        ),
        'global_tax_inclusive' => array(
            'title' => __('All Prices are Tax Inclusive', 'dodo-payments-for-woocommerce'),
            'type' => 'checkbox',
            'default' => 'no',
            'desc_tip' => false,
            'description' => __('Select if tax is included on all product prices. You can override this on a per-product basis on Dodo Payments Dashboard.', 'dodo-payments-for-woocommerce'),
        ),
        'enable_tax_id_collection' => array(
            'title' => __('Enable Tax ID Collection', 'dodo-payments-for-woocommerce'),
            'type' => 'checkbox',
            'label' => __('Allow customers to provide their Tax ID / VAT number during checkout', 'dodo-payments-for-woocommerce'),
            'default' => 'no',
            'desc_tip' => false,
            'description' => __('When enabled, customers will be able to enter their business Tax ID or VAT number on the Dodo Payments checkout page. This is useful for B2B transactions and tax compliance. Uses the modern Checkout Sessions API.', 'dodo-payments-for-woocommerce'),
        ),
        'overlay_checkout_title' => array(
            'title' => __('Overlay Checkout', 'dodo-payments-for-woocommerce'),
            'type' => 'title',
            'description' => __('Enable embedded checkout overlay for a seamless checkout experience without leaving your site.', 'dodo-payments-for-woocommerce'),
        ),
        'enable_overlay_checkout' => array(
            'title' => __('Enable Overlay Checkout', 'dodo-payments-for-woocommerce'),
            'type' => 'checkbox',
            'label' => __('Use overlay checkout SDK for embedded checkout experience', 'dodo-payments-for-woocommerce'),
            'default' => 'no',
            'desc_tip' => false,
            'description' => __('When enabled, customers will complete checkout in an overlay modal without leaving your site. Requires Checkout Sessions API (automatically enabled when Tax ID Collection is enabled).', 'dodo-payments-for-woocommerce'),
        ),
        'webhook_endpoint' => array(
            'title' => __('Webhook Endpoint', 'dodo-payments-for-woocommerce'),
            'type' => 'title',
            'description' => $webhook_help_description,
        )
    );
}

}
