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
        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
        $gateway = $gateways[$this->name] ?? null;

        return array(
            'title'       => $this->get_setting('title'),
            // Rendered as HTML in the block, like classic checkout's payment_fields().
            'description' => wp_kses_post($this->get_setting('description')),
            'icon'        => DODO_PAYMENTS_PLUGIN_URL . 'assets/logo.png',
            'supports'    => $gateway ? array_values(array_filter($gateway->supports, array($gateway, 'supports'))) : array('products'),
        );
    }
}
