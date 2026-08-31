<?php
/**
 * Webhook endpoint and handlers: payment, refund, subscription, plan change, and renewal events.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Webhook endpoint and handlers: payment, refund, subscription, plan change, and renewal events.
 *
 * @since 0.7.0
 */
trait DodoPaymentsWebhooks
{

public function webhook()
{
    $headers = [
        'webhook-signature' => isset($_SERVER['HTTP_WEBHOOK_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_WEBHOOK_SIGNATURE'])) : '',
        'webhook-id' => isset($_SERVER['HTTP_WEBHOOK_ID']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_WEBHOOK_ID'])) : '',
        'webhook-timestamp' => isset($_SERVER['HTTP_WEBHOOK_TIMESTAMP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_WEBHOOK_TIMESTAMP'])) : '',
    ];

    // Read raw webhook body - do NOT sanitize as it will corrupt JSON
    // The webhook signature verification class handles security validation
    $body = file_get_contents('php://input');

    try {
        $webhook = new Dodo_Payments_Standard_Webhook($this->webhook_key);
    } catch (\Exception $e) {
        $this->log_debug('Invalid webhook key: ' . $e->getMessage());
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
        $this->log_debug('Could not verify webhook event: ' . $e->getMessage());
        if ($this->testmode) {
            status_header(401);
        } else {
            $this->consume_webhook_silently();
        }
        return;
    }

    // Webhook type format: 'kind.status' (e.g., 'payment.succeeded', 'subscription.active')
    $type = $payload['type'];
    $type_parts = explode('.', $type, 2);

    if (count($type_parts) !== 2) {
        $this->log_debug('Invalid webhook event type format: ' . $type);
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
            $this->log_debug("Found order #{$order_id} via session ID fallback, saved payment mapping");
        }
    }

    // Final check: Log error only if order truly cannot be found
    if (!$order_id) {
        $this->log_debug('Could not find order_id for payment: ' . $payment_id . ' (checked metadata, payment_id mapping, and session_id)');
        return;
    }

    // Get the order object (reuse if already retrieved from metadata check)
    if (!$order || $order->get_id() !== $order_id) {
        $order = wc_get_order($order_id);
    }

    if (!$order) {
        $this->log_debug('Could not find order: ' . $order_id);
        return;
    }

    switch ($status) {
        case 'succeeded':
            // ─── Trial payment guard ─────────────────────────────────────────────
            // Detect zero-amount "mandate authorization" payments that occur at
            // subscription creation when a trial_period_days > 0 is configured.
            // These $0 authorizations MUST NOT call payment_complete().
            $payment_amount = isset($payload['data']['total_amount'])
                ? (int) $payload['data']['total_amount']
                : 0;

            if ($payment_amount === 0 && isset($payload['data']['subscription_id'])) {
                $sub_id = $payload['data']['subscription_id'];
                $wc_sub_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($sub_id);

                if ($wc_sub_id) {
                    $subscription = null;
                    if (class_exists('LicenseMonks_Subscription')) {
                        $lm_sub = wc_get_order($wc_sub_id);
                        if ($lm_sub && $lm_sub->get_type() === 'lm_subscription') {
                            $subscription = $lm_sub;
                        }
                    }
                    if (!$subscription && class_exists('WC_Subscriptions') && function_exists('wcs_get_subscription')) {
                        $subscription = wcs_get_subscription($wc_sub_id);
                    }

                    if ($subscription) {
                        $subscription->update_meta_data('_dodo_trial_active', 'yes');
                        $subscription->update_meta_data('_dodo_trial_initial_payment_id', $payment_id);
                        $subscription->save();
                        $subscription->add_order_note(sprintf(
                            /* translators: %s: Payment ID */
                            __('Dodo Payments: Free trial mandate authorized. Payment ID: %s (zero-amount, not charged yet). Trial is active.', 'dodo-payments-for-woocommerce'),
                            $payment_id
                        ));
                    }
                }

                break; // EXIT — do NOT call payment_complete() or create a renewal order
            }
            // ─── End trial guard ────────────────────────────────────────────────

            $order->payment_complete($payment_id);
            $order->update_status('completed', __('Payment completed by Dodo Payments', 'dodo-payments-for-woocommerce'));

            if (isset($payload['data']['subscription_id'])) {
                $subscription_id = $payload['data']['subscription_id'];
                $wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($subscription_id);

                $subscription = false;

                if (class_exists('WC_Subscriptions') && function_exists('wcs_get_subscription')) {
                    $subscription_order = wcs_get_subscription($wc_subscription_id);
                    if ($subscription_order) {
                        $subscription = $subscription_order;
                    }
                }

                if (!$subscription) {
                    $lm_subscription = wc_get_order($wc_subscription_id);
                    if ($lm_subscription && $lm_subscription->get_type() === 'lm_subscription') {
                        $subscription = $lm_subscription;
                    }
                }

                if ($subscription) {
                    // ─── Post-trial first charge: clear trial flag ─────────────
                    $was_in_trial = $subscription->get_meta('_dodo_trial_active') === 'yes';

                    if ($was_in_trial) {
                        $subscription->update_meta_data('_dodo_trial_active', 'no');
                        $subscription->update_meta_data('_dodo_trial_first_charge', $payment_id);
                        $subscription->update_meta_data('_dodo_trial_first_charge_date', current_time('mysql'));
                        $subscription->save();
                        $subscription->add_order_note(sprintf(
                            /* translators: %1$s: Payment ID, %2$s: Order total */
                            __('Dodo Payments: Trial ended — first post-trial charge successful. Payment ID: %1$s, Amount: %2$s. Subscription is now in paid period.', 'dodo-payments-for-woocommerce'),
                            $payment_id,
                            wc_price($order->get_total())
                        ));
                    }
                    // ─── End trial flag clearing ───────────────────────────────
                }

                if (!$subscription) {
                    $this->log_debug(
                        'Could not find WooCommerce subscription '
                        . $wc_subscription_id
                        . ' for subscription ID '
                        . $subscription_id
                    );
                    return;
                }

                $dodo_subscription = $this->dodo_payments_api->get_subscription($subscription_id);

                if ($subscription->get_type() === 'lm_subscription') {
                    $this->create_lm_renewal_order($subscription, $payment_id);
                } else {
                    $this->create_renewal_order($subscription, $payment_id);
                }
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
        $this->log_debug('Could not find order for payment: ' . $payment_id);
        return;
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        $this->log_debug('Could not find order: ' . $order_id);
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
    $subscription_id = $payload['data']['subscription_id'];

    $wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($subscription_id);

    // The mapping may be missing if the return URL was never hit or the webhook arrived
    // first. Fall back to metadata.wc_order_id (set at checkout) and resolve the child
    // subscription from the parent order, same as the payment webhook handler.
    if (!$wc_subscription_id && isset($payload['data']['metadata']['wc_order_id'])) {
        $order_id = absint($payload['data']['metadata']['wc_order_id']);

        if ($order_id) {
            $wc_subscription_id = $this->find_subscription_id_from_order($order_id);

            if ($wc_subscription_id) {
                Dodo_Payments_Subscription_DB::save_mapping($wc_subscription_id, $subscription_id);
            }
        }
    }

    if (!$wc_subscription_id) {
        $this->log_debug('Could not find WooCommerce subscription for subscription ID ' . $subscription_id);
        return;
    }

    $subscription = wc_get_order($wc_subscription_id);
    
    if ($subscription && $subscription->get_type() === 'lm_subscription') {
        $this->handle_lm_subscription_webhook_event($subscription, $status, $payload);
        return;
    }
    
    if (!class_exists('WC_Subscriptions') || !function_exists('wcs_get_subscription')) {
        $this->log_debug('WC_Subscriptions plugin is not available. Skipping webhook handler.');
        return;
    }

    $subscription = wcs_get_subscription($wc_subscription_id);

    if (!$subscription) {
        $this->log_debug('Could not find WooCommerce subscription: ' . $wc_subscription_id);
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

        case 'trial_ended':
            $subscription->add_order_note(sprintf(
                /* translators: %1$s: Subscription ID */
                __('Dodo Payments: Trial period ended. Subscription ID: %1$s. Auto-charge will occur shortly.', 'dodo-payments-for-woocommerce'),
                $subscription_id
            ));
            break;

        case 'plan_changed':
            $this->handle_plan_changed_webhook($payload, $subscription);
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
 * Resolve the WooCommerce subscription ID (WCS or License Monks) from a parent order ID.
 *
 * @param int $order_id The parent WooCommerce order ID.
 * @return int|null The child subscription ID, or null if none found.
 */
private function find_subscription_id_from_order($order_id)
{
    if (class_exists('WC_Subscriptions') && function_exists('wcs_get_subscriptions_for_order')) {
        $subscriptions = wcs_get_subscriptions_for_order($order_id);
        if (!empty($subscriptions)) {
            $subscription = reset($subscriptions);
            return $subscription ? $subscription->get_id() : null;
        }
    }

    // License Monks subscriptions: child lm_subscription orders of the parent order.
    $lm_subscription_ids = wc_get_orders(array(
        'type'   => 'lm_subscription',
        'parent' => $order_id,
        'limit'  => 1,
        'return' => 'ids',
    ));

    return !empty($lm_subscription_ids) ? (int) reset($lm_subscription_ids) : null;
}

/**
 * Handle subscription plan changed webhook
 *
 * Completes pending upgrade orders when Dodo confirms a successful plan change.
 * The order metadata _dodo_pending_plan_change stores the pending upgrade details.
 *
 * @param array $payload The webhook payload.
 * @param WC_Subscription $subscription The subscription object.
 * @return void
 */
private function handle_plan_changed_webhook($payload, $subscription)
{
    $dodo_subscription_id = $payload['data']['subscription_id'] ?? '';

    if (empty($dodo_subscription_id)) {
        $this->log_debug('Plan changed webhook missing subscription_id');
        return;
    }

    // Find orders with pending plan changes for this subscription
    $args = array(
        'limit'  => 1,
        'type'   => 'shop_order',
        'status' => array('pending', 'on-hold'),
        'meta_query' => array(
            array(
                'key'     => '_dodo_pending_plan_change',
                'compare' => 'EXISTS',
            ),
        ),
    );

    $orders = wc_get_orders($args);

    foreach ($orders as $order) {
        $pending_change = $order->get_meta('_dodo_pending_plan_change');

        if (empty($pending_change)) {
            continue;
        }

        // Verify this order is for the same subscription that changed
        if (isset($pending_change['dodo_subscription_id']) && $pending_change['dodo_subscription_id'] === $dodo_subscription_id) {
            // Validate the pending change isn't stale (older than 1 hour)
            $timestamp = $pending_change['timestamp'] ?? '';
            if (!empty($timestamp)) {
                $pending_time = strtotime($timestamp);
                $current_time = current_time('timestamp');
                $one_hour_ago = $current_time - HOUR_IN_SECONDS;

                if ($pending_time < $one_hour_ago) {
                    $this->log_debug('Stale plan change detected for order #' . $order->get_id() . ', skipping');
                    $order->delete_meta_data('_dodo_pending_plan_change');
                    $order->save();
                    continue;
                }
            }

            // Verify the new product ID matches what Dodo confirmed
            $new_product_id = $payload['data']['product_id'] ?? '';
            if (!empty($new_product_id) && isset($pending_change['new_dodo_product_id']) && $pending_change['new_dodo_product_id'] !== $new_product_id) {
                $this->log_debug('Product ID mismatch in plan change webhook for order #' . $order->get_id());
                continue;
            }

            // Complete the order
            $order->payment_complete();
            $order->delete_meta_data('_dodo_pending_plan_change');
            $order->add_order_note(
                sprintf(
                    // translators: %1$s: Subscription ID, %2$s: New product ID
                    __('Subscription plan change confirmed by Dodo Payments. Subscription: %1$s, New plan: %2$s', 'dodo-payments-for-woocommerce'),
                    $dodo_subscription_id,
                    $new_product_id
                )
            );
            $order->save();

            $this->log_debug('Completed upgrade order #' . $order->get_id() . ' from plan_changed webhook');
            break; // Only process one matching order
        }
    }
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

private function handle_lm_subscription_webhook_event($subscription, $status, $payload)
{
    switch ($status) {
        case 'active':
            LicenseMonks_Subscription_Lifecycle::transition($subscription, 'active');
            $subscription->add_order_note(__('Subscription marked active in Dodo Payments', 'dodo-payments-for-woocommerce'));
            break;
        case 'renewed':
            // Handled in payment.succeeded webhook instead
            break;
        case 'on_hold':
        case 'paused':
            LicenseMonks_Subscription_Lifecycle::transition($subscription, 'on-hold');
            $subscription->add_order_note(__('Subscription on hold in Dodo Payments', 'dodo-payments-for-woocommerce'));
            break;
        case 'cancelled':
            LicenseMonks_Subscription_Lifecycle::transition($subscription, 'cancelled');
            $subscription->add_order_note(__('Subscription cancelled in Dodo Payments', 'dodo-payments-for-woocommerce'));
            break;
        case 'failed':
            LicenseMonks_Subscription_Lifecycle::put_on_hold(
                $subscription,
                __('Payment failed in Dodo Payments', 'dodo-payments-for-woocommerce')
            );
            break;
        case 'expired':
            LicenseMonks_Subscription_Lifecycle::expire($subscription);
            $subscription->add_order_note(__('Subscription expired in Dodo Payments', 'dodo-payments-for-woocommerce'));
            break;
        case 'trial_ended':
            $subscription->add_order_note(sprintf(
                /* translators: %1$s: Subscription ID */
                __('Dodo Payments: Trial period ended for subscription %1$s. Dodo will now auto-charge the stored mandate.', 'dodo-payments-for-woocommerce'),
                $subscription_id
            ));
            // Do NOT transition LM status here — let payment.succeeded (first post-trial charge)
            // drive the active transition so license activation hooks fire at the right time.
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

private function create_lm_renewal_order($subscription, $payment_id)
{
    if (!class_exists('LicenseMonks_Subscription_Renewal')) {
        $subscription->add_order_note(
            __('License renewal failed: LicenseMonks_Subscription_Renewal class not available', 'dodo-payments-for-woocommerce')
        );
        $this->log_debug('LM renewal failed: class not available');
        return;
    }

    if (!class_exists('LicenseMonks_Subscription_Lifecycle')) {
        $subscription->add_order_note(
            __('License renewal failed: LicenseMonks_Subscription_Lifecycle class not available', 'dodo-payments-for-woocommerce')
        );
        $this->log_debug('LM renewal failed: lifecycle class not available');
        return;
    }

    $renewal_order = LicenseMonks_Subscription_Renewal::process_renewal($subscription);

    if (is_wp_error($renewal_order)) {
        $subscription->add_order_note(
            sprintf(
                // translators: %s: Error message
                __('License renewal failed: %s', 'dodo-payments-for-woocommerce'),
                $renewal_order->get_error_message()
            )
        );
        $this->log_debug('LM renewal failed: ' . $renewal_order->get_error_message());
        return;
    }

    if (!$renewal_order) {
        $subscription->add_order_note(
            __('License renewal failed: process_renewal returned empty', 'dodo-payments-for-woocommerce')
        );
        $this->log_debug('LM renewal failed: no order returned from process_renewal');
        return;
    }

    $renewal_order->payment_complete($payment_id);
    $renewal_order->update_status('completed');

    LicenseMonks_Subscription_Lifecycle::advance_period($subscription, $renewal_order);

    $subscription->add_order_note(__('Subscription renewed by Dodo Payments', 'dodo-payments-for-woocommerce'));
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
