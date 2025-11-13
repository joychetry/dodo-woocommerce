<?php

/**
 * Dodo Payments Invoice Helper Class
 *
 * Handles invoice URL retrieval and caching for WooCommerce orders.
 *
 * @since 0.5.0
 */
class Dodo_Payments_Invoice
{
    /**
     * Dodo Payments API instance
     *
     * @var Dodo_Payments_API
     */
    private $dodo_payments_api;

    /**
     * Constructor
     *
     * @param Dodo_Payments_API $dodo_payments_api The API instance to use for invoice retrieval.
     */
    public function __construct($dodo_payments_api)
    {
        $this->dodo_payments_api = $dodo_payments_api;
    }

    /**
     * Gets the invoice URL for a WooCommerce order.
     *
     * Checks order meta for cached invoice URL first, then retrieves from API if needed.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return string|false The invoice URL if found, or false on error.
     */
    public function get_invoice_url($order)
    {
        // Check if invoice URL is already cached
        $cached_url = $order->get_meta('_dodo_invoice_url');
        if (!empty($cached_url)) {
            // Verify the cached URL points to a valid endpoint
            // (The endpoint will verify file existence and permissions)
            return $cached_url;
        }

        // Get payment ID from order
        $payment_id = $this->get_payment_id_from_order($order);
        
        if (!$payment_id) {
            return false;
        }

        // Fetch invoice URL from API
        $invoice_url = $this->dodo_payments_api->get_payment_invoice($payment_id);
        
        if ($invoice_url) {
            // Cache the invoice URL in order meta
            $order->update_meta_data('_dodo_invoice_url', $invoice_url);
            $order->save();
        }

        return $invoice_url;
    }

    /**
     * Gets invoice URL directly from payment ID.
     *
     * @param string $payment_id The Dodo Payments payment ID.
     * @return string|false The invoice URL if found, or false on error.
     */
    public function get_invoice_for_payment($payment_id)
    {
        return $this->dodo_payments_api->get_payment_invoice($payment_id);
    }

    /**
     * Retrieves the Dodo Payments payment ID from a WooCommerce order.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return string|false The payment ID if found, or false.
     */
    private function get_payment_id_from_order($order)
    {
        // Try to get payment ID from database mapping first
        $order_id = $order->get_id();
        $payment_id = Dodo_Payments_Payment_DB::get_dodo_payment_id($order_id);
        
        if ($payment_id) {
            return $payment_id;
        }

        // Fallback: check order meta (for legacy orders or direct storage)
        $payment_id = $order->get_meta('_dodo_payment_id');
        if (!empty($payment_id)) {
            return $payment_id;
        }

        return false;
    }
}

