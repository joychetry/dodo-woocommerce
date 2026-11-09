<?php
/**
 * Invoice display: PDF download handler, admin order columns (HPOS + legacy), and invoice links.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Invoice display: PDF download handler, admin order columns (HPOS + legacy), and invoice links.
 *
 * @since 0.7.0
 */
trait DodoPaymentsInvoices
{

/**
 * Flushes rewrite rules if flagged on plugin activation
 *
 * @return void
 * @since 0.5.0
 */
public function maybe_flush_rewrite_rules()
{
    if (get_option('dodo_payments_flush_rewrite_rules')) {
        delete_option('dodo_payments_flush_rewrite_rules');
        flush_rewrite_rules();
    }
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
 * Streams the invoice PDF for an order (admin-post.php?action=dodo_payments_invoice&order_id=N).
 *
 * Runs for any logged-in user: customers may download invoices for their own orders,
 * shop managers for any order.
 *
 * @return void
 * @since 1.2.1
 */
public function download_invoice()
{
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    $order = $order_id ? wc_get_order($order_id) : false;

    if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id
        || !(current_user_can('view_order', $order_id) || current_user_can('edit_shop_orders'))) {
        wp_die(esc_html__('Invoice not found.', 'dodo-payments-for-woocommerce'), '', array('response' => 404));
    }

    $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
    $pdf = $invoice_helper->get_invoice_pdf($order);

    if (!$pdf) {
        wp_die(esc_html__('The invoice is not available yet. Please try again later.', 'dodo-payments-for-woocommerce'), '', array('response' => 502));
    }

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="invoice-' . $order->get_order_number() . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
    exit;
}

/**
 * Adds an Invoice link to an order's actions in the My Account orders list.
 *
 * @param array    $actions Existing order actions.
 * @param WC_Order $order   The order.
 * @return array Modified actions.
 * @since 1.2.2
 */
public function add_myaccount_invoice_action($actions, $order)
{
    $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
    $invoice_url = $invoice_helper->get_invoice_url($order);

    if ($invoice_url) {
        $actions['invoice'] = array(
            'url'  => $invoice_url,
            'name' => __('Invoice', 'dodo-payments-for-woocommerce'),
        );
    }

    return $actions;
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

    // Invoice PDF download for customers and admins (admin-post.php runs admin_init for any logged-in user).
    add_action('admin_post_dodo_payments_invoice', array($this, 'download_invoice'));

    // Invoice is exposed via the Actions column button below; no dedicated column.

    // Add an invoice action button to the Actions column (HPOS + legacy share this hook).
    add_filter('woocommerce_admin_order_actions', array($this, 'add_invoice_action'), 20, 2);

    // wc_render_action_buttons() doesn't support a 'target' key, so open the invoice
    // in a new tab via a tiny admin-footer script.
    add_action('admin_print_footer_scripts', array($this, 'print_invoice_button_target_script'));

    // Add invoice section to order edit page (works for both HPOS and legacy)
    add_action('woocommerce_admin_order_data_after_order_details', array($this, 'display_admin_invoice_section'), 10, 1);
}

/**
 * Opens the invoice action button in a new tab.
 *
 * @return void
 * @since 1.0.1
 */
public function print_invoice_button_target_script()
{
    ?>
<script>
(function() {
    var buttons = document.querySelectorAll('a.wc-action-button[title="View Dodo Payments invoice"]');
    for (var i = 0; i < buttons.length; i++) {
        buttons[i].setAttribute('target', '_blank');
    }
})();
</script>
<?php
}

/**
 * Adds an invoice action button to the order list Actions column.
 *
 * @param array    $actions Existing action buttons.
 * @param WC_Order $order   The order object.
 * @return array Modified actions.
 * @since 1.0.0
 */
public function add_invoice_action($actions, $order)
{
    if (!$order instanceof WC_Order || $order->get_payment_method() !== $this->id) {
        return $actions;
    }

    $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
    $invoice_url = $invoice_helper->get_invoice_url($order);

    if (!$invoice_url) {
        return $actions;
    }

    // 'view' renders a core WooCommerce action-button icon (dashicons-visibility);
    // a custom class like 'invoice' gets no ::after glyph and shows as a blank square.
    $actions['invoice'] = array(
        'url'    => $invoice_url,
        'name'   => __('Invoice', 'dodo-payments-for-woocommerce'),
        'action' => 'view',
        'title'  => __('View Dodo Payments invoice', 'dodo-payments-for-woocommerce'),
    );

    return $actions;
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
            <span class="dashicons dashicons-media-document"></span>
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
}