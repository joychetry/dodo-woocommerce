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
        // Dodo bills renewals; stops WooCommerce Subscriptions from creating its own renewal
        // order and putting the subscription on-hold (which would pause it in Dodo).
        'gateway_scheduled_payments',
    );

    $this->enabled = $this->get_option('enabled');
    $this->title = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->instructions = $this->get_option('instructions');

    $this->testmode = 'yes' === $this->get_option('testmode');
    $this->api_key = $this->testmode ? $this->get_option('test_api_key') : $this->get_option('live_api_key');
    $this->webhook_key = $this->testmode ? $this->get_option('test_webhook_key') : $this->get_option('live_webhook_key');
    $this->brand_id = $this->testmode ? $this->get_option('test_brand_id') : $this->get_option('live_brand_id');

    $this->global_tax_category = $this->get_option('global_tax_category');
    $this->global_tax_inclusive = 'yes' === $this->get_option('global_tax_inclusive');
    $this->enable_tax_id_collection = 'yes' === $this->get_option('enable_tax_id_collection');
    $this->enable_overlay_checkout = 'yes' === $this->get_option('enable_overlay_checkout');

    $this->init_form_fields();
    $this->init_settings();

    $this->init_dodo_payments_api();

    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

    add_action('woocommerce_thankyou_' . $this->id, array($this, 'thank_you_page'));

    // Capture payment_id from return URL after checkout session completion
    add_action('template_redirect', array($this, 'capture_payment_id_from_return'), 10);

    // webhook to http://<site-host>/wc-api/dodo_payments
    add_action('woocommerce_api_' . $this->id, array($this, 'webhook'));

    add_action('init', array($this, 'maybe_flush_rewrite_rules'));

    // All invoice display hooks (order details, My Account column, admin) are registered at
    // plugin level in dodo-payments-for-woocommerce.php — NOT here. Gateways load lazily, so
    // constructor hooks miss pages like view-order, and double-register (rendering invoice
    // sections twice) when more than one instance exists.

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

    // Subscription sync hooks are registered at plugin level in dodo-payments-for-woocommerce.php,
    // because the gateway is not instantiated on every request.
}

private function init_dodo_payments_api()
{
    $this->dodo_payments_api = new Dodo_Payments_API(array(
        'testmode' => $this->testmode,
        'api_key' => $this->api_key,
        'global_tax_category' => $this->global_tax_category,
        'global_tax_inclusive' => $this->global_tax_inclusive,
        'brand_id' => $this->brand_id,
        'checkout_options' => Dodo_Payments_Checkout_Settings::build_request_options(array($this, 'get_option')),
        'feature_flags' => Dodo_Payments_Checkout_Settings::feature_flag_overrides(array($this, 'get_option')),
    ));
}

/**
 * Whether the current request is this gateway's settings screen.
 *
 * Read-only check on the URL, so no nonce is involved.
 *
 * @return bool
 */
private function is_gateway_settings_screen()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    return is_admin() && isset($_GET['page'], $_GET['section']) && 'wc-settings' === $_GET['page'] && $this->id === $_GET['section'];
}

/**
 * Loads the collapsible sections, the questions editor and the reset button.
 *
 * @return void
 * @since 1.3.0
 */
public function enqueue_admin_assets()
{
    if (!$this->is_gateway_settings_screen()) {
        return;
    }

    $css = 'assets/css/dodo-admin-settings.css';
    $js = 'assets/js/dodo-admin-settings.min.js';

    // filemtime as the version: admin-only, and it cannot drift from the file.
    wp_enqueue_style('dodo-payments-admin-settings', DODO_PAYMENTS_PLUGIN_URL . $css, array(), (string) filemtime(DODO_PAYMENTS_PLUGIN_DIR . $css));

    // The payment methods multiselect uses WooCommerce's enhanced select.
    if (wp_script_is('wc-enhanced-select', 'registered')) {
        wp_enqueue_script('wc-enhanced-select');
    }

    wp_enqueue_script('dodo-payments-admin-settings', DODO_PAYMENTS_PLUGIN_URL . $js, array('jquery'), (string) filemtime(DODO_PAYMENTS_PLUGIN_DIR . $js), true);

    $prefix = Dodo_Payments_Checkout_Settings::PREFIX;
    wp_localize_script('dodo-payments-admin-settings', 'dodoPaymentsSettings', array(
        'sectionClass' => Dodo_Payments_Checkout_Settings::SECTION_CLASS,
        'cancelModeField' => $this->get_field_key($prefix . 'cancel_url_mode'),
        'cancelCustomField' => $this->get_field_key($prefix . 'cancel_url_custom'),
        'resetField' => Dodo_Payments_Checkout_Settings::RESET_FIELD,
        'i18n' => array(
            'reset' => __('Reset checkout options', 'dodo-payments-for-woocommerce'),
            'resetConfirm' => __('Reset every checkout option and feature flag to its default? Your general settings, API keys, webhook signing keys and brand are kept. Unsaved changes on this page will be lost.', 'dodo-payments-for-woocommerce'),
            'oneField' => __('1 setting', 'dodo-payments-for-woocommerce'),
            /* translators: %d: number of settings in the section */
            'manyFields' => __('%d settings', 'dodo-payments-for-woocommerce'),
        ),
    ));
}

/**
 * Saves the settings form, or resets the checkout options when the reset button submitted it.
 *
 * A reset clears the Checkout Session options and feature flags only. Unsaved edits
 * elsewhere on the page are discarded rather than half-applied.
 *
 * @return bool
 * @since 1.3.0
 */
public function process_admin_options()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by WC_Admin_Settings::save().
    if (empty($_POST[Dodo_Payments_Checkout_Settings::RESET_FIELD])) {
        return parent::process_admin_options();
    }

    $this->init_settings();
    $this->settings = Dodo_Payments_Checkout_Settings::reset_settings($this->settings);

    return update_option(
        $this->get_option_key(),
        apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings),
        'yes'
    );
}

/**
 * Renders the extra checkout questions editor.
 *
 * Dispatched by WC_Settings_API for form fields of type `dodo_custom_fields`.
 *
 * @param string               $key  Field key.
 * @param array<string, mixed> $data Field definition.
 * @return string
 * @since 1.3.0
 */
public function generate_dodo_custom_fields_html($key, $data)
{
    return Dodo_Payments_Checkout_Settings::render_custom_fields(
        $this->get_field_key($key),
        wp_parse_args($data, array('title' => '', 'description' => '')),
        $this->get_option($key)
    );
}

/**
 * Builds the brand dropdown options for one mode from that mode's API key.
 *
 * Only calls the API on this gateway's settings screen, since the gateway (and its form
 * fields) is constructed on checkout requests too. The saved brand is always kept as an
 * option so a failed or keyless lookup can't silently reset it on save.
 *
 * @param bool $testmode Whether to list test mode brands.
 * @return array<string, string> Brand ID => label.
 */
private function get_brand_options($testmode)
{
    $api_key = $this->get_option($testmode ? 'test_api_key' : 'live_api_key');
    $saved = $this->get_option($testmode ? 'test_brand_id' : 'live_brand_id');

    $options = array('' => __('Not set (Dodo default: primary brand)', 'dodo-payments-for-woocommerce'));
    if ('' !== $saved) {
        $options[$saved] = $saved;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
    if ('' === $api_key || !$this->is_gateway_settings_screen()) {
        return $options;
    }

    $api = new Dodo_Payments_API(array(
        'testmode' => $testmode,
        'api_key' => $api_key,
        'global_tax_category' => $this->global_tax_category,
        'global_tax_inclusive' => $this->global_tax_inclusive,
    ));

    foreach ((array) $api->list_brands() as $brand) {
        if (empty($brand['brand_id']) || (empty($brand['enabled']) && $brand['brand_id'] !== $saved)) {
            continue;
        }
        $label = !empty($brand['name']) ? $brand['name'] : $brand['brand_id'];
        if (isset($brand['business_id']) && $brand['brand_id'] === $brand['business_id']) {
            $label .= ' ' . __('(primary)', 'dodo-payments-for-woocommerce');
        }
        $options[$brand['brand_id']] = $label . ' — ' . $brand['brand_id'];
    }

    return $options;
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
    wc_get_logger()->debug('Dodo Payments: ' . $message, array('source' => 'dodo-payments'));
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
        'live_brand_id' => array(
            'title' => __('Live Brand', 'dodo-payments-for-woocommerce'),
            'type' => 'select',
            'options' => $this->get_brand_options(false),
            'default' => '',
            'desc_tip' => false,
            'description' => __('Products are created and updated under this brand, and orders take the brand of their products. Existing products move to it the next time they are bought; live subscriptions keep their current brand. Save the Live API Key to load the brand list.', 'dodo-payments-for-woocommerce'),
        ),
        'test_brand_id' => array(
            'title' => __('Test Brand', 'dodo-payments-for-woocommerce'),
            'type' => 'select',
            'options' => $this->get_brand_options(true),
            'default' => '',
            'desc_tip' => false,
            'description' => __('Brand used in test mode. Save the Test API Key to load the brand list.', 'dodo-payments-for-woocommerce'),
        ),
        'enable_tax_id_collection' => array(
            'title' => __('Enable Tax ID Collection', 'dodo-payments-for-woocommerce'),
            'type' => 'checkbox',
            'label' => __('Allow customers to provide their Tax ID / VAT number during checkout', 'dodo-payments-for-woocommerce'),
            'default' => 'no',
            'desc_tip' => false,
            'description' => __('Adds a "Purchasing as a business" option with company and Tax ID / VAT number fields to your checkout, and lets customers enter a tax ID on the Dodo Payments checkout page. Useful for B2B sales and tax compliance.', 'dodo-payments-for-woocommerce'),
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
            'description' => __('When enabled, customers complete checkout in a pop-up on your checkout page instead of being redirected to Dodo Payments.', 'dodo-payments-for-woocommerce'),
        ),
    );

    // Checkout Session options and feature flags, then the webhook URL last as before.
    $this->form_fields = array_merge(
        $this->form_fields,
        Dodo_Payments_Checkout_Settings::form_fields(),
        array(
            'webhook_endpoint' => array(
                'title' => __('Webhook Endpoint', 'dodo-payments-for-woocommerce'),
                'type' => 'title',
                'description' => $webhook_help_description,
            ),
        )
    );
}

}
