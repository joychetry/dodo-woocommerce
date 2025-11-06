# Tax ID Collection Implementation Guide

## Overview

This document describes the Tax ID / VAT number collection feature added to Dodo Payments for WooCommerce plugin version 0.4.0.

## Feature Summary

The plugin now supports collecting Tax ID (VAT) numbers from customers during checkout, which is essential for:

- **B2B transactions** - Business customers can provide their tax identification numbers
- **Tax compliance** - Required for certain jurisdictions and cross-border transactions  
- **EU VAT regulations** - Companies can provide their VAT numbers for proper tax handling

## Implementation Architecture

### API Approach

The feature leverages Dodo Payments' **Checkout Sessions API** instead of the legacy payment links:

| Aspect | Legacy API | Checkout Sessions API |
|--------|-----------|----------------------|
| Endpoint | `/payments`, `/subscriptions` | `/checkout-sessions` |
| Tax ID Support | ❌ No | ✅ Yes |
| Response | `payment_link`, `payment_id` | `checkout_url`, `session_id` |
| Use Case | Standard payments | Advanced features (Tax ID, etc.) |

### Code Changes

#### 1. New API Method: `create_checkout_session()`

**File**: `includes/class-dodo-payments-api.php`

```php
public function create_checkout_session(
    $order, 
    $synced_products, 
    $dodo_discount_code, 
    $return_url, 
    $enable_tax_id_collection = false
)
```

**Key Features**:
- Works for both one-time payments and subscriptions
- Sends `feature_flags: { allow_tax_id: true }` when enabled
- Also enables phone number collection automatically
- Returns `session_id` and `checkout_url`

**Request Structure**:
```php
array(
    'product_cart' => $synced_products,
    'customer' => array(
        'email' => ...,
        'name' => ...,
        'phone_number' => ...
    ),
    'billing_address' => array(
        'street' => ...,
        'city' => ...,
        'state' => ...,
        'country' => ...,
        'zipcode' => ...
    ),
    'return_url' => $return_url,
    'discount_code' => $dodo_discount_code, // if provided
    'feature_flags' => array(
        'allow_tax_id' => true,
        'allow_phone_number_collection' => true
    )
)
```

#### 2. Gateway Setting

**File**: `dodo-payments-for-woocommerce.php`

**Setting ID**: `enable_tax_id_collection`

**Location**: WooCommerce → Settings → Payments → Dodo Payments

**Properties**:
- Type: Checkbox
- Label: "Allow customers to provide their Tax ID / VAT number during checkout"
- Default: No (disabled)
- Description: "When enabled, customers will be able to enter their business Tax ID or VAT number on the Dodo Payments checkout page. This is useful for B2B transactions and tax compliance. Uses the modern Checkout Sessions API."

#### 3. Payment Flow Logic

**Modified Method**: `do_payment()`

**Logic Flow**:
```
if (enable_tax_id_collection is enabled) {
    → Use create_checkout_session()
    → Store session_id as order meta
    → Redirect to checkout_url
} else {
    → Use legacy create_payment() or create_subscription()
    → Store payment_id
    → Redirect to payment_link
}
```

#### 4. Webhook Handling

**No Changes Required** ✅

Both checkout sessions and legacy APIs fire the same webhook events:
- `payment.succeeded`
- `payment.failed`
- `subscription.active`
- `subscription.cancelled`
- etc.

The existing webhook handler in the `webhook()` method processes both flows identically.

## Database Schema

### Order Meta

When using checkout sessions, a new meta field is stored:

| Meta Key | Type | Description |
|----------|------|-------------|
| `_dodo_checkout_session_id` | string | The Dodo Payments checkout session ID |

This can be used for:
- Reference lookups
- Support inquiries
- Debugging

## User Experience

### When Tax ID Collection is Disabled (Default)

1. Customer proceeds to checkout
2. Selects "Dodo Payments" as payment method
3. Clicks "Place Order"
4. Redirected to Dodo payment page (legacy flow)
5. Completes payment
6. Redirected back to store

### When Tax ID Collection is Enabled

1. Customer proceeds to checkout  
2. Selects "Dodo Payments" as payment method
3. Clicks "Place Order"
4. Redirected to Dodo checkout session page
5. **Can optionally enter Tax ID / VAT number** 📝
6. Enters phone number (also collected)
7. Completes payment
8. Redirected back to store

## Configuration Instructions

### For Store Owners

1. Log into WordPress admin
2. Navigate to: **WooCommerce → Settings → Payments**
3. Find **Dodo Payments** in the list
4. Click **Manage**
5. Scroll to **Enable Tax ID Collection**
6. Check the box to enable
7. Click **Save changes**

### For Developers

**Enable programmatically**:
```php
update_option('woocommerce_dodo_payments_settings', array(
    // ... other settings ...
    'enable_tax_id_collection' => 'yes'
));
```

**Check if enabled**:
```php
$gateway = WC()->payment_gateways->payment_gateways()['dodo_payments'];
$is_enabled = $gateway->enable_tax_id_collection;
```

## API Reference

### Request: Create Checkout Session

**Endpoint**: `POST /checkout-sessions`

**Headers**:
```
Authorization: Bearer {api_key}
Content-Type: application/json
```

**Body**:
```json
{
  "product_cart": [
    {
      "product_id": "prod_xxx",
      "quantity": 1
    }
  ],
  "customer": {
    "email": "customer@example.com",
    "name": "John Doe",
    "phone_number": "+1234567890"
  },
  "billing_address": {
    "street": "123 Main St",
    "city": "San Francisco",
    "state": "CA",
    "country": "US",
    "zipcode": "94102"
  },
  "return_url": "https://yoursite.com/checkout/order-received/123/",
  "feature_flags": {
    "allow_tax_id": true,
    "allow_phone_number_collection": true
  }
}
```

**Response**:
```json
{
  "session_id": "cks_Gi6KGJ2zFJo9rq9Ukifwa",
  "checkout_url": "https://test.checkout.dodopayments.com/session/cks_Gi6KGJ2zFJo9rq9Ukifwa"
}
```

## Testing Checklist

### One-Time Payment
- [ ] Create simple product
- [ ] Enable Tax ID collection
- [ ] Complete checkout
- [ ] Verify Tax ID field appears
- [ ] Enter test Tax ID
- [ ] Complete payment
- [ ] Verify order marked as complete
- [ ] Check order notes for session ID

### Subscription Payment
- [ ] Create subscription product
- [ ] Enable Tax ID collection  
- [ ] Complete checkout
- [ ] Verify Tax ID field appears
- [ ] Enter test Tax ID
- [ ] Complete payment
- [ ] Verify subscription created
- [ ] Check order notes for session ID

### Backward Compatibility
- [ ] Disable Tax ID collection
- [ ] Complete one-time payment
- [ ] Complete subscription payment
- [ ] Verify legacy flow works

### Webhook Handling
- [ ] Enable Tax ID collection
- [ ] Complete payment
- [ ] Verify webhook received
- [ ] Check order status updated
- [ ] Verify no errors in logs

## Troubleshooting

### Tax ID field not showing

**Check**:
1. Is the setting enabled in WooCommerce → Settings → Payments → Dodo Payments?
2. Is the API key valid?
3. Check browser console for JavaScript errors
4. Check WordPress debug log for PHP errors

### Payment fails with checkout sessions

**Check**:
1. Ensure billing address is complete (all fields required)
2. Verify API key has checkout sessions permission
3. Check if test/live mode matches API key
4. Review order notes for error messages

### Webhooks not working

**Same for both flows**:
1. Verify webhook signing key is configured
2. Check webhook URL is accessible
3. Test webhook from Dodo dashboard
4. Check WordPress debug log

## Security Considerations

### Data Handling

- **Tax IDs**: Collected and stored by Dodo Payments, not in WordPress
- **PCI Compliance**: Payment data never touches WordPress server
- **Webhook Verification**: All webhooks verified using HMAC signature
- **API Keys**: Stored in WordPress database, use secure hosting

### Best Practices

1. Always use SSL/HTTPS in production
2. Keep WordPress and plugins updated
3. Use strong webhook signing keys
4. Regularly rotate API keys
5. Monitor webhook logs for suspicious activity

## Performance Impact

### Minimal Impact

- **Same number of API calls** as legacy flow
- **No additional database queries** (only one meta field)
- **Client-side redirect** - no server processing delay
- **Webhook handling identical** - no performance change

## Backward Compatibility

### Fully Compatible ✅

- **Default behavior unchanged**: Legacy API used by default
- **Existing orders unaffected**: Only new orders use new flow
- **Settings preserved**: Existing configuration works as before
- **Webhooks unchanged**: Same webhook handler for both flows

## Future Enhancements

Potential improvements for future versions:

1. **Tax ID Validation**: Validate VAT numbers against VIES database
2. **Automatic Tax Exemption**: Apply B2B reverse charge when valid VAT
3. **Tax ID Storage**: Optionally save validated tax IDs in WordPress
4. **Customer Profiles**: Remember tax IDs for returning customers
5. **Admin Display**: Show collected tax IDs in order details
6. **Reports**: Add tax ID column to order exports

## Support & Documentation

### Resources

- **Dodo Payments Docs**: https://docs.dodopayments.com/developer-resources/checkout-session
- **Plugin Documentation**: See README.md
- **Changelog**: See changelog.txt
- **Support**: https://dodopayments.com/support

### Getting Help

For issues or questions:

1. Check plugin logs in WooCommerce → Status → Logs
2. Review order notes for error messages
3. Test in test mode before going live
4. Contact Dodo Payments support with session ID

## Version History

- **0.4.0** (2025-11-06): Initial Tax ID collection support
  - Checkout Sessions API integration
  - Automatic payment ID capture from return URL
  - Proper webhook integration
  - Phone number optional field handling
  - Optimized feature flags implementation
- **0.3.3**: Previous version (no Tax ID support)

---

## Implementation Notes

### Payment ID Capture Flow

Following Dodo Payments best practices, the implementation handles payment ID mapping via return URL:

1. **Checkout Session Created** → `session_id` stored in order meta
2. **Customer Completes Payment** → Dodo redirects to `return_url` with `payment_id` parameter
3. **Return URL Handler** → Captures `payment_id` and saves mapping to database
4. **Webhook Arrives** → Uses `payment_id` to find order and update status

This approach ensures webhooks can properly find and update orders even though checkout sessions don't return `payment_id` immediately.

### Phone Number Handling

Per Dodo documentation, phone number is optional in the customer object:
- Only included in request if customer provided it during WooCommerce checkout
- Prevents API rejection due to empty strings
- Follows Dodo's recommended field handling practices

### Feature Flags

Implemented according to Dodo best practices:
```php
'feature_flags' => array(
    'allow_phone_number_collection' => true,  // Always enabled
    'allow_tax_id' => true,                    // Conditional based on setting
)
```

---

**Last Updated**: November 6, 2025  
**Plugin Version**: 0.4.0  
**Dodo API Version**: Checkout Sessions API v1

