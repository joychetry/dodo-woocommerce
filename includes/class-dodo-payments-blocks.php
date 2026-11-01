<?php
/**
 * WooCommerce checkout block integration for Dodo Payments.
 *
 * Payment itself runs through the gateway's classic process_payment(), which the
 * Store API calls and whose redirect it follows; this class only exposes the method
 * to the block.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * @since 1.1.0
 */
final class Dodo_Payments_Blocks extends AbstractPaymentMethodType
{
    /**
     * Must match the gateway's $this->id.
     *
     * @var string
     */
    protected $name = 'dodo_payments';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_dodo_payments_settings', []);
    }

    public function is_active()
    {
        return 'yes' === $this->get_setting('enabled');
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'dodo-payments-blocks',
            DODO_PAYMENTS_PLUGIN_URL . 'assets/js/dodo-blocks.js',
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'),
            '1.1.0',
            true
        );
        return array('dodo-payments-blocks');
    }

    public function get_payment_method_data()
    {
        $gateway = WC()->payment_gateways()->payment_gateways()[$this->name];

        return array(
            'title'       => $this->get_setting('title'),
            // Rendered as HTML in the block. get_description() applies kses and the
            // woocommerce_gateway_description filter like classic; wpautop() is skipped
            // because it inserts <br> between inline markup such as card icons.
            'description' => $gateway->get_description(),
            'icon'        => DODO_PAYMENTS_PLUGIN_URL . 'assets/logo.png',
            'supports'    => array_values(array_filter($gateway->supports, array($gateway, 'supports'))),
        );
    }

    /**
     * Block-checkout version of the classic "Purchasing as a business" fields
     * (DodoPaymentsB2b). Contact location, so WooCommerce saves them on the
     * customer and prefills them next time for logged-in buyers.
     */
    public static function register_b2b_fields()
    {
        $settings = get_option('woocommerce_dodo_payments_settings', []);
        if (!function_exists('woocommerce_register_additional_checkout_field')
            || 'yes' !== ($settings['enable_tax_id_collection'] ?? 'no')) {
            return;
        }

        // JSON-schema rules WooCommerce evaluates against the checkout (WC 9.9+).
        $toggle_on = array('const' => true);
        $when      = function ($fields, $billing_company_empty = false) {
            $customer = array(
                'additional_fields' => array(
                    'properties' => array('dodo/buy-as-company' => $fields),
                    'required'   => array('dodo/buy-as-company'),
                ),
            );
            if ($billing_company_empty) {
                $customer['billing_address'] = array('properties' => array('company' => array('const' => '')));
            }
            return array('customer' => array('properties' => $customer));
        };

        woocommerce_register_additional_checkout_field(array(
            'id'       => 'dodo/buy-as-company',
            'label'    => __('Purchasing as a business', 'dodo-payments-for-woocommerce'),
            'location' => 'contact',
            'type'     => 'checkbox',
        ));
        woocommerce_register_additional_checkout_field(array(
            'id'       => 'dodo/company-name',
            'label'    => __('Company Name', 'dodo-payments-for-woocommerce'),
            'location' => 'contact',
            'hidden'   => $when(array('not' => $toggle_on)),
            // Same rule as classic: required only when the billing company is empty.
            'required' => $when($toggle_on, true),
        ));
        woocommerce_register_additional_checkout_field(array(
            'id'       => 'dodo/tax-id',
            'label'    => __('Tax ID (VAT/GST)', 'dodo-payments-for-woocommerce'),
            'location' => 'contact',
            'hidden'   => $when(array('not' => $toggle_on)),
            'required' => $when($toggle_on),
        ));
    }

    /**
     * Copy the block fields into the order meta the classic fields use, so
     * Dodo_Payments_API reads both checkouts the same way.
     *
     * @param WC_Order $order Order being built by the Store API.
     */
    public static function copy_b2b_fields_to_order($order)
    {
        $fields = \Automattic\WooCommerce\Blocks\Package::container()
            ->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);
        if (!$fields->is_field('dodo/buy-as-company')) {
            return;
        }

        $is_b2b = (bool) $fields->get_field_from_object('dodo/buy-as-company', $order, 'other');
        $order->update_meta_data('_buy_as_company_checkbox', $is_b2b ? 'yes' : 'no');

        // Drafts are updated on every attempt; clear values left by an earlier B2B attempt.
        $map = array('dodo/company-name' => '_custom_company_name', 'dodo/tax-id' => '_dodo_tax_id');
        foreach ($map as $field => $meta_key) {
            $value = $is_b2b ? trim((string) $fields->get_field_from_object($field, $order, 'other')) : '';
            if ('' !== $value) {
                $order->update_meta_data($meta_key, $value);
            } else {
                $order->delete_meta_data($meta_key);
            }
        }
    }
}
