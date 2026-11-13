=== Dodo Payments for WooCommerce ===
Contributors: classicmonks
Tags: payments, woocommerce, dodo
Requires at least: 7.1
Tested up to: 7.1
Stable tag: 1.2.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==

Dodo Payments for WooCommerce is a comprehensive payment solution that handles all your payment processing needs. As a Merchant of Record (MoR) service, we take care of payment processing, tax compliance, and financial regulations, allowing you to focus on growing your business. With support for multiple payment methods and automated tax calculations, Dodo Payments makes it easy to sell globally while staying compliant with local tax laws and regulations.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/dodo-payments-for-woocommerce`
  directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Use the Settings->Dodo Payments screen to configure the plugin

== Supports ==

- Digital Products, Ebooks, Ed-Tech and SaaS products
- Subscription products and recurring payments
- Percentage based coupon codes
- Classic Checkout Flow
- Block based checkout (overlay checkout falls back to a redirect)
- Payments from multiple countries
- Product pricing in USD or INR

== Does not Support ==

- Fixed amount or custom discount codes

== Frequently Asked Questions ==

= What payment methods are supported? =

Currently, we support credit card payments through Dodo Payment Gateway.

== Changelog ==

= 1.2.2 =
* Fix: The invoice section shows once on the admin order page and appears on the customer's order page even when the theme replaces the order details table
* New: An "Invoice" action in the My Account orders list, which also works with themes that override the orders template

= 1.2.1 =
* Fix: Overlay checkout on the classic checkout page opens again; the cart is now emptied after payment instead of before, so WooCommerce no longer sends the buyer to an empty cart
* Fix: Cancelling a subscription from My Account now also cancels it in Dodo Payments, so customers are no longer charged after a trial
* Fix: A subscription cancelled (or set to cancel at period end) before Dodo Payments activated it is cancelled in Dodo Payments when Dodo reports it active, instead of being reactivated
* Fix: WooCommerce Subscriptions no longer creates its own renewal orders or puts Dodo subscriptions on hold at each renewal date
* Fix: A Dodo Payments renewal advances the WooCommerce Subscriptions next payment date
* Fix: A redelivered WooCommerce Subscriptions renewal payment no longer creates a second renewal order
* Fix: Subscription changes made in wp-admin are no longer sent to Dodo Payments twice
* Fix: A payment for a cancelled order or subscription emails the admin instead of completing the order or creating a renewal
* Fix: A redelivered payment webhook no longer moves a refunded order back to completed
* Fix: Renewal charges on the legacy (non Checkout Sessions) flow are recorded, so License Monks licenses are extended instead of expiring while the customer is still billed
* Fix: Legacy payments and subscriptions send the WooCommerce order ID to Dodo Payments, so their webhooks always find the order
* Fix: A failed, cancelled or processing payment event no longer changes a paid order, so a failed renewal or a late retry no longer fails the parent order or puts the subscription on hold
* Fix: Refunds are recorded on the order that holds the payment, with the refunded amount: a renewal refund no longer refunds the parent order (which cancelled the subscription), and partial refunds stay partial
* Fix: A customer whose payment is declined or abandoned returns to the pay-for-order page with an error, instead of a "thank you" page
* Fix: The payment ID is captured from the return URL again: the return handler did not run because gateways are not loaded on the order-received page
* Fix: License Monks subscription upgrades no longer fail with "WooCommerce Subscriptions is not active"
* Fix: Upgrades recreate a Dodo product whose mapping is stale, instead of failing with a 404
* Fix: The charge for a plan change completes its upgrade order instead of creating a renewal order and extending the subscription
* Feature: Customers on a free trial can end it early and pay now from My Account (the order's Pay button becomes "End trial & pay now"); the card saved at signup is charged on the existing Dodo subscription
* Fix: Paying a free-trial order no longer starts a second Dodo subscription with a new trial (which also re-linked the WooCommerce subscription to the new, unpaid one)
* Fix: The first charge after a free trial completes the order and starts the first paid period from the charge date, instead of also creating a renewal order and sending a renewal email
* Fix: Paying an order whose trial-end charge was declined opens Dodo's "pay pending dues" page for the existing subscription, instead of starting a new subscription with another free trial
* Fix: Putting a subscription on hold or reactivating it in WooCommerce now pauses and resumes it in Dodo Payments (both requests were rejected by Dodo), and changes that came from Dodo are no longer sent back to it
* Fix: The License Monks "trial ended" note shows the subscription ID
* Enhancement: Every handled webhook is logged with its type, webhook ID, payment, subscription and order IDs

= 1.2.0 =
* Feature: Support the WooCommerce Checkout block, including business purchase and tax ID fields
* Change: Requires WordPress 7.1 and WooCommerce 11.0 or higher
* Fix: Cart is emptied only after a successful payment, so failed payments can be retried
* Fix: Overlay checkout only opens Dodo Payments checkout URLs
* Fix: Subscription plan-change confirmations now complete the right upgrade order, even when the webhook arrives late
* Performance: Checkout scripts load deferred

= 0.6 =
* Feature: Add "Buy as Company" functionality - Allow customers to purchase as business and provide company name for invoices
* Feature: Add company name field to checkout with toggle switch
* Enhancement: Add informational text about tax ID collection for B2B transactions
* Enhancement: Add nonce verification for custom checkout fields security

= 0.4 =
* Feature: Add "Purchase as Business" functionality - B2B customers can provide company name for invoices
* Feature: Add Tax ID/VAT collection support through Dodo Payments checkout
* Feature: Add Checkout Sessions API integration with overlay checkout mode
* Feature: Add invoice retrieval and management functionality
* Enhancement: Improve input sanitization with wp_unslash() for WordPress.org compliance
* Enhancement: Add nonce verification to custom checkout fields
* Enhancement: Wrap debug logging in WP_DEBUG conditional
* Enhancement: Add filter to disable overlay checkout if needed
* Fix: Omit empty billing fields in API requests to prevent transaction errors

= 0.3.3 =
* fix: change subscription status to 'on_hold' instead of invalid state of 'paused' when a subscription is paused in woocommerce.

= 0.3.2 =
* fix: add missing import for cart exceptions which prevented cart errors from being displayed properly

= 0.3.1 =
* fix: use more widely used format for webhook url

= 0.3.0 =
* Feature: Add comprehensive subscription support
* Feature: Subscription product management and synchronization
* Feature: Subscription lifecycle management (cancel, suspend, reactivate)

= 0.2.5 =
* Fix: remove unsupported syntax for PHP 7

= 0.2.4 =
* Fix: clear cart only if the payment link is created

= 0.2.1 =
* Fix product prices getting rounded off

= 0.2.0 =
* Feature: Add support for coupon codes(Fixed percentage type only).

= 0.1.9 =
* Fixed a bug where products with descriptions longer than 1000 characters would fail to process

= 0.1.3 =
* Initial release
