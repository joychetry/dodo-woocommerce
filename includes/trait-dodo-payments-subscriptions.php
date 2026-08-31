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

public function handle_lm_subscription_status_updated($subscription, $new_status, $old_status)
{
    if ($subscription->get_payment_method() !== $this->id) {
        return;
    }

    // Strip wc- prefix if present for uniform matching
    $new_status = str_starts_with($new_status, 'wc-') ? substr($new_status, 3) : $new_status;

    switch ($new_status) {
        case 'on-hold':
            $this->suspend_lm_subscription($subscription);
            break;
        case 'pending-cancel':
            $this->cancel_lm_subscription_at_next_billing($subscription);
            break;
        case 'cancelled':
        case 'expired':
            $this->cancel_lm_subscription($subscription);
            break;
        case 'active':
            if (str_contains($old_status, 'on-hold')) {
                $this->reactivate_lm_subscription($subscription);
            }
            break;
    }
}

private function suspend_lm_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    try {
        $this->dodo_payments_api->pause_subscription($dodo_subscription_id);
        $subscription->add_order_note(__('Subscription paused in Dodo Payments', 'dodo-payments-for-woocommerce'));
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
        return;
    }

    try {
        $this->dodo_payments_api->cancel_subscription($dodo_subscription_id);
        $subscription->add_order_note(__('Subscription cancelled in Dodo Payments', 'dodo-payments-for-woocommerce'));
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

private function cancel_lm_subscription_at_next_billing($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    try {
        $this->dodo_payments_api->cancel_subscription_at_next_billing_date($dodo_subscription_id);
        $subscription->add_order_note(__('Subscription set to cancel at end of billing cycle in Dodo Payments', 'dodo-payments-for-woocommerce'));
    } catch (\Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to set subscription to cancel at next billing date in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
    }
}

private function reactivate_lm_subscription($subscription)
{
    $dodo_subscription_id = Dodo_Payments_Subscription_DB::get_dodo_subscription_id($subscription->get_id());
    if (!$dodo_subscription_id) {
        return;
    }

    try {
        $this->dodo_payments_api->resume_subscription($dodo_subscription_id);
        $subscription->add_order_note(__('Subscription resumed in Dodo Payments', 'dodo-payments-for-woocommerce'));
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

public function handle_subscription_status_updated($subscription, $new_status, $old_status)
{
    if ($subscription->get_payment_method() !== $this->id) {
        return;
    }

    switch ($new_status) {
        case 'on-hold':
            $this->suspend_subscription($subscription);
            break;
        case 'pending-cancel':
            $this->cancel_subscription_at_next_billing_date($subscription);
            break;
        case 'cancelled':
            $this->cancel_subscription($subscription);
            break;
        case 'expired':
            // When a subscription expires, we should also cancel it in Dodo Payments
            $this->cancel_subscription($subscription);
            break;
        case 'active':
            if ($old_status === 'on-hold') {
                $this->reactivate_subscription($subscription);
            }
            break;
    }
}

public function handle_subscription_date_change($subscription, $changed_dates)
{
    if ($subscription->get_payment_method() !== $this->id) {
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
        $subscription->add_order_note(__('No Dodo Payments subscription ID found for cancellation.', 'dodo-payments-for-woocommerce'));
        return;
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
    } catch (Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
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
        $subscription->add_order_note(__('No Dodo Payments subscription ID found for cancellation.', 'dodo-payments-for-woocommerce'));
        return;
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
    } catch (Exception $e) {
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Error message
                __('Failed to cancel subscription in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $e->getMessage()
            )
        );
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
        $this->dodo_payments_api->pause_subscription($dodo_subscription_id);
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Subscription ID
                __('Subscription paused in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $dodo_subscription_id
            )
        );
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
        $this->dodo_payments_api->resume_subscription($dodo_subscription_id);
        $subscription->add_order_note(
            sprintf(
                // translators: %1$s: Subscription ID
                __('Subscription resumed in Dodo Payments: %1$s', 'dodo-payments-for-woocommerce'),
                $dodo_subscription_id
            )
        );
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
