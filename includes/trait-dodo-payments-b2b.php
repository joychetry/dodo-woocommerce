<?php
/**
 * B2B checkout: "Purchasing as a business" fields, validation, and saving for tax ID collection.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * B2B checkout: "Purchasing as a business" fields, validation, and saving for tax ID collection.
 *
 * @since 0.7.0
 */
trait DodoPaymentsB2b
{

public function add_buy_as_company_fields()
{
    // Get checkout object
    $checkout = WC()->checkout();

    // Prefill from saved user meta; POST/WC session wins, and a saved 'no' never re-checks the box.
    $user_id = get_current_user_id();
    $buy_as_company = $checkout->get_value('buy_as_company_checkbox');
    $company_name   = $checkout->get_value('custom_company_name');
    $tax_id         = $checkout->get_value('dodo_tax_id');
    if ($user_id) {
        if (empty($buy_as_company) && 'yes' === get_user_meta($user_id, '_dodo_buy_as_company', true)) {
            $buy_as_company = '1';
        }
        if (empty($company_name)) {
            $company_name = get_user_meta($user_id, '_dodo_custom_company_name', true);
        }
        if (empty($tax_id)) {
            $tax_id = get_user_meta($user_id, '_dodo_tax_id', true);
        }
    }

    echo '<div id="buy_as_company_fields">';
    // Add toggle switch for "Buy as company"
    echo '<div class="form-row form-row-wide dodo-toggle-wrapper">';
    echo '<label class="dodo-toggle-label">';
    echo '<input type="checkbox" name="buy_as_company_checkbox" id="buy_as_company_checkbox" class="dodo-toggle-input" value="1" ' . checked($buy_as_company, '1', false) . '>';
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
    ), $company_name);

    woocommerce_form_field('dodo_tax_id', array(
        'type'        => 'text',
        'class'       => array('form-row-wide'),
        'label'       => __('Tax ID (VAT/GST)', 'dodo-payments-for-woocommerce'),
        'required'    => false,
        'placeholder' => __('Enter Tax ID, e.g. VAT number or GST number', 'dodo-payments-for-woocommerce'),
    ), $tax_id);

    echo '</div>';

    // Add nonce for security verification
    wp_nonce_field('dodo_save_purchase_as_business', 'dodo_purchase_as_business_nonce');

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
        
        /* Hide company name and tax ID fields initially */
        #custom_company_name_field { display: none; margin-top: -12px; }
        #dodo_tax_id_field { display: none; margin-top: -8px; }
        /* Hide the (optional) suffix on our fields */
        #dodo_tax_id_field .optional, #custom_company_name_field .optional { display: none; }
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
    // Verify nonce for security - fail loudly if missing or invalid
    if (!isset($_POST['dodo_purchase_as_business_nonce'])) {
        wc_add_notice(__('Security verification failed. Please try again.', 'dodo-payments-for-woocommerce'), 'error');
        return;
    }

    $nonce = sanitize_text_field(wp_unslash($_POST['dodo_purchase_as_business_nonce']));
    if (!wp_verify_nonce($nonce, 'dodo_save_purchase_as_business')) {
        wc_add_notice(__('Security verification failed. Please try again.', 'dodo-payments-for-woocommerce'), 'error');
        return;
    }

    $buy_as_company = isset($_POST['buy_as_company_checkbox']) && '1' === sanitize_text_field(wp_unslash($_POST['buy_as_company_checkbox']));
    $custom_company_name = isset($_POST['custom_company_name']) ? trim(sanitize_text_field(wp_unslash($_POST['custom_company_name']))) : '';
    $tax_id = isset($_POST['dodo_tax_id']) ? trim(sanitize_text_field(wp_unslash($_POST['dodo_tax_id']))) : '';

    if ($buy_as_company) {
        $default_company = isset($_POST['billing_company']) ? trim(sanitize_text_field(wp_unslash($_POST['billing_company']))) : '';
        if (empty($custom_company_name) && empty($default_company)) {
            wc_add_notice(__('Company name is required when "Buy as Company" is checked.', 'dodo-payments-for-woocommerce'), 'error');
        }
        if (empty($tax_id)) {
            wc_add_notice(__('Tax ID (VAT/GST) is required when "Buy as Company" is checked.', 'dodo-payments-for-woocommerce'), 'error');
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
    // Verify nonce for security - log failures for audit
    if (!isset($_POST['dodo_purchase_as_business_nonce']) ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dodo_purchase_as_business_nonce'])), 'dodo_save_purchase_as_business')) {
        // Log this security event - validation should have caught this first
        $this->log_debug('Security: Nonce verification failed in save_buy_as_company_fields for order ' . $order_id);
        return;
    }

    $buy_as_company = isset($_POST['buy_as_company_checkbox']) && '1' === sanitize_text_field(wp_unslash($_POST['buy_as_company_checkbox'])) ? 'yes' : 'no';
    $custom_company_name = isset($_POST['custom_company_name']) ? trim(sanitize_text_field(wp_unslash($_POST['custom_company_name']))) : '';
    $tax_id = isset($_POST['dodo_tax_id']) ? trim(sanitize_text_field(wp_unslash($_POST['dodo_tax_id']))) : '';

    $order = wc_get_order($order_id);
    if ($order) {
        $order->update_meta_data('_buy_as_company_checkbox', $buy_as_company);
        // Company/tax meta only on B2B orders; fields stay populated when toggled off,
        // so without this gate a non-business order would record them.
        if ('yes' === $buy_as_company) {
            if (!empty($custom_company_name)) {
                $order->update_meta_data('_custom_company_name', $custom_company_name);
            }
            if (!empty($tax_id)) {
                $order->update_meta_data('_dodo_tax_id', $tax_id);
            }
        }
        $order->save();
    }

    // Remember the choices on the customer's account so the fields are
    // prefilled on their next checkout instead of re-entered every time.
    $user_id = get_current_user_id();
    if ($user_id) {
        update_user_meta($user_id, '_dodo_buy_as_company', $buy_as_company);
        if (!empty($custom_company_name)) {
            update_user_meta($user_id, '_dodo_custom_company_name', $custom_company_name);
        }
        if (!empty($tax_id)) {
            update_user_meta($user_id, '_dodo_tax_id', $tax_id);
        }
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
        DODO_PAYMENTS_PLUGIN_URL . 'assets/js/dodo-checkout-company-fields.min.js',
        array('jquery'),
        '0.6.0',
        true
    );
}

}
