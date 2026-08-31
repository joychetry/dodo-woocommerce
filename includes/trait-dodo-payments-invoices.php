<?php
/**
 * Invoice display: My Account invoice endpoint, admin order columns (HPOS + legacy), and invoice links.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Invoice display: My Account invoice endpoint, admin order columns (HPOS + legacy), and invoice links.
 *
 * @since 0.7.0
 */
trait DodoPaymentsInvoices
{

public function add_invoice_endpoint()
{
    add_rewrite_endpoint('view-invoice', EP_ROOT | EP_PAGES);
    add_action('woocommerce_account_view-invoice_endpoint', array($this, 'view_invoice_endpoint_content'));
}

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
 * Adds invoice column to My Account orders table
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 * @since 0.5.0
 */
public function add_myaccount_invoice_column($columns)
{
    $columns['invoice'] = __('Invoice', 'dodo-payments-for-woocommerce');
    return $columns;
}

/**
 * Renders invoice column content in My Account orders table
 *
 * Note: This action receives order ID (not WC_Order object) in WooCommerce.
 *
 * @param mixed $order_or_order_id The order object or order ID.
 * @return void
 * @since 0.5.0
 */
public function render_myaccount_invoice_column($order_or_order_id)
{
    // Handle both order object and order ID for compatibility
    if (!is_object($order_or_order_id)) {
        $order = wc_get_order($order_or_order_id);
    } else {
        $order = $order_or_order_id;
    }

    if (!$order instanceof WC_Order) {
        echo '—';
        return;
    }

    // Only show for Dodo Payments orders
    if ($order->get_payment_method() !== $this->id) {
        echo '—';
        return;
    }

    $invoice_helper = new Dodo_Payments_Invoice($this->dodo_payments_api);
    $invoice_url = $invoice_helper->get_invoice_url($order);

    if ($invoice_url) {
        echo '<a href="' . esc_url($invoice_url) . '" target="_blank" class="button button-small" title="' . esc_attr__('View Invoice', 'dodo-payments-for-woocommerce') . '">';
        echo esc_html__('View', 'dodo-payments-for-woocommerce');
        echo '</a>';
    } else {
        echo '<span style="color: #999;">' . esc_html__('N/A', 'dodo-payments-for-woocommerce') . '</span>';
    }
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
        add_action('woocommerce_shop_order_list_table_column_content', array($this, 'render_invoice_column_hpos'), 20, 2);
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
 * @param WC_Order $order The order object.
 * @param string $column Column name.
 * @return void
 * @since 0.5.0
 */
public function render_invoice_column_hpos($order, $column)
{
    if ($column !== 'dodo_invoice') {
        return;
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
}
