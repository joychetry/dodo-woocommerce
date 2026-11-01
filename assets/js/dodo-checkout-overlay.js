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

        // Inline: the form is embedded in the order payment page instead of opening in a modal.
        var isInline = dodoCheckoutOverlay.displayType === 'inline';

        // Initialize Dodo Payments SDK
        try {
            DodoPaymentsSDK.Initialize({
                mode: dodoCheckoutOverlay.mode, // 'test' or 'live'
                displayType: isInline ? 'inline' : 'overlay',
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
                    if (isInline) {
                        // SDK 2.1.0 sends no checkout.form_ready, so the frame being loaded is the cue.
                        $('#dodo-inline-checkout-loading').remove();
                        break;
                    }
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

                case 'checkout.form_ready':
                    $('#dodo-inline-checkout-loading').remove();
                    break;

                case 'checkout.breakdown':
                    renderBreakdown(event.data && event.data.message);
                    break;

                case 'checkout.link_expired':
                    showErrorMessage(dodoCheckoutOverlay.i18n.expired);
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
         * Formats an amount in the currency's smallest unit (cents for USD, yen for JPY).
         */
        function formatAmount(amount, currency) {
            var format = new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency: currency });
            return format.format(amount / Math.pow(10, format.resolvedOptions().maximumFractionDigits));
        }

        /**
         * Shows Dodo's prices on the order payment page. Dodo calculates the tax, so these
         * replace WooCommerce's totals.
         */
        function renderBreakdown(breakdown) {
            if (!breakdown || !breakdown.currency) {
                return;
            }

            var $table = $('#dodo-inline-totals');
            var chargedCurrency = breakdown.finalTotalCurrency || breakdown.currency;

            $table.find('[data-dodo-row]').each(function() {
                var $row = $(this);
                var key = $row.data('dodo-row');
                var value = breakdown[key];
                var hasValue = typeof value === 'number';
                var visible = hasValue;
                var text = hasValue ? formatAmount(key === 'discount' ? -value : value, key === 'finalTotal' ? chargedCurrency : breakdown.currency) : '';

                if (key === 'discount') {
                    visible = hasValue && value > 0;
                } else if (key === 'tax' && !hasValue) {
                    // Tax is unknown until the customer enters a country and postal code.
                    visible = true;
                    text = dodoCheckoutOverlay.i18n.taxPending;
                } else if (key === 'finalTotal') {
                    // Only differs from the total with currency conversion or payment method fees.
                    visible = hasValue && (value !== breakdown.total || chargedCurrency !== breakdown.currency);
                }

                $row.prop('hidden', !visible).find('td').text(text);
            });

            $table.prop('hidden', false);
        }

        /**
         * Show error message to user
         */
        function showErrorMessage(message) {
            var $notice = $('#dodo-inline-checkout-notice');
            if ($notice.length) {
                // Text, not HTML: the message comes from the checkout frame.
                var $error = $('<div class="woocommerce-error"></div>').text(message + ' ');
                $error.append($('<a></a>').attr('href', dodoCheckoutOverlay.retryUrl).text(dodoCheckoutOverlay.i18n.retry));
                $notice.empty().append($error);
                $('#dodo-inline-checkout-loading').remove();
                return;
            }

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

                var options = { checkoutUrl: dodoCheckoutOverlay.checkoutUrl };
                if (isInline) {
                    options.elementId = dodoCheckoutOverlay.elementId;
                }
                DodoPaymentsSDK.Checkout.open(options);
            } catch (error) {
                console.error('Error opening checkout overlay:', error);
                showErrorMessage(isInline ? dodoCheckoutOverlay.i18n.error : 'Failed to open checkout. Please try again.');
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

