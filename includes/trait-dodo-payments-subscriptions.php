<?php
/**
 * Subscription lifecycle sync: WooCommerce Subscriptions and LicenseMonks status updates, suspend/cancel/reactivate.
 *
 * @package Dodo_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Subscription lifecycle sync: WooCommerce Subscriptions and LicenseMonks status updates, suspend/cancel/reactivate.
 *
 * @since 0.7.0
 */
trait DodoPaymentsSubscriptions
{

/** @var bool True while a subscription.cancelled webhook is applied: Dodo already cancelled it. */
private $applying_dodo_cancellation = false;

/**
 * Notes a cancellation made before Dodo linked the subscription (the link is saved on Dodo's
 * first webhook). The subscription.active webhook handler sends it to Dodo then.
 *
 * @param WC_Order $subscription WooCommerce or License Monks subscription.
 * @return bool True: nothing can be sent yet.
 */
private function defer_dodo_cancel($subscription)
{
    $this->log_debug('Subscription #' . $subscription->get_id() . ' cancelled before a Dodo subscription was linked; deferred until it activates');
    $subscription->add_order_note(__('Dodo Payments: no Dodo subscription is linked yet. If one activates, the cancellation will be sent to Dodo Payments then.', 'dodo-payments-for-woocommerce'));
    return true;
}

/**
 * Syncs a License Monks status change to Dodo. Called only for Dodo-paid subscriptions.
 *
 * @return bool|null Cancellation result (false: the Dodo API call failed), null for other statuses.
 */
public function handle_lm_subscription_status_updated($subscription, $new_status, $old_status)
{
    // Strip wc- prefix if present for uniform matching
    $new_status = str_starts_with($new_status, 'wc-') ? substr($new_status, 3) : $new_status;

    if ($this->is_dodo_status_echo($new_status)) {
        return null;
    }

    switch ($new_status) {
        case 'on-hold':
            $this->suspend_lm_subscription($subscription);
            break;
        case 'pending-cancel':
            return $this->cancel_lm_subscription_at_next_billing($subscription);
        case 'cancelled':
        case 'expired':
            return $this->cancel_lm_subscription($subscription);
        case 'active':
            if (str_contains($old_status, 'on-hold')) {
                $this->reactivate_lm_subscription($subscription);
            } elseif (str_contains($old_status, 'pending-cancel')) {
                $this->undo_scheduled_cancel($subscription);
            }
            break;
    }
}

/**
 * Removes the cancellation scheduled in Dodo when a pending cancellation is reverted,
 * so Dodo does not cancel at the period end anyway.
 *
 * @param WC_Order $subscription WooCommerce or License Monks subscription.
 */
private function undo_scheduled_cancel($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    try {
        $this->dodo_payments_api->cancel_subscription_at_next_billing_date($dodo_subscription_id, false);
        $subscription->add_order_note(__('Scheduled cancellation removed in Dodo Payments', 'dodo-payments-for-woocommerce'));
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to remove the scheduled cancellation in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

/**
 * Whether a hold or reactivation is being applied from a Dodo webhook, so it must not be sent back.
 *
 * Sending it back races Dodo: after a failed charge Dodo still reports "active" for a moment, and
 * pausing it then turns its on_hold (customer can pay the dues) into paused. Cancellations still
 * go through: the webhook handler relies on them to resend a cancellation Dodo missed.
 */
private function is_dodo_status_echo($new_status)
{
    if ('cancelled' === $new_status && $this->applying_dodo_cancellation) {
        return true;
    }
    return in_array($new_status, array('on-hold', 'active'), true) && doing_action('woocommerce_api_' . $this->id);
}

private function suspend_lm_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    try {
        if ($this->dodo_payments_api->pause_subscription($dodo_subscription_id)) {
            $subscription->add_order_note(__('Subscription paused in Dodo Payments', 'dodo-payments-for-woocommerce'));
        }
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to pause subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

private function cancel_lm_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return $this->defer_dodo_cancel($subscription);
    }

    try {
        $this->dodo_payments_api->cancel_subscription($dodo_subscription_id);
        $subscription->add_order_note(__('Subscription cancelled in Dodo Payments', 'dodo-payments-for-woocommerce'));
        return true;
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
        return false;
    }
}

private function cancel_lm_subscription_at_next_billing($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return $this->defer_dodo_cancel($subscription);
    }

    try {
        $this->dodo_payments_api->cancel_subscription_at_next_billing_date($dodo_subscription_id);
        $subscription->add_order_note(__('Subscription set to cancel at end of billing cycle in Dodo Payments', 'dodo-payments-for-woocommerce'));
        return true;
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to set subscription to cancel at next billing date in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
        return false;
    }
}

private function reactivate_lm_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    try {
        if ($this->dodo_payments_api->resume_subscription($dodo_subscription_id)) {
            $subscription->add_order_note(__('Subscription resumed in Dodo Payments', 'dodo-payments-for-woocommerce'));
        }
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to resume subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

/**
 * Syncs a WooCommerce Subscriptions status change to Dodo. Called only for Dodo-paid subscriptions.
 *
 * @return bool|null Cancellation result (false: the Dodo API call failed), null for other statuses.
 */
public function handle_subscription_status_updated($subscription, $new_status, $old_status)
{
    if ($this->is_dodo_status_echo($new_status)) {
        return null;
    }

    switch ($new_status) {
        case 'on-hold':
            $this->suspend_subscription($subscription);
            break;
        case 'pending-cancel':
            return $this->cancel_subscription_at_next_billing_date($subscription);
        case 'cancelled':
        case 'expired':
            return $this->cancel_subscription($subscription);
        case 'active':
            if ($old_status === 'on-hold') {
                $this->reactivate_subscription($subscription);
            } elseif ($old_status === 'pending-cancel') {
                $this->undo_scheduled_cancel($subscription);
            }
            break;
    }
}

public function handle_subscription_date_change($subscription, $changed_dates)
{
    // WooCommerce Subscriptions also updates dates itself (renewals, cancel at period end).
    if (!doing_action('woocommerce_process_shop_order_meta')) {
        return;
    }

    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    $subscription->add_order_note(__('Dodo Payments: Manual subscription date changes are not yet supported and will not be synced.', 'dodo-payments-for-woocommerce'));
}

public function cancel_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

    if (!$dodo_subscription_id) {
        return $this->defer_dodo_cancel($subscription);
    }

    try {
        $this->dodo_payments_api->cancel_subscription($dodo_subscription_id);
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Subscription ID
                __('Subscription cancelled in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $dodo_subscription_id
            )
        );
        return true;
    } catch (Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
        return false;
    }
}

/**
 * Cancels a subscription at next billing date in WooCommerce
 *
 * @param WC_Subscription $subscription
 * @return void
 * @since 0.3.0
 */
public function cancel_subscription_at_next_billing_date($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

    if (!$dodo_subscription_id) {
        return $this->defer_dodo_cancel($subscription);
    }

    try {
        $this->dodo_payments_api->cancel_subscription_at_next_billing_date($dodo_subscription_id);
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Subscription ID
                __('Subscription scheduled for cancellation at next billing date in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $dodo_subscription_id
            )
        );
        return true;
    } catch (Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
        return false;
    }
}

/**
 * Suspends a subscription in Dodo Payments
 *
 * @param WC_Subscription $subscription
 * @return void
 * @since 0.3.0
 */
public function suspend_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

    if (!$dodo_subscription_id) {
        $subscription->add_order_note(__('No Dodo Payments subscription ID found for suspension.', 'dodo-payments-for-woocommerce'));
        return;
    }

    try {
        if ($this->dodo_payments_api->pause_subscription($dodo_subscription_id)) {
            $subscription->add_order_note(
                sprintf(
                    // translators: %1$s: Subscription ID
                    __('Subscription paused in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                    $dodo_subscription_id
                )
            );
        }
    } catch (Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to pause subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

/**
 * Reactivates a subscription when activated in WooCommerce
 *
 * @param WC_Subscription $subscription
 * @return void
 * @since 0.3.0
 */
public function reactivate_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());

    if (!$dodo_subscription_id) {
        $subscription->add_order_note(__('No Dodo Payments subscription ID found for reactivation.', 'dodo-payments-for-woocommerce'));
        return;
    }

    try {
        if ($this->dodo_payments_api->resume_subscription($dodo_subscription_id)) {
            $subscription->add_order_note(
                sprintf(
                    // translators: %1$s: Subscription ID
                    __('Subscription resumed in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                    $dodo_subscription_id
                )
            );
        }
    } catch (Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to resume subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

/**
 * Utility method to check if an order contains subscription products
 *
 * @param WC_Order $order
 * @return bool
 * @since 0.3.0
 */
private function order_contains_subscription($order)
{
    if (function_exists('wcs_order_contains_subscription')) {
        return wcs_order_contains_subscription($order);
    }

    // Fallback check for subscription products
    if (class_exists('WC_Subscriptions_Product')) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && WC_Subscriptions_Product::is_subscription($product)) {
                return true;
            }
        }
    }

    // License Monks check
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->get_type() === 'lm-subscription') {
            return true;
        }
    }

    return false;
}

/**
 * Extends the trial period for a License Monks or WooCommerce Subscription
 * by a given number of days via the Dodo Payments API.
 *
 * Used by the admin AJAX handler to grant trial extensions for customer support.
 *
 * @param int $wc_subscription_id The WooCommerce subscription/order ID.
 * @param int $days              Number of days to extend the trial by.
 * @return \WP_Error|true WP_Error on failure, true on success.
 * @since 0.7.0
 */
}
