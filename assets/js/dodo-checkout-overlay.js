/**
 * Dodo Payments Overlay Checkout
 *
 * Handles the overlay checkout experience using Dodo Payments Checkout SDK.
 *
 * @package Dodo_Payments
 * @since 0.5.0
 */

(function($) {
    'use strict';

    // Wait for DOM and SDK to be ready
    $(document).ready(function() {
        // Check if overlay checkout data is available
        if (typeof dodoCheckoutOverlay === 'undefined' || !dodoCheckoutOverlay.checkoutUrl) {
            return;
        }

        // Wait for Dodo Payments SDK to load
        // CDN exposes SDK as DodoPaymentsCheckout.DodoPayments
        var DodoPaymentsSDK = null;
        if (typeof DodoPaymentsCheckout !== 'undefined' && typeof DodoPaymentsCheckout.DodoPayments !== 'undefined') {
            DodoPaymentsSDK = DodoPaymentsCheckout.DodoPayments;
        } else if (typeof DodoPayments !== 'undefined') {
            // Fallback for direct DodoPayments (if CDN exposes it directly)
            DodoPaymentsSDK = DodoPayments;
        } else {
            console.error('Dodo Payments Checkout SDK not loaded');
            return;
        }

        // Initialize Dodo Payments SDK
        try {
            DodoPaymentsSDK.Initialize({
                mode: dodoCheckoutOverlay.mode, // 'test' or 'live'
                onEvent: function(event) {
                    handleCheckoutEvent(event);
                }
            });
        } catch (error) {
            console.error('Error initializing Dodo Payments SDK:', error);
            return;
        }

        /**
         * Handle checkout events
         */
        function handleCheckoutEvent(event) {
            console.log('Dodo Payments Checkout Event:', event.event_type || event.type);

            var eventType = event.event_type || event.type;

            switch (eventType) {
                case 'checkout.opened':
                    // Overlay opened
                    $('body').addClass('dodo-checkout-overlay-open');
                    // Clear session data to prevent reopening on refresh
                    if (dodoCheckoutOverlay.ajaxUrl && dodoCheckoutOverlay.nonce) {
                        $.post(dodoCheckoutOverlay.ajaxUrl, {
                            action: 'dodo_clear_checkout_session',
                            nonce: dodoCheckoutOverlay.nonce
                        });
                    }
                    break;

                case 'checkout.closed':
                    // Overlay closed
                    $('body').removeClass('dodo-checkout-overlay-open');
                    break;

                case 'checkout.payment_page_opened':
                    // Payment page opened in overlay
                    break;

                case 'checkout.customer_details_submitted':
                    // Customer details submitted
                    break;

                case 'checkout.redirect':
                    // Redirect event - payment completed or failed
                    if (event.data && event.data.url) {
                        window.location.href = event.data.url;
                    }
                    break;

                case 'checkout.error':
                    // Error occurred
                    console.error('Dodo Payments Checkout Error:', event.data);
                    var errorMessage = 'An error occurred during checkout. Please try again.';
                    if (event.data && event.data.message) {
                        errorMessage = event.data.message;
                    }
                    showErrorMessage(errorMessage);
                    break;

                default:
                    console.log('Unhandled checkout event:', eventType);
            }
        }

        /**
         * Show error message to user
         */
        function showErrorMessage(message) {
            // Remove any existing error notices
            $('.woocommerce-error, .woocommerce-message').remove();

            // Add error notice
            if ($('.woocommerce-checkout').length) {
                $('.woocommerce-checkout').prepend(
                    '<div class="woocommerce-error" role="alert">' +
                    '<strong>' + message + '</strong>' +
                    '</div>'
                );
            } else {
                // Fallback: show alert
                alert(message);
            }
        }

        /**
         * Open checkout overlay
         */
        function openCheckout() {
            try {
                // Get SDK reference (same as initialization)
                var DodoPaymentsSDK = null;
                if (typeof DodoPaymentsCheckout !== 'undefined' && typeof DodoPaymentsCheckout.DodoPayments !== 'undefined') {
                    DodoPaymentsSDK = DodoPaymentsCheckout.DodoPayments;
                } else if (typeof DodoPayments !== 'undefined') {
                    DodoPaymentsSDK = DodoPayments;
                } else {
                    showErrorMessage('Dodo Payments SDK not available');
                    return;
                }

                // Check if checkout is already open
                if (DodoPaymentsSDK.Checkout && typeof DodoPaymentsSDK.Checkout.isOpen === 'function' && DodoPaymentsSDK.Checkout.isOpen()) {
                    return; // Already open, don't open again
                }

                DodoPaymentsSDK.Checkout.open({
                    checkoutUrl: dodoCheckoutOverlay.checkoutUrl
                });
            } catch (error) {
                console.error('Error opening checkout overlay:', error);
                showErrorMessage('Failed to open checkout. Please try again.');
            }
        }

        // Auto-open checkout overlay when page loads
        // Small delay to ensure page is fully rendered and SDK is initialized
        setTimeout(function() {
            openCheckout();
        }, 500);

        // Also listen for form submission to prevent default if overlay is open
        $('form.checkout').on('submit', function(e) {
            // If overlay is open, don't submit the form
            if ($('body').hasClass('dodo-checkout-overlay-open')) {
                e.preventDefault();
                return false;
            }
        });
    });
})(jQuery);

