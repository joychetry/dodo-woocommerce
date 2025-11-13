<?php

/**
 * Plugin Name: Dodo Payments for WooCommerce
 * Plugin URI: https://dodopayments.com
 * Short Description: Accept payments globally within minutes.
 * Description: Dodo Payments plugin for WooCommerce. Accept payments from your customers using Dodo Payments.
 * Version: 0.4.1
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
 * Tested up to: 6.8
 * WC requires at least: 7.9
 * WC tested up to: 9.6
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// NOTE: Order of inclusion is important here. We want to include the DB classes before the API class.
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-product-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-payment-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-coupon-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-subscription-db.php';

require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-cart-exception.php';

require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-standard-webhook.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-dodo-payments-invoice.php';
// Create database tables on plugin activation
register_activation_hook(__FILE__, function () {
    Dodo_Payments_Product_DB::create_table();
    Dodo_Payments_Payment_DB::create_table();
    Dodo_Payments_Coupon_DB::create_table();
    Dodo_Payments_Subscription_DB::create_table();
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

            private bool $testmode;
            private string $api_key;
            private string $webhook_key;

            protected Dodo_Payments_API $dodo_payments_api;

            private string $global_tax_category;
            private bool $global_tax_inclusive;
            private bool $enable_tax_id_collection;
            private bool $enable_overlay_checkout;

            public function __construct()
            {
                $this->id = 'dodo_payments';
                $this->icon = plugins_url('/assets/logo.png', __FILE__);
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
                // Default to 'yes' for backward compatibility (coupons were always enabled before)
                $this->enable_coupons = 'yes' === $this->get_option('enable_coupons', 'yes');

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
                
                // Secure PDF invoice serving endpoint
                add_action('template_redirect', array($this, 'serve_invoice_pdf'), 5);

                // Invoice display in admin (HPOS compatible)
                add_action('admin_init', array($this, 'add_admin_invoice_hooks'));

                // Overlay checkout scripts
                add_action('wp_enqueue_scripts', array($this, 'enqueue_overlay_checkout_scripts'));
                
                // AJAX handler to clear checkout session URL
                add_action('wp_ajax_dodo_clear_checkout_session', array($this, 'ajax_clear_checkout_session'));
                add_action('wp_ajax_nopriv_dodo_clear_checkout_session', array($this, 'ajax_clear_checkout_session'));
                
                // Clear session after payment completion
                add_action('woocommerce_thankyou_' . $this->id, array($this, 'clear_checkout_session_after_payment'), 5);

                // Add "Buy as Company" checkbox and company name field to checkout
                add_action('woocommerce_after_checkout_billing_form', array($this, 'add_buy_as_company_fields'));
                
                // Validate checkout fields
                add_action('woocommerce_checkout_process', array($this, 'validate_buy_as_company_fields'));
                
                // Save checkout fields to order meta
                add_action('woocommerce_checkout_update_order_meta', array($this, 'save_buy_as_company_fields'));
                
                // Enqueue checkout scripts for company fields
                add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_company_fields_script'));

                // Subscription-related actions
                if (class_exists('WC_Subscriptions')) {
                    add_action('woocommerce_subscription_status_updated', array($this, 'handle_subscription_status_updated'), 10, 3);
                    add_action('woocommerce_subscription_dates_updated', array($this, 'handle_subscription_date_change'), 10, 2);
                }
            }

            public function handle_subscription_status_updated($subscription, $new_status, $old_status)
            {
                if ($subscription->get_payment_method() !== $this->id) {
                    return;
                }

                switch ($new_status) {
                    case 'on-hold':
                        $this->suspend_subscription($subscription);
                        break;
                    case 'pending-cancel':
                        $this->cancel_subscription_at_next_billing_date($subscription);
                        break;
                    case 'cancelled':
                        $this->cancel_subscription($subscription);
                        break;
                    case 'expired':
                        // When a subscription expires, we should also cancel it in Dodo Payments
                        $this->cancel_subscription($subscription);
                        break;
                    case 'active':
                        if ($old_status === 'on-hold') {
                            $this->reactivate_subscription($subscription);
                        }
                        break;
                }
            }

            public function handle_subscription_date_change($subscription, $changed_dates)
            {
                if ($subscription->get_payment_method() !== $this->id) {
                    return;
                }

                $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
                if (!$dodo_subscription_id) {
                    return;
                }

                $subscription->add_order_note(__('Dodo Payments: Manual subscription date changes are not yet supported and will not be synced.', 'dodo-payments-for-woocommerce'));
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
                    'coupon_settings_title' => array(
                        'title' => __('Coupon Support', 'dodo-payments-for-woocommerce'),
                        'type' => 'title',
                        'description' => __('Configure coupon code synchronization with Dodo Payments.', 'dodo-payments-for-woocommerce'),
                    ),
                    'enable_coupons' => array(
                        'title' => __('Enable Coupon Support', 'dodo-payments-for-woocommerce'),
                        'type' => 'checkbox',
                        'label' => __('Sync WooCommerce coupons to Dodo Payments', 'dodo-payments-for-woocommerce'),
                        'default' => 'yes',
                        'desc_tip' => false,
                        'description' => __('When enabled, percentage-based coupon codes from WooCommerce will be automatically synced to Dodo Payments and applied during checkout. Only percentage discount coupons are supported.', 'dodo-payments-for-woocommerce'),
                    ),
                    'webhook_endpoint' => array(
                        'title' => __('Webhook Endpoint', 'dodo-payments-for-woocommerce'),
                        'type' => 'title',
                        'description' => $webhook_help_description,
                    )
                );
            }

            public function process_payment($order_id)
            {
                $order = wc_get_order($order_id);
                $order->update_status('pending-payment', __('Awaiting payment via Dodo Payments', 'dodo-payments-for-woocommerce'));
                wc_reduce_stock_levels($order_id);

                if ($order->get_total() == 0) {
                    $order->payment_complete();

                    WC()->cart->empty_cart();
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
                }

                $res = $this->do_payment($order);
                WC()->cart->empty_cart();
                return $res;
            }

            /**
             * Adds custom endpoint for viewing invoices
             *
             * @return void
             * @since 0.5.0
             */
            public function add_invoice_endpoint()
            {
                add_rewrite_endpoint('view-invoice', EP_ROOT | EP_PAGES);
                add_action('woocommerce_account_view-invoice_endpoint', array($this, 'view_invoice_endpoint_content'));
            }

            /**
             * Displays invoice download link on order details page
             *
             * @param WC_Order $order The WooCommerce order.
             * @return void
             * @since 0.5.0
             */
            public function display_invoice_link($order)
            {
                // Only show for Dodo Payments orders
                if ($order->get_payment_method() !== $this->id) {
                    return;
                }

                // Only show for logged-in users who own the order
                if (!is_user_logged_in() || !current_user_can('view_order', $order->get_id())) {
                    return;
                }

                // Initialize invoice helper
                $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
                $invoice_url = $invoice_helper->get_invoice_url($order);

                if (!$invoice_url) {
                    return; // No invoice available
                }

                // Display invoice link
                echo '<div class="dodo-payments-invoice-section" style="margin-top: 20px;">';
                echo '<h3>' . esc_html__('Invoice', 'dodo-payments-for-woocommerce') . '</h3>';
                echo '<p>';
                echo '<a href="' . esc_url($invoice_url) . '" target="_blank" class="button" style="margin-right: 10px;">';
                echo esc_html__('View Invoice', 'dodo-payments-for-woocommerce');
                echo '</a>';
                echo '</p>';
                echo '</div>';
            }

            /**
             * Serves PDF invoice securely with permission checks
             *
             * @return void
             * @since 0.5.0
             */
            public function serve_invoice_pdf()
            {
                // Check if this is an invoice request
                if (!isset($_GET['dodo_invoice']) || empty($_GET['dodo_invoice'])) {
                    return;
                }

                $payment_id = sanitize_text_field(wp_unslash($_GET['dodo_invoice']));
                
                if (empty($payment_id)) {
                    status_header(400);
                    exit;
                }

                // Find order by payment_id (use original payment_id, not sanitized)
                $order_id = Dodo_Payments_Payment_DB::get_order_id($payment_id);
                
                if (!$order_id) {
                    status_header(404);
                    exit;
                }

                $order = wc_get_order($order_id);
                
                if (!$order) {
                    status_header(404);
                    exit;
                }

                // Verify permissions: user must own the order or be admin
                $can_view = false;
                
                if (is_user_logged_in()) {
                    // Check if user owns the order
                    if (current_user_can('view_order', $order_id)) {
                        $can_view = true;
                    }
                    // Check if user is admin/shop manager
                    if (current_user_can('manage_woocommerce')) {
                        $can_view = true;
                    }
                }
                
                if (!$can_view) {
                    status_header(403);
                    exit;
                }

                // Verify this is a Dodo Payments order
                if ($order->get_payment_method() !== $this->id) {
                    status_header(403);
                    exit;
                }

                // Get PDF file path
                $upload_dir = wp_upload_dir();
                if ($upload_dir['error']) {
                    status_header(500);
                    exit;
                }

                // Sanitize payment ID for filename (must match save_pdf_invoice() logic)
                $sanitized_payment_id = sanitize_file_name($payment_id);
                $pdf_path = $upload_dir['basedir'] . '/dodo-invoices/' . $sanitized_payment_id . '.pdf';

                if (!file_exists($pdf_path) || !is_readable($pdf_path)) {
                    // File doesn't exist - try to regenerate from API
                    $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
                    $regenerated_url = $invoice_helper->get_invoice_for_payment($payment_id);
                    
                    if ($regenerated_url && strpos($regenerated_url, 'dodo_invoice=' . urlencode($payment_id)) !== false) {
                        // File should now exist, try again
                        if (file_exists($pdf_path) && is_readable($pdf_path)) {
                            // Continue to serve PDF
                        } else {
                            status_header(404);
                            exit;
                        }
                    } else {
                        status_header(404);
                        exit;
                    }
                }

                // Clear any output buffering to ensure headers are sent properly
                if (ob_get_level()) {
                    ob_end_clean();
                }

                // Serve PDF with proper headers
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="invoice-' . esc_attr($sanitized_payment_id) . '.pdf"');
                header('Content-Length: ' . filesize($pdf_path));
                header('Cache-Control: private, max-age=3600');
                
                // Output PDF content
                $readfile_result = @readfile($pdf_path);
                
                if ($readfile_result === false) {
                    status_header(500);
                    exit;
                }
                
                exit;
            }

            /**
             * Handles the view-invoice endpoint content
             *
             * @return void
             * @since 0.5.0
             */
            public function view_invoice_endpoint_content()
            {
                global $wp;
                
                // Get order ID from query var
                $order_id = isset($wp->query_vars['view-invoice']) ? absint($wp->query_vars['view-invoice']) : 0;
                
                if (!$order_id) {
                    wc_add_notice(__('Invalid order ID.', 'dodo-payments-for-woocommerce'), 'error');
                    wp_safe_redirect(wc_get_page_permalink('myaccount'));
                    exit;
                }

                $order = wc_get_order($order_id);

                if (!$order) {
                    wc_add_notice(__('Order not found.', 'dodo-payments-for-woocommerce'), 'error');
                    wp_safe_redirect(wc_get_page_permalink('myaccount'));
                    exit;
                }

                // Verify user owns the order
                if (!current_user_can('view_order', $order_id)) {
                    wc_add_notice(__('You do not have permission to view this invoice.', 'dodo-payments-for-woocommerce'), 'error');
                    wp_safe_redirect(wc_get_page_permalink('myaccount'));
                    exit;
                }

                // Only allow for Dodo Payments orders
                if ($order->get_payment_method() !== $this->id) {
                    wc_add_notice(__('This order does not use Dodo Payments.', 'dodo-payments-for-woocommerce'), 'error');
                    wp_safe_redirect(wc_get_account_endpoint_url('orders'));
                    exit;
                }

                // Get invoice URL
                $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
                $invoice_url = $invoice_helper->get_invoice_url($order);

                if (!$invoice_url) {
                    wc_add_notice(__('Invoice not available for this order.', 'dodo-payments-for-woocommerce'), 'error');
                    wp_safe_redirect($order->get_view_order_url());
                    exit;
                }

                // Redirect to invoice URL
                wp_safe_redirect($invoice_url);
                exit;
            }

            /**
             * Adds admin hooks for invoice display (HPOS compatible)
             *
             * @return void
             * @since 0.5.0
             */
            public function add_admin_invoice_hooks()
            {
                // Only add hooks in admin area
                if (!is_admin()) {
                    return;
                }

                // Check if HPOS is enabled
                $hpos_enabled = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') 
                    && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

                if ($hpos_enabled) {
                    // HPOS: Add column to orders table
                    add_filter('manage_woocommerce_page_wc-orders_columns', array($this, 'add_invoice_column'), 20);
                    add_action('woocommerce_shop_order_list_table_columns', array($this, 'render_invoice_column'), 20);
                } else {
                    // Legacy: Add column to orders table
                    add_filter('manage_shop_order_posts_columns', array($this, 'add_invoice_column'), 20);
                    add_action('manage_shop_order_posts_custom_column', array($this, 'render_invoice_column_legacy'), 20, 2);
                }

                // Add invoice section to order edit page (works for both HPOS and legacy)
                add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_admin_invoice_section'), 10, 1);
            }

            /**
             * Adds invoice column to orders table
             *
             * @param array $columns Existing columns.
             * @return array Modified columns.
             * @since 0.5.0
             */
            public function add_invoice_column($columns)
            {
                // Insert invoice column after order number
                $new_columns = array();
                foreach ($columns as $key => $value) {
                    $new_columns[$key] = $value;
                    if ($key === 'order_number') {
                        $new_columns['dodo_invoice'] = __('Invoice', 'dodo-payments-for-woocommerce');
                    }
                }
                // If order_number doesn't exist, add at the end
                if (!isset($new_columns['dodo_invoice'])) {
                    $new_columns['dodo_invoice'] = __('Invoice', 'dodo-payments-for-woocommerce');
                }
                return $new_columns;
            }

            /**
             * Renders invoice column content for HPOS orders table
             *
             * @param string $column Column name.
             * @return void
             * @since 0.5.0
             */
            public function render_invoice_column($column)
            {
                if ($column !== 'dodo_invoice') {
                    return;
                }

                // Get order from global context (HPOS)
                // WooCommerce sets $the_order global when rendering each row
                global $the_order;
                
                $order = null;
                
                // Try to get order from global first
                if ($the_order instanceof WC_Order) {
                    $order = $the_order;
                } else {
                    // Fallback: try to get from list table object
                    global $wp_list_table;
                    if (isset($wp_list_table) && method_exists($wp_list_table, 'get_current_order')) {
                        $order = $wp_list_table->get_current_order();
                    }
                }
                
                if (!$order instanceof WC_Order) {
                    echo '—';
                    return;
                }

                $this->render_invoice_column_content($order);
            }

            /**
             * Renders invoice column content for legacy orders table
             *
             * @param string $column Column name.
             * @param int $order_id Order ID.
             * @return void
             * @since 0.5.0
             */
            public function render_invoice_column_legacy($column, $order_id)
            {
                if ($column !== 'dodo_invoice') {
                    return;
                }

                $order = wc_get_order($order_id);
                if (!$order) {
                    return;
                }

                $this->render_invoice_column_content($order);
            }

            /**
             * Renders invoice column content (shared for HPOS and legacy)
             *
             * @param WC_Order $order Order object.
             * @return void
             * @since 0.5.0
             */
            private function render_invoice_column_content($order)
            {
                // Only show for Dodo Payments orders
                if ($order->get_payment_method() !== $this->id) {
                    echo '—';
                    return;
                }

                $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
                $invoice_url = $invoice_helper->get_invoice_url($order);

                if ($invoice_url) {
                    echo '<a href="' . esc_url($invoice_url) . '" target="_blank" class="button button-small" title="' . esc_attr__('View Invoice', 'dodo-payments-for-woocommerce') . '">';
                    echo '<span class="dashicons dashicons-media-document" style="font-size: 16px; line-height: 1.2;"></span>';
                    echo '</a>';
                } else {
                    echo '<span class="dashicons dashicons-minus" style="color: #999;" title="' . esc_attr__('Invoice not available', 'dodo-payments-for-woocommerce') . '"></span>';
                }
            }

            /**
             * Displays invoice section on order edit page (HPOS compatible)
             *
             * @param WC_Order $order Order object.
             * @return void
             * @since 0.5.0
             */
            public function display_admin_invoice_section($order)
            {
                // Only show for Dodo Payments orders
                if ($order->get_payment_method() !== $this->id) {
                    return;
                }

                $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
                $invoice_url = $invoice_helper->get_invoice_url($order);

                if (!$invoice_url) {
                    return;
                }

                ?>
                <div class="order_data_column" style="clear: both; width: 100%; margin-top: 20px;">
                    <h3><?php esc_html_e('Dodo Payments Invoice', 'dodo-payments-for-woocommerce'); ?></h3>
                    <p class="form-field">
                        <a href="<?php echo esc_url($invoice_url); ?>" target="_blank" class="button button-primary">
                            <span class="dashicons dashicons-media-document" style="vertical-align: middle; margin-right: 5px;"></span>
                            <?php esc_html_e('View Invoice', 'dodo-payments-for-woocommerce'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }

            /**
             * Enqueues overlay checkout scripts on checkout page
             *
             * @return void
             * @since 0.5.0
             */
            public function enqueue_overlay_checkout_scripts()
            {
                // Only enqueue on checkout page when overlay checkout is enabled
                if (!is_checkout() || !$this->enable_overlay_checkout) {
                    return;
                }

                // Ensure WooCommerce is loaded
                if (!function_exists('WC') || !WC()->session) {
                    return;
                }

                // Check if we have a checkout session URL from multiple sources
                $checkout_session_url = null;
                
                // Priority 1: Check URL parameter (for redirect back to checkout)
                if (isset($_GET['dodo_checkout_session_url'])) {
                    $checkout_session_url = sanitize_text_field(wp_unslash($_GET['dodo_checkout_session_url']));
                    // Store in session for persistence
                    if ($checkout_session_url && WC()->session) {
                        WC()->session->set('dodo_checkout_session_url', $checkout_session_url);
                    }
                }
                
                // Priority 2: Check WooCommerce session (stored during payment processing)
                if (!$checkout_session_url && WC()->session) {
                    $checkout_session_url = WC()->session->get('dodo_checkout_session_url');
                }
                
                // Priority 3: Try to get from order being processed
                if (!$checkout_session_url && WC()->session && WC()->session->get('order_awaiting_payment')) {
                    $order_id = WC()->session->get('order_awaiting_payment');
                    $order = wc_get_order($order_id);
                    if ($order && $order->get_payment_method() === $this->id) {
                        $checkout_session_url = $order->get_meta('_dodo_checkout_session_url');
                        // Store in session for next page load
                        if ($checkout_session_url && WC()->session) {
                            WC()->session->set('dodo_checkout_session_url', $checkout_session_url);
                        }
                    }
                }
                
                // Priority 4: Check recent orders for this user (fallback)
                if (!$checkout_session_url && is_user_logged_in()) {
                    $recent_orders = wc_get_orders(array(
                        'customer' => get_current_user_id(),
                        'limit' => 1,
                        'orderby' => 'date',
                        'order' => 'DESC',
                        'payment_method' => $this->id,
                        'status' => 'pending',
                    ));
                    
                    if (!empty($recent_orders)) {
                        $order = reset($recent_orders);
                        $checkout_session_url = $order->get_meta('_dodo_checkout_session_url');
                        // Store in session for next page load
                        if ($checkout_session_url && WC()->session) {
                            WC()->session->set('dodo_checkout_session_url', $checkout_session_url);
                        }
                    }
                }

                if (!$checkout_session_url) {
                    // Log for debugging (only in debug mode)
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('Dodo Payments: Overlay checkout script not enqueued - no checkout session URL found');
                    }
                    return; // No checkout session URL available
                }

                // Enqueue Dodo Payments Checkout SDK
                wp_enqueue_script(
                    'dodo-payments-checkout-sdk',
                    'https://cdn.jsdelivr.net/npm/dodopayments-checkout@latest/dist/index.js',
                    array(),
                    null,
                    true
                );

                // Enqueue our overlay checkout script
                wp_enqueue_script(
                    'dodo-checkout-overlay',
                    plugins_url('/assets/js/dodo-checkout-overlay.min.js', __FILE__),
                    array('dodo-payments-checkout-sdk', 'jquery'),
                    '0.5.0',
                    true
                );

                // Pass data to JavaScript
                wp_localize_script(
                    'dodo-checkout-overlay',
                    'dodoCheckoutOverlay',
                    array(
                        'checkoutUrl' => $checkout_session_url,
                        'mode' => $this->testmode ? 'test' : 'live',
                        'ajaxUrl' => admin_url('admin-ajax.php'),
                        'nonce' => wp_create_nonce('dodo_checkout_overlay'),
                    )
                );
            }

            /**
             * AJAX handler to clear checkout session URL from WooCommerce session
             *
             * @return void
             * @since 0.5.0
             */
            public function ajax_clear_checkout_session()
            {
                check_ajax_referer('dodo_checkout_overlay', 'nonce');
                
                if (WC()->session) {
                    WC()->session->__unset('dodo_checkout_session_url');
                }
                
                wp_send_json_success();
            }

            /**
             * Clears checkout session URL after payment completion
             *
             * @param int $order_id Order ID.
             * @return void
             * @since 0.5.0
             */
            public function clear_checkout_session_after_payment($order_id)
            {
                if (WC()->session) {
                    WC()->session->__unset('dodo_checkout_session_url');
                }
            }

            /**
             * Adds "Buy as Company" checkbox and company name field to checkout form
             *
             * @return void
             * @since 0.6.0
             */
            public function add_buy_as_company_fields()
            {
                // Get checkout object
                $checkout = WC()->checkout();
                
                echo '<div id="buy_as_company_fields">';
                // Add toggle switch for "Buy as company"
                echo '<div class="form-row form-row-wide dodo-toggle-wrapper">';
                echo '<label class="dodo-toggle-label">';
                echo '<input type="checkbox" name="buy_as_company_checkbox" id="buy_as_company_checkbox" class="dodo-toggle-input" value="1" ' . checked($checkout->get_value('buy_as_company_checkbox'), true, false) . '>';
                echo '<span class="dodo-toggle-slider"></span>';
                echo '<span class="dodo-toggle-text">' . esc_html__('Purchasing as a business', 'dodo-payments-for-woocommerce') . '</span>';
                echo '</label>';
                echo '</div>';

                woocommerce_form_field('custom_company_name', array(
                    'type'        => 'text',
                    'class'       => array('form-row-wide'),
                    'label'       => __('Company Name', 'dodo-payments-for-woocommerce'),
                    'required'    => false,
                    'placeholder' => __('Enter company name', 'dodo-payments-for-woocommerce'),
                ), $checkout->get_value('custom_company_name'));

                // Add informational text about tax ID
                echo '<p class="form-row form-row-wide dodo-tax-id-info" style="font-size: 0.9em; margin-top: -10px; margin-bottom: 15px;">';
                echo esc_html__('You can enter your Tax ID on the payment page by selecting "Purchasing as a business".', 'dodo-payments-for-woocommerce');
                echo '</p>';

                echo '</div>';
                
                // Add inline CSS for toggle switch styling and initially hide company name field
                echo '<style type="text/css">
                    /* Toggle Switch Styles */
                    .dodo-toggle-wrapper {
                        margin-bottom: 20px;
                    }
                    .dodo-toggle-label {
                        display: flex;
                        align-items: center;
                        cursor: pointer;
                        user-select: none;
                    }
                    .dodo-toggle-text {
                        margin-left: 12px;
                    }
                    .dodo-toggle-input {
                        position: absolute;
                        opacity: 0;
                        width: 0;
                        height: 0;
                    }
                    .dodo-toggle-slider {
                        position: relative;
                        display: inline-block;
                        width: 36px;
                        height: 20px;
                        background-color: #ccc;
                        border-radius: 26px;
                        transition: background-color 0.3s ease;
                    }
                    .dodo-toggle-slider:before {
                        content: "";
                        position: absolute;
                        height: 16px;
                        width: 16px;
                        left: 2px;
                        bottom: 2px;
                        background-color: white;
                        border-radius: 50%;
                        transition: transform 0.3s ease;
                    }
                    .dodo-toggle-input:checked + .dodo-toggle-slider {
                        background-color: #01824c;
                    }
                    .dodo-toggle-input:checked + .dodo-toggle-slider:before {
                        transform: translateX(16px);
                    }
                    
                    /* Hide company name field initially */
                    #custom_company_name_field { display: none; margin-top: -12px; }
                    .dodo-tax-id-info { display: none; }
                </style>';
            }

            /**
             * Validates "Buy as Company" fields during checkout
             *
             * @return void
             * @since 0.6.0
             */
            public function validate_buy_as_company_fields()
            {
                $buy_as_company = isset($_POST['buy_as_company_checkbox']) && $_POST['buy_as_company_checkbox'];
                $custom_company_name = isset($_POST['custom_company_name']) ? sanitize_text_field($_POST['custom_company_name']) : '';

                if ($buy_as_company && empty($custom_company_name)) {
                    $default_company = isset($_POST['billing_company']) ? sanitize_text_field($_POST['billing_company']) : '';
                    if (empty($default_company)) {
                        wc_add_notice(__('Company name is required when "Buy as Company" is checked.', 'dodo-payments-for-woocommerce'), 'error');
                    }
                }
            }

            /**
             * Saves "Buy as Company" fields to order meta
             *
             * @param int $order_id The order ID.
             * @return void
             * @since 0.6.0
             */
            public function save_buy_as_company_fields($order_id)
            {
                $buy_as_company = isset($_POST['buy_as_company_checkbox']) && $_POST['buy_as_company_checkbox'] ? 'yes' : 'no';
                $custom_company_name = isset($_POST['custom_company_name']) ? sanitize_text_field($_POST['custom_company_name']) : '';

                $order = wc_get_order($order_id);
                if ($order) {
                    $order->update_meta_data('_buy_as_company_checkbox', $buy_as_company);
                    if (!empty($custom_company_name)) {
                        $order->update_meta_data('_custom_company_name', $custom_company_name);
                    }
                    $order->save();
                }
            }

            /**
             * Enqueues JavaScript for company fields toggle functionality
             *
             * @return void
             * @since 0.6.0
             */
            public function enqueue_checkout_company_fields_script()
            {
                if (!is_checkout()) {
                    return;
                }

                wp_enqueue_script(
                    'dodo-checkout-company-fields',
                    plugins_url('/assets/js/dodo-checkout-company-fields.min.js', __FILE__),
                    array('jquery'),
                    '0.6.0',
                    true
                );
            }

            public function thank_you_page()
            {
                if ($this->instructions) {
                    echo wp_kses_post(wpautop(wptexturize($this->instructions)));
                }
            }

            /**
             * Capture payment ID and subscription ID from return URL after checkout session completion
             * 
             * This is essential for checkout sessions flow. When customers complete payment,
             * Dodo redirects them back with payment_id and status as query parameters.
             * We need to save this mapping so webhooks can find the order later.
             * 
             * Following Dodo Payments best practices for handling return_url parameters.
             * 
             * @return void
             * @since 0.4.0
             */
            public function capture_payment_id_from_return()
            {
                // Only run on order received page
                if (!is_wc_endpoint_url('order-received')) {
                    return;
                }
                
                // Get order ID from URL
                global $wp;
                $order_id = absint($wp->query_vars['order-received']);
                
                if (!$order_id) {
                    return;
                }
                
                $order = wc_get_order($order_id);
                
                if (!$order || $order->get_payment_method() !== $this->id) {
                    return;
                }
                
                // Check if this was a checkout session order (has session_id stored)
                $session_id = $order->get_meta('_dodo_checkout_session_id');
                
                if (!$session_id) {
                    return; // Not a checkout session order, skip
                }
                
                // Get payment_id from URL parameters (Dodo includes this in return_url)
                // Following Dodo documentation: return_url receives payment_id and status parameters
                $payment_id = isset($_GET['payment_id']) ? sanitize_text_field(wp_unslash($_GET['payment_id'])) : '';
                
                if (!$payment_id) {
                    return; // No payment_id in URL yet
                }
                
                // Check if already mapped (prevent duplicate entries)
                $existing_order_id = Dodo_Payments_Payment_DB::get_order_id($payment_id);
                
                if ($existing_order_id) {
                    return; // Already mapped, nothing to do
                }
                
                // Save the payment ID mapping for webhook processing
                Dodo_Payments_Payment_DB::save_mapping($order_id, $payment_id);
                
                $order->add_order_note(
                    sprintf(
                        // translators: %1$s: Payment ID
                        __('Payment ID captured from checkout session return: %1$s', 'dodo-payments-for-woocommerce'),
                        $payment_id
                    )
                );
                
                // Also check for subscription_id if this is a subscription order
                $subscription_id = isset($_GET['subscription_id']) ? sanitize_text_field(wp_unslash($_GET['subscription_id'])) : '';
                
                if ($subscription_id && class_exists('WC_Subscriptions') && function_exists('wcs_get_subscriptions_for_order')) {
                    $subscription_orders = wcs_get_subscriptions_for_order($order_id);
                    
                    if (!empty($subscription_orders)) {
                        $subscription = reset($subscription_orders);
                        
                        // Check if already mapped
                        $existing_wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($subscription_id);
                        
                        if (!$existing_wc_subscription_id) {
                            Dodo_Payments_Subscription_DB::save_mapping($subscription->get_id(), $subscription_id);
                            
                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: Subscription ID
                                    __('Subscription ID captured from checkout session return: %1$s', 'dodo-payments-for-woocommerce'),
                                    $subscription_id
                                )
                            );
                        }
                    }
                }
            }

            public function do_payment($order)
            {
                // Validate API key is configured
                if (empty($this->api_key)) {
                    $mode = $this->testmode ? 'Test' : 'Live';
                    $error_msg = sprintf(
                        // translators: %1$s: Mode (Test or Live)
                        __('Dodo Payments %1$s API Key is not configured. Please configure it in WooCommerce > Settings > Payments > Dodo Payments.', 'dodo-payments-for-woocommerce'),
                        $mode
                    );
                    
                    $order->add_order_note($error_msg);
                    wc_add_notice($error_msg, 'error');
                    
                    return array('result' => 'failure');
                }
                
                // Check if order contains subscription products
                $contains_subscription = $this->order_contains_subscription($order);

                try {
                    $synced_products = $this->sync_products($order);

                    /** @var string[] */
                    $coupons = $order->get_coupon_codes();
                    $dodo_discount_code = null;

                    // Only process coupons if coupon support is enabled
                    if ($this->enable_coupons) {
                        if (count($coupons) > 1) {
                            $message = __('Dodo Payments: Multiple Coupon codes are not supported.', 'dodo-payments-for-woocommerce');
                            $order->add_order_note($message);
                            wc_add_notice($message, 'error');

                            return array('result' => 'failure');
                        }

                        if (count($coupons) == 1) {
                            $coupon_code = $coupons[0];

                            try {
                                $dodo_discount_code = $this->sync_coupon($coupon_code);
                            } catch (Dodo_Payments_Cart_Exception $e) {
                                wc_add_notice($e->getMessage(), 'error');

                                return array('result' => 'failure');
                            } catch (Exception $e) {
                                $order->add_order_note(
                                    sprintf(
                                        // translators: %1$s: Error message
                                        __('Dodo Payments Error: %1$s', 'dodo-payments-for-woocommerce'),
                                        $e->getMessage()
                                    )
                                );
                                wc_add_notice(__('Dodo Payments: an unexpected error occured.', 'dodo-payments-for-woocommerce'), 'error');

                                return array('result' => 'failure');
                            }
                        }
                    } else {
                        // Coupon support is disabled - log if coupons were applied
                        if (count($coupons) > 0) {
                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$d: Number of coupons
                                    __('Dodo Payments: %1$d coupon(s) applied in WooCommerce but not synced to Dodo Payments (coupon support is disabled).', 'dodo-payments-for-woocommerce'),
                                    count($coupons)
                                )
                            );
                        }
                    }

                    // Use checkout sessions API when tax ID collection is enabled OR overlay checkout is enabled
                    // This provides a modern checkout experience with additional features
                    if ($this->enable_tax_id_collection || $this->enable_overlay_checkout) {
                        // Let Dodo Payments automatically handle payment method filtering based on location and currency
                        $response = $this->dodo_payments_api->create_checkout_session(
                            $order,
                            $synced_products,
                            $dodo_discount_code,
                            $this->get_return_url($order),
                            $this->enable_tax_id_collection, // enable tax ID collection
                            null // Let Dodo handle payment method filtering automatically
                        );
                    } else {
                        // Use legacy payment/subscription API for backward compatibility
                        $response = $contains_subscription
                            ? $this->dodo_payments_api->create_subscription(
                                $order,
                                $synced_products,
                                $dodo_discount_code,
                                $this->get_return_url($order)
                            )
                            : $this->dodo_payments_api->create_payment(
                                $order,
                                $synced_products,
                                $dodo_discount_code,
                                $this->get_return_url($order)
                            );
                    }
                } catch (Exception $e) {
                    $error_message = $e->getMessage();
                    
                    $order->add_order_note(
                        sprintf(
                            // translators: %1$s: Error message
                            __('Dodo Payments Error: %1$s', 'dodo-payments-for-woocommerce'),
                            $error_message
                        )
                    );
                    
                    // Log the error for debugging
                    error_log('Dodo Payments Error for Order #' . $order->get_id() . ': ' . $error_message);
                    
                    // Show user-friendly error message
                    wc_add_notice(
                        sprintf(
                            // translators: %1$s: Error message
                            __('Payment processing failed: %1$s', 'dodo-payments-for-woocommerce'),
                            $error_message
                        ),
                        'error'
                    );

                    return array('result' => 'failure');
                }

                // Handle both checkout session and legacy payment/subscription responses
                if ($this->enable_tax_id_collection || $this->enable_overlay_checkout) {
                    // Handle Checkout Session response
                    if (isset($response['checkout_url']) && isset($response['session_id'])) {
                        // Store the session ID for future reference
                        $order->update_meta_data('_dodo_checkout_session_id', $response['session_id']);
                        
                        // If overlay checkout is enabled, store checkout URL and redirect to checkout page
                        if ($this->enable_overlay_checkout) {
                            $order->update_meta_data('_dodo_checkout_session_url', $response['checkout_url']);
                            $order->save();

                            // Store checkout session URL in WooCommerce session for script enqueue
                            if (WC()->session) {
                                WC()->session->set('dodo_checkout_session_url', $response['checkout_url']);
                            }

                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: Session ID
                                    __('Checkout session created in Dodo Payments: %1$s (Overlay checkout enabled)', 'dodo-payments-for-woocommerce'),
                                    $response['session_id']
                                )
                            );

                            // Redirect to checkout page where overlay will open
                            return array(
                                'result' => 'success',
                                'redirect' => add_query_arg('dodo_checkout_session_url', urlencode($response['checkout_url']), wc_get_checkout_url())
                            );
                        } else {
                            // Standard redirect to external checkout URL
                            $order->save();

                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: Session ID
                                    __('Checkout session created in Dodo Payments: %1$s (Tax ID collection enabled)', 'dodo-payments-for-woocommerce'),
                                    $response['session_id']
                                )
                            );

                            return array(
                                'result' => 'success',
                                'redirect' => $response['checkout_url']
                            );
                        }
                    } else {
                        $order->add_order_note(
                            __('Failed to create checkout session in Dodo Payments: Invalid response', 'dodo-payments-for-woocommerce')
                        );
                        return array('result' => 'failure');
                    }
                } else {
                    // Handle legacy payment and subscription responses
                    if ($contains_subscription) {
                        if (isset($response['payment_link'])) {
                            if (isset($response['subscription_id'])) {
                                // Save the subscription mapping
                                $subscription_order = wcs_get_subscriptions_for_order($order->get_id());
                                if (!empty($subscription_order)) {
                                    $subscription = reset($subscription_order);
                                    Dodo_Payments_Subscription_DB::save_mapping($subscription->get_id(), $response['subscription_id']);

                                    $order->add_order_note(
                                        sprintf(
                                            // translators: %1$s: Subscription ID
                                            __('Subscription created in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                            $response['subscription_id']
                                        )
                                    );
                                }
                            }

                            if (isset($response['payment_id'])) {
                                // Save the payment mapping
                                Dodo_Payments_Payment_DB::save_mapping($order->get_id(), $response['payment_id']);

                                $order->add_order_note(
                                    sprintf(
                                        // translators: %1$s: Payment ID
                                        __('Payment created in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                        $response['payment_id']
                                    )
                                );
                            }


                            return array(
                                'result' => 'success',
                                'redirect' => $response['payment_link']
                            );
                        } else {
                            $order->add_order_note(
                                __('Failed to create subscription in Dodo Payments: Invalid response', 'dodo-payments-for-woocommerce')
                            );
                            return array('result' => 'failure');
                        }
                    } else {
                        if (isset($response['payment_link']) && isset($response['payment_id'])) {
                            // Save the payment mapping
                            Dodo_Payments_Payment_DB::save_mapping($order->get_id(), $response['payment_id']);

                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: Payment ID
                                    __('Payment created in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                    $response['payment_id']
                                )
                            );

                            return array(
                                'result' => 'success',
                                'redirect' => $response['payment_link']
                            );
                        } else {
                            $order->add_order_note(
                                __('Failed to create payment in Dodo Payments: Invalid response', 'dodo-payments-for-woocommerce')
                            );
                            return array('result' => 'failure');
                        }
                    }
                }
            }

            /**
             * Syncs products from WooCommerce to Dodo Payments
             *
             * @param \WC_Order $order
             * @return array{amount: mixed, product_id: string, quantity: mixed}[]
             *
             * @since 0.1.0
             */
            private function sync_products($order)
            {
                $items = $order->get_items();
                $mapped_products = array();

                foreach ($items as $item) {
                    $product = $item->get_product();
                    $local_product_id = $product->get_id();

                    // Check if this is a subscription product
                    $is_subscription = false;
                    if (class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::is_subscription($product)) {
                        $is_subscription = true;
                    }

                    // Check if product is already mapped
                    $dodo_product_id = Dodo_Payments_Product_DB::get_dodo_product_id($local_product_id);
                    $dodo_product = null;

                    if ($dodo_product_id) {
                        $dodo_product = $this->dodo_payments_api->get_product($dodo_product_id);

                        // If product not found in Dodo (404), clear the stale mapping
                        if (!$dodo_product) {
                            $stale_dodo_product_id = $dodo_product_id; // Store before clearing
                            error_log("Dodo Payments: Auto-recovery - Product mapping stale for WC Product #{$local_product_id} (Dodo Product {$stale_dodo_product_id} not found). Clearing stale mapping and will re-create product on checkout.");
                            Dodo_Payments_Product_DB::delete_mapping($local_product_id);
                            $dodo_product_id = null; // Force re-creation
                            
                            // Add order note for transparency
                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: WooCommerce product ID, %2$s: Dodo product ID
                                    __('Auto-recovery: Stale product mapping cleared for WC Product #%1$s (Dodo Product %2$s not found). Product will be re-created automatically.', 'dodo-payments-for-woocommerce'),
                                    $local_product_id,
                                    $stale_dodo_product_id
                                )
                            );
                        } else {
                            try {
                                if ($is_subscription) {
                                    $this->dodo_payments_api->update_subscription_product($dodo_product['product_id'], $product);
                                } else {
                                    $this->dodo_payments_api->update_product($dodo_product['product_id'], $product);
                                }
                            } catch (Exception $e) {
                                $order->add_order_note(
                                    sprintf(
                                        // translators: %1$s: Error message
                                        __('Failed to update product in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                        $e->getMessage(),
                                    )
                                );

                                continue;
                            }
                        }
                    }

                    if (!$dodo_product_id || !$dodo_product) {
                        try {
                            if ($is_subscription) {
                                $response_body = $this->dodo_payments_api->create_subscription_product($product);
                            } else {
                                $response_body = $this->dodo_payments_api->create_product($product);
                            }
                        } catch (Exception $e) {
                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: Error message
                                    __('Dodo Payments Error: %1$s', 'dodo-payments-for-woocommerce'),
                                    $e->getMessage(),
                                )
                            );

                            continue;
                        }

                        $dodo_product_id = $response_body['product_id'];
                        // Save the mapping
                        Dodo_Payments_Product_DB::save_mapping($local_product_id, $dodo_product_id);

                        // sync image to dodo payments
                        try {
                            $this->dodo_payments_api->sync_image_for_product($product, $dodo_product_id);
                        } catch (Exception $e) {
                            $order->add_order_note(
                                sprintf(
                                    // translators: %1$s: Error message
                                    __('Failed to sync image for product in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                    $e->getMessage(),
                                )
                            );
                        }
                    }

                    $mapped_products[] = array(
                        'product_id' => $dodo_product_id,
                        'quantity' => $item->get_quantity(),
                        'amount' => (int) $product->get_price() * 100
                    );
                }

                return $mapped_products;
            }

            /**
             * Syncs a coupon from WooCommerce to Dodo Payments
             *
             * @param string $coupon_code
             * @return string Dodo Payments discount code
             * @throws Dodo_Payments_Cart_Exception If the coupon is not a percentage discount code
             * @throws Exception If the coupon could not be synced
             *
             * @since 0.2.0
             */
            private function sync_coupon($coupon_code)
            {
                $coupon = new WC_Coupon($coupon_code);
                $coupon_type = $coupon->get_discount_type();

                // TODO: support more discount types later on
                if ($coupon_type !== 'percent') {
                    throw new Dodo_Payments_Cart_Exception('Dodo Payments: Only percentage discount codes are supported.');
                }

                $dodo_discount_id = Dodo_Payments_Coupon_DB::get_dodo_coupon_id($coupon->get_id());
                $dodo_discount = null;

                $dodo_discount_code = null;

                $dodo_discount_req_body = self::wc_coupon_to_dodo_discount_body($coupon);

                if ($dodo_discount_id) {
                    $dodo_discount = $this->dodo_payments_api->get_discount_code($dodo_discount_id);

                    if (!!$dodo_discount) {
                        $dodo_discount = $this->dodo_payments_api->update_discount_code($dodo_discount_id, $dodo_discount_req_body);
                        $dodo_discount_code = $dodo_discount['code'];
                    }
                }

                if (!$dodo_discount_id || !$dodo_discount) {
                    // FIXME: This will not work if the discount code already exists with a different id
                    // need to find a way to get the id of the existing discount code
                    $dodo_discount = $this->dodo_payments_api->create_discount_code($dodo_discount_req_body);

                    $dodo_discount_id = $dodo_discount['discount_id'];
                    $dodo_discount_code = $dodo_discount['code'];

                    // Save the mapping
                    Dodo_Payments_Coupon_DB::save_mapping($coupon->get_id(), $dodo_discount_id);
                }

                return $dodo_discount_code;
            }

            private static function wc_coupon_to_dodo_discount_body($coupon)
            {
                $coupon_amount = (int) $coupon->get_amount() * 100;
                /** @var int|null */
                $usage_limit = $coupon->get_usage_limit() > 0 ? (int) $coupon->get_usage_limit() : null;

                /** @var string[] */
                $product_ids = $coupon->get_product_ids();

                $dodo_product_ids = array();
                foreach ($product_ids as $product_id) {
                    $dodo_product_id = Dodo_Payments_Product_DB::get_dodo_product_id($product_id);

                    if ($dodo_product_id) {
                        array_push($dodo_product_ids, $dodo_product_id);
                    }
                }

                /** @var string[]|null */
                $restricted_to = count($dodo_product_ids) > 0 ? $dodo_product_ids : null;
                /** @var string|null */
                $expires_at = $coupon->get_date_expires() ? (string) $coupon->get_date_expires() : null;

                return array(
                    'type' => 'percentage',
                    'code' => $coupon->get_code(),
                    'amount' => $coupon_amount,
                    'expires_at' => $expires_at,
                    'usage_limit' => $usage_limit,
                    'restricted_to' => $restricted_to,
                );
            }

            private function get_base_url()
            {
                return $this->testmode ? 'https://test.dodopayments.com' : 'https://live.dodopayments.com';
            }

            /**
             * Cancels a subscription when cancelled in WooCommerce
             *
             * @param WC_Subscription $subscription
             * @return void
             * @since 0.3.0
             */
            public function cancel_subscription($subscription)
            {
                $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

                if (!$dodo_subscription_id) {
                    $subscription->add_order_note(__('No Dodo Payments subscription ID found for cancellation.', 'dodo-payments-for-woocommerce'));
                    return;
                }

                try {
                    $this->dodo_payments_api->cancel_subscription($dodo_subscription_id);
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Subscription ID
                            __('Subscription cancelled in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $dodo_subscription_id
                        )
                    );
                } catch (Exception $e) {
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Error message
                            __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $e->getMessage()
                        )
                    );
                }
            }

            /**
             * Cancels a subscription at next billing date in WooCommerce
             *
             * @param WC_Subscription $subscription
             * @return void
             * @since 0.3.0
             */
            public function cancel_subscription_at_next_billing_date($subscription)
            {
                $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

                if (!$dodo_subscription_id) {
                    $subscription->add_order_note(__('No Dodo Payments subscription ID found for cancellation.', 'dodo-payments-for-woocommerce'));
                    return;
                }

                try {
                    $this->dodo_payments_api->cancel_subscription_at_next_billing_date($dodo_subscription_id);
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Subscription ID
                            __('Subscription scheduled for cancellation at next billing date in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $dodo_subscription_id
                        )
                    );
                } catch (Exception $e) {
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Error message
                            __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $e->getMessage()
                        )
                    );
                }
            }

            /**
             * Suspends a subscription in Dodo Payments
             *
             * @param WC_Subscription $subscription
             * @return void
             * @since 0.3.0
             */
            public function suspend_subscription($subscription)
            {
                $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

                if (!$dodo_subscription_id) {
                    $subscription->add_order_note(__('No Dodo Payments subscription ID found for suspension.', 'dodo-payments-for-woocommerce'));
                    return;
                }

                try {
                    $this->dodo_payments_api->pause_subscription($dodo_subscription_id);
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Subscription ID
                            __('Subscription paused in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $dodo_subscription_id
                        )
                    );
                } catch (Exception $e) {
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Error message
                            __('Failed to pause subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $e->getMessage()
                        )
                    );
                }
            }

            /**
             * Reactivates a subscription when activated in WooCommerce
             *
             * @param WC_Subscription $subscription
             * @return void
             * @since 0.3.0
             */
            public function reactivate_subscription($subscription)
            {
                $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

                if (!$dodo_subscription_id) {
                    $subscription->add_order_note(__('No Dodo Payments subscription ID found for reactivation.', 'dodo-payments-for-woocommerce'));
                    return;
                }

                try {
                    $this->dodo_payments_api->resume_subscription($dodo_subscription_id);
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Subscription ID
                            __('Subscription resumed in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $dodo_subscription_id
                        )
                    );
                } catch (Exception $e) {
                    $subscription->add_order_note(
                        sprintf(
                            // translators: %1$s: Error message
                            __('Failed to resume subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                            $e->getMessage()
                        )
                    );
                }
            }

            /**
             * Utility method to check if an order contains subscription products
             *
             * @param WC_Order $order
             * @return bool
             * @since 0.3.0
             */
            private function order_contains_subscription($order)
            {
                if (function_exists('wcs_order_contains_subscription')) {
                    return wcs_order_contains_subscription($order);
                }

                // Fallback check for subscription products
                if (class_exists('WC_Subscriptions_Product')) {
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        if ($product && WC_Subscriptions_Product::is_subscription($product)) {
                            return true;
                        }
                    }
                }

                return false;
            }

            /**
             * Handles webhook notifications from Dodo Payments
             * 
             * This method processes webhooks from both:
             * - Legacy payment/subscription API (when tax ID collection is disabled)
             * - Checkout Sessions API (when tax ID collection is enabled)
             * 
             * Both approaches fire the same webhook events (payment.succeeded, subscription.active, etc.)
             * so the same handler logic works for both flows.
             * 
             * @return void
             * @since 0.3.0
             */
            public function webhook()
            {
                $headers = [
                    'webhook-signature' => isset($_SERVER['HTTP_WEBHOOK_SIGNATURE']) ? sanitize_text_field($_SERVER['HTTP_WEBHOOK_SIGNATURE']) : '',
                    'webhook-id' => isset($_SERVER['HTTP_WEBHOOK_ID']) ? sanitize_text_field($_SERVER['HTTP_WEBHOOK_ID']) : '',
                    'webhook-timestamp' => isset($_SERVER['HTTP_WEBHOOK_TIMESTAMP']) ? sanitize_text_field($_SERVER['HTTP_WEBHOOK_TIMESTAMP']) : '',
                ];

                $body = sanitize_text_field(file_get_contents('php://input'));

                try {
                    $webhook = new Dodo_Payments_Standard_Webhook($this->webhook_key);
                } catch (\Exception $e) {
                    error_log('Dodo Payments: Invalid webhook key: ' . $e->getMessage());
                    if ($this->testmode) {
                        status_header(401);
                    } else {
                        $this->consume_webhook_silently();
                    }
                    return;
                }

                try {
                    $payload = $webhook->verify($body, $headers);
                } catch (Exception $e) {
                    error_log('Dodo Payments: Could not verify webhook event: ' . $e->getMessage());
                    if ($this->testmode) {
                        status_header(401);
                    } else {
                        $this->consume_webhook_silently();
                    }
                    return;
                }

                // Can be
                $type = $payload['type'];
                $type_parts = explode('.', $type, 2);

                if (count($type_parts) !== 2) {
                    error_log('Dodo Payments: Invalid webhook event type format: ' . $type);
                    if ($this->testmode) {
                        status_header(400);
                    } else {
                        $this->consume_webhook_silently();
                    }
                    return;
                }

                $kind = $type_parts[0];
                $status = $type_parts[1];

                switch ($kind) {
                    case 'payment':
                        $this->handle_payment_webhook($payload, $status);
                        break;
                    case 'refund':
                        $this->handle_refund_webhook($payload, $status);
                        break;
                    case 'subscription':
                        $this->handle_subscription_webhook($payload, $status);
                        break;
                    default:
                        // Handle other webhook types if needed
                        break;
                }

                $this->consume_webhook_silently();
            }

            /**
             * Handle payment webhook events
             *
             * Following Dodo Payments best practices for webhook handling:
             * 1. Check metadata first (most reliable, eliminates race conditions)
             * 2. Check payment_id mapping (secondary method)
             * 3. Fallback to session_id search (tertiary method for legacy/edge cases)
             *
             * @param array $payload
             * @param string $status
             * @return void
             */
            private function handle_payment_webhook($payload, $status)
            {
                $payment_id = $payload['data']['payment_id'];
                $order_id = null;
                $order = null; // Will be set if retrieved during metadata check

                // Method 1: Extract order_id from metadata (most reliable, eliminates race conditions)
                // Metadata is included in checkout session creation and available in webhook payload
                if (isset($payload['data']['metadata']['wc_order_id'])) {
                    $metadata_order_id = absint($payload['data']['metadata']['wc_order_id']);
                    
                    // Verify the order exists and is valid
                    if ($metadata_order_id) {
                        $order = wc_get_order($metadata_order_id);
                        if ($order && $order->get_payment_method() === $this->id) {
                            $order_id = $metadata_order_id;
                            // Save payment_id mapping for future webhooks (if not already mapped)
                            $existing_order_id = Dodo_Payments_Payment_DB::get_order_id($payment_id);
                            if (!$existing_order_id) {
                                Dodo_Payments_Payment_DB::save_mapping($order_id, $payment_id);
                            }
                        } else {
                            $order = null; // Invalid order, continue to next method
                        }
                    }
                }

                // Method 2: Check payment_id mapping (for legacy orders or direct payments)
                if (!$order_id) {
                    $order_id = Dodo_Payments_Payment_DB::get_order_id($payment_id);
                }

                // Method 3: Fallback to session_id search (handles race conditions)
                // This should rarely be needed if metadata is properly set
                if (!$order_id && isset($payload['data']['checkout_session_id'])) {
                    $session_id = $payload['data']['checkout_session_id'];
                    
                    // Search for order with this session_id in meta
                    $orders = wc_get_orders(array(
                        'limit' => 1,
                        'meta_key' => '_dodo_checkout_session_id',
                        'meta_value' => $session_id,
                        'return' => 'ids',
                    ));
                    
                    if (!empty($orders)) {
                        $order_id = $orders[0];
                        // Save the payment_id mapping for future webhooks
                        Dodo_Payments_Payment_DB::save_mapping($order_id, $payment_id);
                        
                        // Only log in debug mode to reduce noise
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("Dodo Payments: Found order #{$order_id} via session ID fallback, saved payment mapping");
                        }
                    }
                }

                // Final check: Log error only if order truly cannot be found
                if (!$order_id) {
                    error_log('Dodo Payments: Could not find order_id for payment: ' . $payment_id . ' (checked metadata, payment_id mapping, and session_id)');
                    return;
                }

                // Get the order object (reuse if already retrieved from metadata check)
                if (!$order || $order->get_id() !== $order_id) {
                    $order = wc_get_order($order_id);
                }

                if (!$order) {
                    error_log('Dodo Payments: Could not find order: ' . $order_id);
                    return;
                }

                switch ($status) {
                    case 'succeeded':
                        $order->payment_complete($payment_id);
                        $order->update_status('completed', __('Payment completed by Dodo Payments', 'dodo-payments-for-woocommerce'));

                        if (isset($payload['data']['subscription_id'])) {
                            $subscription_id = $payload['data']['subscription_id'];
                            $wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($subscription_id);

                            if (function_exists('wcs_get_subscription')) {
                                $subscription = wcs_get_subscription($wc_subscription_id);
                            }

                            if (!$subscription) {
                                error_log(
                                    'Dodo Payments: Could not find WooCommerce subscription '
                                    . $wc_subscription_id
                                    . ' for subscription ID '
                                    . $subscription_id
                                );
                                return;
                            }

                            $dodo_subscription = $this->dodo_payments_api->get_subscription($subscription_id);

                            $this->create_renewal_order($subscription, $payment_id);
                        }

                        break;

                    case 'failed':
                        $order->update_status('failed', __('Payment failed by Dodo Payments', 'dodo-payments-for-woocommerce'));
                        wc_increase_stock_levels($order_id);
                        break;

                    case 'cancelled':
                        $order->update_status('cancelled', __('Payment cancelled by Dodo Payments', 'dodo-payments-for-woocommerce'));
                        wc_increase_stock_levels($order_id);
                        break;

                    case 'processing':
                    default:
                        $order->update_status('processing', __('Payment processing by Dodo Payments', 'dodo-payments-for-woocommerce'));
                        break;
                }
            }

            /**
             * Handle refund webhook events
             *
             * @param array $payload
             * @param string $status
             * @return void
             */
            private function handle_refund_webhook($payload, $status)
            {
                $payment_id = $payload['data']['payment_id'];
                $order_id = Dodo_Payments_Payment_DB::get_order_id($payment_id);

                if (!$order_id) {
                    error_log('Dodo Payments: Could not find order for payment: ' . $payment_id);
                    return;
                }

                $order = wc_get_order($order_id);

                if (!$order) {
                    error_log('Dodo Payments: Could not find order: ' . $order_id);
                    return;
                }

                $order->add_order_note(
                    sprintf(
                        // translators: %1$s: Webhook type
                        __('Refund webhook received from Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                        $payload['type']
                    )
                );

                switch ($status) {
                    case 'succeeded':
                        $order->update_status('refunded', __('Payment refunded by Dodo Payments', 'dodo-payments-for-woocommerce'));

                        $order->add_order_note(
                            sprintf(
                                // translators: %1$s: Payment ID, %2$s: Refund ID
                                __('Refunded payment in Dodo Payments. Payment ID: %1$s, Refund ID: %2$s', 'dodo-payments-for-woocommerce'),
                                $payment_id,
                                $payload['data']['refund_id']
                            )
                        );
                        break;

                    case 'failed':
                        $order->add_order_note(
                            sprintf(
                                // translators: %1$s: Payment ID, %2$s: Refund ID
                                __('Refund failed in Dodo Payments. Payment ID: %1$s, Refund ID: %2$s', 'dodo-payments-for-woocommerce'),
                                $payment_id,
                                $payload['data']['refund_id']
                            )
                        );
                        break;
                }
            }

            /**
             * Handle subscription webhook events
             *
             * @param array $payload
             * @param string $status
             * @return void
             */
            private function handle_subscription_webhook($payload, $status)
            {
                if (!class_exists('WC_Subscriptions')) {
                    return;
                }

                $subscription_id = $payload['data']['subscription_id'];
                $wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($subscription_id);

                if (!$wc_subscription_id) {
                    error_log('Dodo Payments: Could not find WooCommerce subscription for Dodo subscription: ' . $subscription_id);
                    return;
                }

                $subscription = wcs_get_subscription($wc_subscription_id);

                if (!$subscription) {
                    error_log('Dodo Payments: Could not find WooCommerce subscription: ' . $wc_subscription_id);
                    return;
                }

                switch ($status) {
                    case 'active':
                        $subscription->update_status(
                            'active',
                            sprintf(
                                // translators: %1$s: Subscription ID
                                __('Subscription activated by Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                $subscription_id
                            )
                        );
                        break;

                    case 'renewed':
                        $subscription->add_order_note(
                            __('Subscription renewed by Dodo Payments', 'dodo-payments-for-woocommerce')
                        );
                        // doesn't do anything yet
                        $this->handle_subscription_renewal($subscription);
                        break;

                    case 'on_hold':
                    case 'paused':
                        $subscription->update_status('on-hold', __('Subscription paused by Dodo Payments', 'dodo-payments-for-woocommerce'));
                        break;

                    case 'cancelled':
                        $subscription->update_status('cancelled', __('Subscription cancelled by Dodo Payments', 'dodo-payments-for-woocommerce'));
                        break;

                    case 'failed':
                        $subscription->update_status('on-hold', __('Subscription payment failed in Dodo Payments', 'dodo-payments-for-woocommerce'));
                        break;

                    case 'expired':
                        $subscription->update_status('expired', __('Subscription expired in Dodo Payments', 'dodo-payments-for-woocommerce'));
                        break;

                    default:
                        $subscription->add_order_note(
                            sprintf(
                                // translators: %1$s: Webhook type
                                __('Subscription webhook received from Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                $payload['type']
                            )
                        );
                        break;
                }
            }

            /**
             * Handle subscription renewal
             *
             * @param WC_Subscription $subscription
             * @return void
             */
            private function handle_subscription_renewal($subscription)
            {
                // Does nothing as we're handling renewal from the 'payment.succeeded' webhook
                // TODO: handle renewal from the 'subscription.renewed' webhook when
                // it includes the `payment_id` and pass it to `$order->payment_complete($payment_id)`.
                // This will help the merchant link the renewal order to the payment ID.
            }

            /**
             * Create a renewal order for a subscription
             *
             * @param WC_Subscription $subscription
             * @param string $payment_id
             * @return void
             */
            private function create_renewal_order($subscription, $payment_id)
            {
                if (function_exists('wcs_create_renewal_order')) {
                    $renewal_order = wcs_create_renewal_order($subscription);
                    if ($renewal_order) {
                        $renewal_order->payment_complete($payment_id);
                        $renewal_order->set_payment_method(wc_get_payment_gateway_by_order($subscription));

                        $renewal_order->update_status('completed');
                        $subscription->add_order_note(__('Subscription renewed by Dodo Payments', 'dodo-payments-for-woocommerce'));
                    }
                }
            }

            /**
             * Consume webhook silently by setting 200 status
             *
             * @return void
             */
            private function consume_webhook_silently()
            {
                status_header(200);
            }
        }
    }
}

add_filter('woocommerce_payment_gateways', 'dodo_payments_add_gateway_class_to_woo');
function dodo_payments_add_gateway_class_to_woo($gateways)
{
    $gateways[] = 'Dodo_Payments_WC_Gateway';
    return $gateways;
}
