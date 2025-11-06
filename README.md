# Dodo Payments for WooCommerce

A WooCommerce payment gateway plugin that allows you to accept payments through Dodo Payments.

## Description

Dodo Payments for WooCommerce enables you to accept payments from your customers using Dodo Payments as a payment method. The plugin integrates seamlessly with your WooCommerce store and provides a secure, reliable merchant of record solution.

## Features

- Easy integration with WooCommerce
- Support for both live and test modes
- Secure payment processing
- Webhook support for payment status updates
- Tax category configuration
- Support for digital products, SaaS, e-books, and EdTech products
- **Tax ID / VAT Number Collection** - Allow B2B customers to provide their tax identification numbers during checkout
- Support for WooCommerce Subscriptions
- Modern Checkout Sessions API integration

## Requirements

- WordPress 6.1 or higher
- PHP 7.4 or higher
- WooCommerce 7.9 or higher
- SSL certificate (for secure payment processing)

## Installation

1. Download the plugin zip file
2. Go to WordPress admin panel > Plugins > Add New
3. Click "Upload Plugin" and select the downloaded zip file
4. Click "Install Now" and then "Activate"

## Configuration

1. Go to WooCommerce > Settings > Payments
2. Find "Dodo Payments" and click "Manage"
3. Configure the following settings:
   - Enable/Disable the payment method
   - Set the payment method title and description
   - Configure test/live mode
   - Add your API keys and webhook signing keys
   - Set global tax category and tax inclusive settings

### API Keys

- **Live API Key**: Required for receiving live payments. Generate from Dodo Payments (Live Mode) > Developer > API Keys
- **Live Webhook Signing Key**: Required for payment status sync. Generate from Dodo Payments (Live Mode) > Developer > Webhooks
- **Test API Key**: Optional, for testing payments. Generate from Dodo Payments (Test Mode) > Developer > API Keys
- **Test Webhook Signing Key**: Optional, for testing payment status sync. Generate from Dodo Payments (Test Mode) > Developer > Webhooks

## Tax ID / VAT Number Collection

The plugin supports collecting Tax ID (VAT) numbers from customers during checkout. This is particularly useful for:

- **B2B transactions** - Businesses can provide their tax identification numbers
- **Tax compliance** - Required for certain jurisdictions and cross-border transactions
- **EU VAT regulations** - Companies can provide their VAT numbers for proper tax handling

### Enabling Tax ID Collection

1. Go to WooCommerce > Settings > Payments > Dodo Payments
2. Find the "Enable Tax ID Collection" setting
3. Check the box to enable the feature
4. Save your settings

### How It Works

When Tax ID collection is enabled:

- The plugin uses the modern **Checkout Sessions API** instead of the legacy payment links
- Customers will see a Tax ID / VAT number field on the Dodo Payments checkout page
- The field is optional by default, allowing both B2B and B2C customers to complete checkout
- Tax IDs are securely stored by Dodo Payments and included in payment/subscription records
- The feature works seamlessly with both one-time payments and subscriptions

### Technical Details

- **API Endpoint**: Uses `/checkout-sessions` instead of `/payments` or `/subscriptions`
- **Feature Flag**: Sends `feature_flags: { allow_tax_id: true }` in the checkout session request
- **Backward Compatible**: When disabled, the plugin continues using the legacy payment API
- **Webhook Handling**: No changes required - checkout sessions fire the same webhook events (payment.succeeded, subscription.active, etc.)

### For Developers

The implementation includes:

- `Dodo_Payments_API::create_checkout_session()` - New method for creating checkout sessions
- Gateway setting: `enable_tax_id_collection` - Boolean flag to enable/disable the feature
- Session ID storage: Stored as order meta `_dodo_checkout_session_id` for reference
- Automatic phone number collection: Also enabled via `allow_phone_number_collection` feature flag

## Webhook Setup

The plugin provides a webhook endpoint for payment status updates. Use the URL at the end of the same page when setting up webhooks in your Dodo Payments dashboard.

## Support

For support, please contact Dodo Payments support team or visit the [Dodo Payments website](https://dodopayments.com).

## License

This plugin is licensed under the GPL v3.
