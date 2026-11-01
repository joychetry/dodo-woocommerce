<?php

/**
 * Dodo Payments Invoice Helper Class
 *
 * Builds invoice links for WooCommerce orders and downloads their invoice PDFs.
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
     * Gets the plugin URL that serves the invoice PDF for a WooCommerce order.
     *
     * Dodo's own invoice links need authentication, so customers and admins download the PDF
     * through the plugin, which checks their access to the order and fetches it with the API key.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return string|false The invoice URL, or false when the order has no paid Dodo payment.
     */
    public function get_invoice_url($order)
    {
        if (!$order->get_date_paid() || !$this->get_payment_id_from_order($order)) {
            return false;
        }

        return add_query_arg(
            array(
                'action'   => 'dodo_payments_invoice',
                'order_id' => $order->get_id(),
            ),
            admin_url('admin-post.php')
        );
    }

    /**
     * Downloads the invoice PDF for a WooCommerce order from Dodo Payments.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return string|false The PDF bytes, or false on error.
     */
    public function get_invoice_pdf($order)
    {
        $payment_id = $this->get_payment_id_from_order($order);

        return $payment_id ? $this->dodo_payments_api->get_payment_invoice_pdf($payment_id) : false;
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

