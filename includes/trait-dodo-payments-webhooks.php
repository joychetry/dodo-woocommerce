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
        $this->log_debug('Invalid webhook key: ' . $e->getMessage() . ' (webhook-id: ' . $headers['webhook-id'] . ')');
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
        $this->log_debug('Could not verify webhook event: ' . $e->getMessage() . ' (webhook-id: ' . $headers['webhook-id'] . ', timestamp: ' . $headers['webhook-timestamp'] . ')');
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

    $this->log_debug(sprintf(
        'Webhook received: %s (webhook-id: %s, payment_id: %s, subscription_id: %s, wc_order_id: %s)',
        $type,
        $headers['webhook-id'],
        $payload['data']['payment_id'] ?? '-',
        $payload['data']['subscription_id'] ?? '-',
        $payload['data']['metadata']['wc_order_id'] ?? '-'
    ));

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

    // Method 4: renewal charges carry no wc_order_id metadata on legacy /subscriptions, and the
    // mapping table keeps only the latest payment per order, so resolve the subscription's parent.
    if (!$order_id && !empty($payload['data']['subscription_id'])) {
        $sub_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($payload['data']['subscription_id']);
        $sub = $sub_id ? wc_get_order($sub_id) : null;
        $order_id = $sub ? $sub->get_parent_id() : null;
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

            // A $0 payment on a paid order is a card update, not a trial signup.
            if ($payment_amount === 0 && isset($payload['data']['subscription_id']) && $order->is_paid()) {
                $this->log_debug("Zero-amount payment {$payment_id} on paid order #{$order_id} (payment method update), nothing to do");
                break;
            }

            if ($payment_amount === 0 && isset($payload['data']['subscription_id'])) {
                $sub_id = $payload['data']['subscription_id'];
                // The mandate can arrive before the subscription mapping is saved; the trial flag must still be set.
                $wc_sub_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($sub_id) ?: $this->find_subscription_id_from_order($order->get_id());

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

            // Redelivery of a payment already held for review, or already applied
            // (a refunded order must not go back to completed).
            if (in_array($payment_id, wp_list_pluck($order->get_meta('_dodo_held_payment_id', false), 'value'), true)
                || ($order->get_transaction_id() === $payment_id && ($order->is_paid() || $order->has_status('refunded')))) {
                $this->log_debug("payment.succeeded {$payment_id} already handled on order #{$order_id} (status: {$order->get_status()}), ignoring duplicate");
                break;
            }

            // WooCommerce or License Monks subscription this charge belongs to.
            $subscription = null;
            $wc_subscription_id = null;
            if (!empty($payload['data']['subscription_id'])) {
                // Same fallback as the trial guard: the charge can arrive before the mapping is saved.
                $wc_subscription_id = Dodo_Payments_Subscription_DB::get_wc_subscription_id($payload['data']['subscription_id']) ?: $this->find_subscription_id_from_order($order->get_id());
                $subscription = $wc_subscription_id ? wc_get_order($wc_subscription_id) : null;
                if ($subscription && !in_array($subscription->get_type(), array('shop_subscription', 'lm_subscription'), true)) {
                    $subscription = null;
                }

                // Charge on a subscription cancelled/expired in WooCommerce: alert the admin instead of fulfilling.
                if ($subscription && $subscription->has_status(array('cancelled', 'expired'))) {
                    $this->hold_late_payment($order, $payment_id, $subscription);
                    break;
                }
            }

            // Charge on a cancelled, never-paid order (e.g. auto-cancelled while the customer
            // was still paying): leave it cancelled for review. Renewals on a paid parent are unaffected.
            if ($order->has_status('cancelled') && !$order->get_date_paid()) {
                $this->hold_late_payment($order, $payment_id);
                break;
            }

            // The parent's own charge is the one that set (or will set) its transaction id;
            // payment status is unreliable because a failed renewal also marks the parent failed.
            // Pin it in meta: payment_complete() on a failed parent overwrites the transaction id.
            $parent_txn = (string) $order->get_meta('_dodo_parent_payment_id');
            if ('' === $parent_txn) {
                $parent_txn = (string) $order->get_transaction_id();
                if (isset($payload['data']['subscription_id'])) {
                    $order->update_meta_data('_dodo_parent_payment_id', '' === $parent_txn ? $payment_id : $parent_txn);
                }
            }
            $order->payment_complete($payment_id);
            $order->update_status('completed', __('Payment completed by Dodo Payments', 'dodo-payments-for-woocommerce'));

            if (isset($payload['data']['subscription_id'])) {
                $subscription_id = $payload['data']['subscription_id'];

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

                    // The first post-trial charge pays the parent order: start the first paid period
                    // now (the trial may have been ended early), not with a renewal order on top.
                    if ($was_in_trial && $subscription->get_type() === 'lm_subscription' && class_exists('LicenseMonks_Subscription_Lifecycle')) {
                        $now = new WC_DateTime('now', new DateTimeZone('UTC'));
                        $period_end = $subscription->get_current_period_end_utc();
                        if (!$period_end || $period_end > $now) {
                            $subscription->set_current_period_end_utc($now);
                            $subscription->save();
                        }
                        LicenseMonks_Subscription_Lifecycle::advance_period($subscription, $order);
                        break;
                    }
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

                // A redelivered webhook must not renew twice, and the first non-trial
                // charge (or its redelivery) pays the parent order itself, so it is not a renewal.
                // Any order other than the parent carrying this payment is a renewal we created.
                // Not meta_query: the posts order store ignores it.
                $seen = wc_get_orders(array(
                    'transaction_id' => $payment_id,
                    'exclude' => array($order->get_id()),
                    'limit' => 1,
                    'return' => 'ids',
                ));
                $is_first_charge = ('' === $parent_txn || $payment_id === $parent_txn) && !$was_in_trial;
                if (!empty($seen) || $is_first_charge) {
                    break;
                }

                // The charge for a plan change pays its upgrade order (if plan_changed has not already); it is not a renewal.
                if (($payload['data']['charge_type'] ?? '') === 'plan_change') {
                    $this->complete_pending_plan_change($subscription_id, $payment_id);
                    break;
                }

                if ($subscription->get_type() === 'lm_subscription') {
                    $this->create_lm_renewal_order($subscription, $payment_id);
                } else {
                    $this->create_renewal_order($subscription, $payment_id);
                }
            }

            break;

        case 'failed':
        case 'cancelled':
        case 'processing':
        default:
            $reason = isset($payload['data']['error_message']) ? ' ' . $payload['data']['error_message'] : '';
            // A paid order is only referenced again by a renewal charge, or by an older attempt
            // delivered late. Failing it would refund stock, and License Monks would put the
            // subscription on hold or cancel it; Dodo's subscription.* events handle that instead.
            if ($order->get_date_paid() || $order->has_status('refunded')) {
                // translators: %1$s: Payment ID, %2$s: Payment status, %3$s: Failure reason
                $order->add_order_note(sprintf(__('Dodo Payments: payment %1$s %2$s.%3$s', 'dodo-payments-for-woocommerce'), $payment_id, $status, $reason));
                break;
            }
            if ('failed' === $status) {
                $order->update_status('failed', __('Payment failed by Dodo Payments', 'dodo-payments-for-woocommerce') . $reason);
                wc_increase_stock_levels($order_id);
            } elseif ('cancelled' === $status) {
                $order->update_status('cancelled', __('Payment cancelled by Dodo Payments', 'dodo-payments-for-woocommerce') . $reason);
                wc_increase_stock_levels($order_id);
            } else {
                $order->update_status('processing', __('Payment processing by Dodo Payments', 'dodo-payments-for-woocommerce'));
            }
            break;
    }
}

/**
 * Record a payment that arrived for a cancelled order or subscription, without fulfilling it,
 * and email the admin to refund it or fulfil manually.
 *
 * The order keeps its status and no renewal order is created.
 *
 * @param WC_Order      $order        Order referenced by the payment.
 * @param string        $payment_id   Dodo payment ID.
 * @param WC_Order|null $subscription Cancelled/expired WooCommerce or License Monks subscription.
 * @return void
 */
private function hold_late_payment($order, $payment_id, $subscription = null)
{
    if ($subscription) {
        $subject_id = $subscription->get_id();
        $note = sprintf(
            // translators: %1$s: Payment ID, %2$s: Subscription ID
            __('Dodo Payments: payment %1$s succeeded for subscription #%2$s, which is cancelled in WooCommerce. No order was completed and no renewal order was created. Refund the payment and cancel the subscription in Dodo Payments.', 'dodo-payments-for-woocommerce'),
            $payment_id,
            $subject_id
        );
        // translators: %1$s: Subscription ID
        $subject = __('[Action needed] Payment received for cancelled subscription #%1$s', 'dodo-payments-for-woocommerce');
        $subscription->add_order_note($note);
    } else {
        $subject_id = $order->get_order_number();
        $note = sprintf(
            // translators: %1$s: Payment ID
            __('Dodo Payments: payment %1$s succeeded for an order that was already cancelled. The order was NOT completed. Refund the payment in Dodo Payments, or fulfil the order manually.', 'dodo-payments-for-woocommerce'),
            $payment_id
        );
        // translators: %1$s: Order number
        $subject = __('[Action needed] Payment received for cancelled order #%1$s', 'dodo-payments-for-woocommerce');
    }

    // One entry per held payment, so a redelivery of any of them is recognised.
    $order->add_meta_data('_dodo_held_payment_id', $payment_id);
    if (!$order->get_date_paid()) {
        $order->set_transaction_id($payment_id);
    }
    // Status is left as is: on-hold would email the customer and take the stock out again.
    $order->save();
    $order->add_order_note($note);

    $this->log_debug("payment.succeeded {$payment_id} held for review on order #{$order->get_id()}" . ($subscription ? " (cancelled subscription #{$subject_id})" : ' (cancelled order)'));

    $admin_email = get_option('admin_email');
    if ($admin_email) {
        // translators: %1$s: Order number or subscription ID
        wp_mail($admin_email, sprintf($subject, $subject_id), $note . "\n\n" . $order->get_edit_order_url());
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
    // The order holding the payment, so a renewal refund lands on its renewal order, not the parent.
    // The mapping table keeps only the latest payment per order, so it is only a fallback.
    $order_ids = wc_get_orders(array('transaction_id' => $payment_id, 'limit' => 1, 'return' => 'ids'));
    $order_id = $order_ids ? $order_ids[0] : Dodo_Payments_Payment_DB::get_order_id($payment_id);

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
            $refund_id = (string) ($payload['data']['refund_id'] ?? '');
            foreach ($order->get_refunds() as $existing) {
                if ($refund_id && $existing->get_meta('_dodo_refund_id') === $refund_id) {
                    return; // Redelivery.
                }
            }

            // Record the amount, so a partial refund stays partial. WooCommerce marks the order
            // refunded once nothing is left. Capped: Dodo can charge cents the store rounded away.
            $currency = $payload['data']['currency'] ?? $order->get_currency();
            $amount = (!empty($payload['data']['is_partial']) && isset($payload['data']['amount']))
                ? min((int) $payload['data']['amount'] / $this->dodo_payments_api->price_to_minor_units(1, $currency), $order->get_remaining_refund_amount())
                : $order->get_remaining_refund_amount();

            if ($amount > 0) {
                $refund = wc_create_refund(array(
                    'order_id' => $order->get_id(),
                    'amount' => $amount,
                    'reason' => $payload['data']['reason'] ?? __('Refunded in Dodo Payments', 'dodo-payments-for-woocommerce'),
                ));
                if (is_wp_error($refund)) {
                    $this->log_debug('Could not record refund ' . $refund_id . ' on order #' . $order->get_id() . ': ' . $refund->get_error_message());
                } else {
                    $refund->update_meta_data('_dodo_refund_id', $refund_id);
                    $refund->save();
                }
            }

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

    // Never reactivate a subscription cancelled in WooCommerce. Dodo still billing it means the
    // cancellation never reached Dodo (cancelled before linking, or the API call failed): send it now.
    if ('active' === $status && $subscription && $subscription->has_status(array('cancelled', 'expired', 'pending-cancel'))) {
        $current = $subscription->get_status();
        $this->log_debug("subscription.active for {$current} subscription #{$subscription->get_id()}, sending the cancellation to Dodo");
        $sent = $subscription->get_type() === 'lm_subscription'
            ? $this->handle_lm_subscription_status_updated($subscription, $current, $current)
            : $this->handle_subscription_status_updated($subscription, $current, $current);
        if (false === $sent) {
            status_header(500); // Dodo redelivers, retrying the cancellation.
            exit;
        }
        return;
    }

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
 * @param WC_Order $subscription The subscription object.
 * @return void
 */
private function handle_plan_changed_webhook($payload, $subscription)
{
    $dodo_subscription_id = $payload['data']['subscription_id'] ?? '';

    if (empty($dodo_subscription_id)) {
        $this->log_debug('Plan changed webhook missing subscription_id');
        return;
    }

    $this->complete_pending_plan_change($dodo_subscription_id, '', $payload['data']['product_id'] ?? '');
}

/**
 * Completes the upgrade order waiting on a plan change of this Dodo subscription, if any.
 *
 * Called for subscription.plan_changed and for the upgrade's own charge (payment.succeeded),
 * whichever arrives first, so the upgrade charge is never mistaken for a renewal.
 *
 * @param string $dodo_subscription_id Dodo subscription ID.
 * @param string $payment_id           Dodo payment ID of the upgrade charge, if known.
 * @param string $new_product_id       Dodo product ID confirmed by the plan change, if known.
 * @return bool Whether an upgrade order was completed.
 */
private function complete_pending_plan_change($dodo_subscription_id, $payment_id = '', $new_product_id = '')
{
    // Pending change is a serialized array, so fetch all and match the subscription below (newest first).
    $orders = wc_get_orders(array(
        'limit'  => -1,
        'type'   => 'shop_order',
        'status' => array('pending', 'on-hold'),
        'meta_query' => array(
            array(
                'key'     => '_dodo_pending_plan_change',
                'compare' => 'EXISTS',
            ),
        ),
    ));

    foreach ($orders as $order) {
        $pending_change = $order->get_meta('_dodo_pending_plan_change');

        // The posts order store ignores meta_query, so this check does the filtering there.
        if (empty($pending_change['dodo_subscription_id']) || $pending_change['dodo_subscription_id'] !== $dodo_subscription_id) {
            continue;
        }

        // Verify the new product ID matches what Dodo confirmed
        if (!empty($new_product_id) && isset($pending_change['new_dodo_product_id']) && $pending_change['new_dodo_product_id'] !== $new_product_id) {
            $this->log_debug('Product ID mismatch in plan change webhook for order #' . $order->get_id());
            continue;
        }

        $order->delete_meta_data('_dodo_pending_plan_change');
        $order->payment_complete($payment_id);
        $order->add_order_note(
            sprintf(
                // translators: %1$s: Subscription ID, %2$s: New product ID
                __('Subscription plan change confirmed by Dodo Payments. Subscription: %1$s, New plan: %2$s', 'dodo-payments-for-woocommerce'),
                $dodo_subscription_id,
                $new_product_id ?: $pending_change['new_dodo_product_id'] ?? ''
            )
        );
        $order->save();

        $this->log_debug('Completed upgrade order #' . $order->get_id() . ($payment_id ? " with payment {$payment_id}" : ' from plan_changed webhook'));
        return true;
    }

    return false;
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

            // With gateway_scheduled_payments, WooCommerce Subscriptions no longer advances the date at renewal.
            // A stale date would make "cancel at period end" cancel immediately.
            try {
                $subscription->update_dates(array('next_payment' => $subscription->calculate_date('next_payment')));
            } catch (\Exception $e) {
                $this->log_debug('Could not advance next payment date of subscription #' . $subscription->get_id() . ': ' . $e->getMessage());
            }
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
                $payload['data']['subscription_id']
            ));
            // Do NOT transition LM status here — let payment.succeeded (first post-trial charge)
            // drive the active transition so license activation hooks fire at the right time.
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

    // Set explicitly so the redelivery check finds this order even if payment_complete() is skipped.
    $renewal_order->set_transaction_id($payment_id);
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
