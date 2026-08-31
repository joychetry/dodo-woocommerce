<?php
/**
 * One-time payment flow: checkout sessions, legacy payment creation, product and coupon sync, and overlay checkout.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * One-time payment flow: checkout sessions, legacy payment creation, product and coupon sync, and overlay checkout.
 *
 * @since 0.7.0
 */
trait DodoPaymentsPayment
{

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
        $this->log_debug('Overlay checkout script not enqueued - no checkout session URL found');
        return; // No checkout session URL available
    }

    /**
     * Allow disabling the overlay checkout feature.
     *
     * By default, the overlay checkout is enabled. Set this filter to false
     * to use the traditional redirect-based checkout instead.
     *
     * @since 0.4.1
     * @param bool $enable_overlay Whether to enable overlay checkout. Default true.
     */
    $enable_overlay_checkout = apply_filters('dodo_payments_enable_overlay_checkout', true);

    if (!$enable_overlay_checkout) {
        return;
    }

    // Enqueue Dodo Payments Checkout SDK from official CDN.
    // This is the official SDK provided by Dodo Payments (the payment processor).
    // The SDK is required for the overlay checkout functionality to work.
    // The script is loaded from jsDelivr CDN which is the official distribution channel.
    // Security: The SDK only communicates with Dodo Payments servers and does not
    // execute arbitrary code on your site.
    // Note: External script loading is allowed for payment processor SDKs per WordPress.org guidelines.
    // An exception will be requested during plugin review if needed.
    wp_enqueue_script(
        'dodo-payments-checkout-sdk',
        'https://cdn.jsdelivr.net/npm/dodopayments-checkout@latest/dist/index.js',
        array(),
        '1.0.0', // Version for cache busting - will be updated with SDK releases
        true
    );

    // Enqueue our overlay checkout script
    wp_enqueue_script(
        'dodo-checkout-overlay',
        DODO_PAYMENTS_PLUGIN_URL . 'assets/js/dodo-checkout-overlay.min.js',
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
 * AJAX handler: extend trial period for a subscription.
 *
 * Expects POST parameters:
 *   - subscription_id (int) — WooCommerce subscription ID
 *   - days           (int) — Number of days to extend
 *   - nonce          (string) — WooCommerce admin nonce
 *
 * @return void
 * @since 0.7.0
 */
public function ajax_extend_trial_period()
{
    check_ajax_referer('dodo_admin_subscription', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(array('message' => __('Insufficient permissions.', 'dodo-payments-for-woocommerce')));
        return;
    }

    $subscription_id = isset($_POST['subscription_id']) ? absint($_POST['subscription_id']) : 0;
    $days = isset($_POST['days']) ? absint($_POST['days']) : 0;

    if (!$subscription_id || !$days) {
        wp_send_json_error(array('message' => __('Invalid parameters.', 'dodo-payments-for-woocommerce')));
        return;
    }

    $result = $this->extend_trial_period_admin($subscription_id, $days);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
        return;
    }

    wp_send_json_success(array('message' => sprintf(
        __('Trial extended by %d days.', 'dodo-payments-for-woocommerce'),
        $days
    )));
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
    
    if ($subscription_id) {
        $subscription_found = false;
        
        if (class_exists('WC_Subscriptions') && function_exists('wcs_get_subscriptions_for_order')) {
            $subscription_orders = wcs_get_subscriptions_for_order($order_id);
            
            if (!empty($subscription_orders)) {
                $subscription = reset($subscription_orders);
                $subscription_found = $subscription;
            }
        } else {
            // Check for License Monks subscriptions
            $lm_subscriptions = wc_get_orders(array(
                'type'   => 'lm_subscription',
                'parent' => $order_id,
                'limit'  => 1,
            ));
            if (!empty($lm_subscriptions)) {
                $subscription_found = reset($lm_subscriptions);
            }
        }
        
        if ($subscription_found) {
            // Check if already mapped
            $existing_wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($subscription_id);
            
            if (!$existing_wc_subscription_id) {
                Dodo_Payments_Subscription_DB::save_mapping($subscription_found->get_id(), $subscription_id);
                
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

    // Detect License Monks upgrade orders
    $upgrade_meta = null;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $meta = $item->get_meta('licensemonks_upgrade', true);
        if (!empty($meta) && !empty($meta['license_id'])) {
            $upgrade_meta = $meta;
            break;
        }
    }

    // Subscription upgrades: use Dodo's native changePlan API
    if ($upgrade_meta && $contains_subscription) {
        return $this->handle_subscription_upgrade($order, $upgrade_meta);
    }

    try {
        $synced_products = $this->sync_products($order);

        /** @var string[] */
        $coupons = $order->get_coupon_codes();
        $dodo_discount_code = null;

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

        // Use checkout sessions API when tax ID collection is enabled OR overlay checkout is enabled
        // This provides a modern checkout experience with additional features
        if ($this->enable_tax_id_collection || $this->enable_overlay_checkout) {
            $response = $this->dodo_payments_api->create_checkout_session(
                $order,
                $synced_products,
                $dodo_discount_code,
                $this->get_return_url($order),
                $this->enable_tax_id_collection,
                null
            );
        } else {
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
        $this->log_debug('Error for Order #' . $order->get_id() . ': ' . $error_message);

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
                    $subscription_found = false;

                    if (class_exists('WC_Subscriptions') && function_exists('wcs_get_subscriptions_for_order')) {
                        $subscription_order = wcs_get_subscriptions_for_order($order->get_id());
                        if (!empty($subscription_order)) {
                            $subscription_found = reset($subscription_order);
                        }
                    } else {
                        // Check for License Monks subscriptions
                        $lm_subscriptions = wc_get_orders(array(
                            'type'   => 'lm_subscription',
                            'parent' => $order->get_id(),
                            'limit'  => 1,
                        ));
                        if (!empty($lm_subscriptions)) {
                            $subscription_found = reset($lm_subscriptions);
                        }
                    }

                    if ($subscription_found) {
                        Dodo_Payments_Subscription_DB::save_mapping($subscription_found->get_id(), $response['subscription_id']);

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
 * Handles subscription upgrades using Dodo's native changePlan API.
 *
 * Bypasses sync_products() and checkout session creation entirely.
 * Dodo handles proration natively based on the selected billing mode.
 *
 * @param \WC_Order $order        The WooCommerce order.
 * @param array     $upgrade_meta The licensemonks_upgrade meta data.
 * @return array{result: string, redirect?: string}
 */
private function handle_subscription_upgrade($order, $upgrade_meta)
{
    try {
        // Find the existing Dodo subscription ID from the original license's order
        $license_id = absint($upgrade_meta['license_id']);

        // Look up WC subscription from original order via license
        $dodo_subscription_id = null;

        if (class_exists('LicenseMonks_License_Generator') && function_exists('wcs_get_subscriptions_for_order')) {
            $license = LicenseMonks_License_Generator::get_license($license_id);

            if (!$license) {
                throw new Exception(sprintf(
                    /* translators: %d: License ID */
                    __('License not found. License ID: %d', 'dodo-payments-for-woocommerce'),
                    $license_id
                ));
            }

            if (empty($license->order_id)) {
                throw new Exception(sprintf(
                    /* translators: %d: License ID */
                    __('License has no associated order. License ID: %d', 'dodo-payments-for-woocommerce'),
                    $license_id
                ));
            }

            // Verify license ownership - ensure current user owns this license
            $license_user_id = isset($license->user_id) ? absint($license->user_id) : 0;
            $current_user_id = get_current_user_id();

            if ($license_user_id > 0 && $current_user_id > 0 && $license_user_id !== $current_user_id) {
                // Check if current user has capability to upgrade licenses (e.g., shop manager)
                if (!current_user_can('manage_woocommerce')) {
                    throw new Exception(__('You do not have permission to upgrade this license.', 'dodo-payments-for-woocommerce'));
                }
            }

            $subscriptions = wcs_get_subscriptions_for_order($license->order_id);
            if (empty($subscriptions)) {
                throw new Exception(sprintf(
                    /* translators: %1$d: License ID, %2$d: Order ID */
                    __('No subscription found for license. License ID: %1$d, Order ID: %2$d', 'dodo-payments-for-woocommerce'),
                    $license_id,
                    $license->order_id
                ));
            }

            $subscription = reset($subscriptions);
            $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

            if (!$dodo_subscription_id) {
                throw new Exception(sprintf(
                    /* translators: %1$d: License ID, %2$d: Subscription ID */
                    __('No Dodo subscription mapping found. License ID: %1$d, WC Subscription ID: %2$d', 'dodo-payments-for-woocommerce'),
                    $license_id,
                    $subscription->get_id()
                ));
            }
        } else {
            if (!class_exists('LicenseMonks_License_Generator')) {
                throw new Exception(__('License Monks plugin is not active.', 'dodo-payments-for-woocommerce'));
            }
            if (!function_exists('wcs_get_subscriptions_for_order')) {
                throw new Exception(__('WooCommerce Subscriptions is not active.', 'dodo-payments-for-woocommerce'));
            }
        }

        // Get the new product from the order
        $new_wc_product = null;
        foreach ($order->get_items() as $item) {
            $new_wc_product = $item->get_product();
            break;
        }

        if (!$new_wc_product) {
            throw new Exception('No product found in upgrade order.');
        }

        $new_dodo_product_id = Dodo_Payments_Product_DB::get_dodo_product_id($new_wc_product->get_id());

        // If new product isn't mapped to Dodo yet, create it
        if (!$new_dodo_product_id) {
            $response_body = $this->dodo_payments_api->create_subscription_product($new_wc_product);
            $new_dodo_product_id = $response_body['product_id'];
            Dodo_Payments_Product_DB::save_mapping($new_wc_product->get_id(), $new_dodo_product_id);
        }

        // Execute the plan change via Dodo's changePlan API
        // Proration mode can be filtered to allow customization:
        // 'prorated_immediately' - Charge prorated amount now
        // 'full_immediately' - Charge full amount of new plan now
        // 'difference_immediately' - Charge only the difference if upgrading
        $valid_modes = array('prorated_immediately', 'full_immediately', 'difference_immediately');
        $proration_mode = apply_filters('dodo_payments_subscription_upgrade_proration_mode', 'prorated_immediately', $order, $upgrade_meta);

        // Validate proration mode - fallback to default if invalid
        if (!in_array($proration_mode, $valid_modes, true)) {
            $order->add_order_note(
                sprintf(
                    // translators: %s: Invalid proration mode
                    __('Invalid proration mode detected: %s. Using default: prorated_immediately', 'dodo-payments-for-woocommerce'),
                    esc_html($proration_mode)
                )
            );
            $proration_mode = 'prorated_immediately';
        }

        $this->dodo_payments_api->change_plan(
            $dodo_subscription_id,
            $new_dodo_product_id,
            1,
            $proration_mode,
            'prevent_change'
        );

        $order->add_order_note(
            sprintf(
                // translators: %1$s: Subscription ID, %2$s: New product name
                __('Subscription plan change initiated via Dodo Payments. Subscription: %1$s, New plan: %2$s (pending payment confirmation)', 'dodo-payments-for-woocommerce'),
                $dodo_subscription_id,
                $new_wc_product->get_name()
            )
        );

        // Store the pending plan change details for webhook processing
        // The timestamp allows detecting stale entries if the webhook is delayed or missed
        $order->update_meta_data('_dodo_pending_plan_change', array(
            'dodo_subscription_id' => $dodo_subscription_id,
            'new_dodo_product_id'  => $new_dodo_product_id,
            'new_wc_product_id'    => $new_wc_product->get_id(),
            'timestamp'            => current_time('mysql'),
            'order_id'             => $order->get_id(),
        ));
        $order->save();

        // Don't call payment_complete() here — with on_payment_failure='prevent_change',
        // the 200 response means the plan change was initiated, not that payment succeeded.
        // The subscription.plan_changed webhook will confirm payment and complete the order.

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        );
    } catch (Exception $e) {
        $error_message = $e->getMessage();

        $order->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Dodo Payments subscription upgrade failed: %1$s', 'dodo-payments-for-woocommerce'),
                $error_message
            )
        );

        $this->log_debug('Subscription upgrade error for Order #' . $order->get_id() . ': ' . $error_message);

        wc_add_notice(
            sprintf(
                // translators: %1$s: Error message
                __('Upgrade failed: %1$s', 'dodo-payments-for-woocommerce'),
                $error_message
            ),
            'error'
        );

        return array('result' => 'failure');
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
        } elseif ($product->get_type() === 'lm-subscription') {
            $is_subscription = true;
        }

        // Check if product is already mapped
        $dodo_product_id = Dodo_Payments_Product_DB::get_dodo_product_id($local_product_id);
        $dodo_product = null;

        if ($dodo_product_id) {
            $dodo_product = $this->dodo_payments_api->get_product($dodo_product_id);

            // If product not found in Dodo (404), clear the stale mapping
            if (!$dodo_product) {
                $this->log_debug("Product mapping stale for WC Product #{$local_product_id}, clearing mapping for Dodo Product {$dodo_product_id}");
                Dodo_Payments_Product_DB::delete_mapping($local_product_id);
                $dodo_product_id = null; // Force re-creation
            } else {
                // Use get_subtotal() (before coupon discounts) so the coupon
                // is only applied once by Dodo, not double-counted here.
                $item_subtotal = (float) $item->get_subtotal();

                // Validate subtotal - skip if negative or invalid
                if ($item_subtotal < 0) {
                    $order->add_order_note(
                        sprintf(
                            // translators: %1$d: Product ID
                            __('Invalid item subtotal detected for product %1$d, skipping sync.', 'dodo-payments-for-woocommerce'),
                            $local_product_id
                        )
                    );
                    continue;
                }

                $item_qty = max(1, $item->get_quantity());
                $effective_unit_price = $item_qty > 0 ? $item_subtotal / $item_qty : 0;
                $catalog_price = (float) $product->get_price();

                // Compare with a threshold that accounts for floating-point precision.
                // Using 0.001 as a safe threshold that works across most currencies
                // (0.01 for USD-like currencies, JPY has no decimals).
                $is_price_modified = abs($effective_unit_price - $catalog_price) > 0.001;

                if ($is_price_modified) {
                    // Upgrade scenario: create a temporary Dodo product with the prorated price
                    // instead of updating the original product's price in Dodo.
                    // Product name includes order ID for traceability and cleanup.
                    try {
                        $temp_product = $this->dodo_payments_api->create_product_with_custom_price(
                            sprintf(
                                /* translators: %s: Product name */
                                __('Upgrade: %s', 'dodo-payments-for-woocommerce'),
                                $product->get_name()
                            ),
                            $this->dodo_payments_api->price_to_minor_units($effective_unit_price)
                        );
                        $dodo_product_id = $temp_product['product_id'];

                        $order->add_order_note(
                            sprintf(
                                // translators: %1$s: Product name, %2$s: Amount
                                __('Created temporary upgrade product in Dodo Payments for %1$s (prorated: %2$s)', 'dodo-payments-for-woocommerce'),
                                $product->get_name(),
                                wc_price($effective_unit_price)
                            )
                        );
                    } catch (Exception $e) {
                        $order->add_order_note(
                            sprintf(
                                // translators: %1$s: Error message
                                __('Failed to create upgrade product in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                                $e->getMessage(),
                            )
                        );
                        continue;
                    }
                } else {
                    // Normal flow: sync full product data to Dodo
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
            'amount' => $this->dodo_payments_api->price_to_minor_units(
                (float) $item->get_subtotal() / max(1, $item->get_quantity())
            )
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

    // The cached ID mapping may be stale (discount deleted/recreated server-side).
    // Recover the existing discount by its code name before falling back to creating one.
    if (!$dodo_discount) {
        $dodo_discount = $this->dodo_payments_api->get_discount_code_by_code($coupon->get_code());

        if (!!$dodo_discount) {
            $dodo_discount_id = $dodo_discount['discount_id'];
            Dodo_Payments_Coupon_DB::save_mapping($coupon->get_id(), $dodo_discount_id);

            $dodo_discount = $this->dodo_payments_api->update_discount_code($dodo_discount_id, $dodo_discount_req_body);
            $dodo_discount_code = $dodo_discount['code'];
        }
    }

    if (!$dodo_discount) {
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
    // Get API instance for currency conversion helper
    $api = new Dodo_Payments_API(array(
        'testmode' => get_option('dodo_payments_testmode') === 'yes',
        'api_key' => get_option('dodo_payments_api_key'),
        'global_tax_category' => get_option('dodo_payments_global_tax_category', ''),
        'global_tax_inclusive' => get_option('dodo_payments_global_tax_inclusive') === 'yes',
    ));

    $coupon_amount = $api->price_to_minor_units($coupon->get_amount());
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

    $body = array(
        'type' => 'percentage',
        'code' => $coupon->get_code(),
        'amount' => $coupon_amount,
        'expires_at' => $expires_at,
        'usage_limit' => $usage_limit,
        'restricted_to' => $restricted_to,
    );

    // License Monks: "Subscription Discount Cycle" coupon flag (first year only).
    // Map it to Dodo's native subscription_cycles so the discount stops after
    // the first billing cycle and renewals are charged at full price.
    //
    // A free-trial $0 mandate does NOT consume a subscription_cycles count:
    // the trial is before the first billing cycle, a free trial has no charge
    // event, and trial_apply_discounts (default false) decouples discount codes
    // from the trial charge. So subscription_cycles=1 discounts exactly the
    // first real (paid) billing cycle after the trial ends.
    if (class_exists('LicenseMonks_Subscription_Coupon_Types')
        && is_callable(array('LicenseMonks_Subscription_Coupon_Types', 'is_first_cycle_only'))
        && LicenseMonks_Subscription_Coupon_Types::is_first_cycle_only($coupon)) {
        $body['subscription_cycles'] = 1;
    }

    return $body;
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
public function extend_trial_period_admin($wc_subscription_id, $days)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($wc_subscription_id);

    if (!$dodo_subscription_id) {
        return new WP_Error(
            'missing_dodo_mapping',
            __('No Dodo Payments subscription ID found for this subscription.', 'dodo-payments-for-woocommerce')
        );
    }

    $dodo_sub = $this->dodo_payments_api->get_subscription($dodo_subscription_id);
    if (!$dodo_sub) {
        return new WP_Error(
            'dodo_fetch_failed',
            __('Could not fetch subscription from Dodo Payments.', 'dodo-payments-for-woocommerce')
        );
    }

    $current_end = $dodo_sub['next_billing_date'] ?? null;
    if (!$current_end) {
        return new WP_Error(
            'no_billing_date',
            __('No next_billing_date found on Dodo subscription. Ensure the subscription has a trial period.', 'dodo-payments-for-woocommerce')
        );
    }

    // Add $days to the current trial end date (both as UTC timestamps)
    $new_end = gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($current_end) + ($days * DAY_IN_SECONDS));

    try {
        $this->dodo_payments_api->extend_trial_period($dodo_subscription_id, $new_end);
    } catch (Exception $e) {
        return new WP_Error('dodo_api_error', $e->getMessage());
    }

    $subscription = wc_get_order($wc_subscription_id);
    if ($subscription) {
        $subscription->add_order_note(sprintf(
            /* translators: %1$d: Number of days, %2$s: New trial end date */
            __('Dodo Payments: Trial period extended by %1$d days. New trial end date: %2$s', 'dodo-payments-for-woocommerce'),
            $days,
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($new_end))
        ));
    }

    return true;
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
}
