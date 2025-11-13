# Dodo Payments for WooCommerce - Troubleshooting Guide

## Common Issues and Solutions

### Issue 1: Product Not Found (404 Errors)

**Symptoms:**
```
Dodo Payments: Product (pdt_xxxxx) not found: {"code":"NOT_FOUND","message":"..."}
```

**Causes:**
1. Switched between Test and Live modes
2. Products deleted from Dodo Payments dashboard
3. Stale product ID mappings in database

**Solutions:**

#### Automatic Fix (v0.4.0+)
The plugin now automatically detects and clears stale product mappings. When a product returns 404:
- The stale mapping is deleted
- Product is automatically re-created on next checkout
- New mapping is saved

**No manual action needed** - just proceed with checkout and products will re-sync.

#### Manual Fix (If Needed)
If you want to clear all mappings at once:

1. **Option A: Use the utility script**
   - Access: `https://yoursite.com/wp-content/plugins/dodo-payments-for-woocommerce/clear-product-mappings.php`
   - Must be logged in as admin
   - Delete the script file after use

2. **Option B: Database query**
   ```sql
   TRUNCATE TABLE wp_dodo_payments_products;
   ```

3. **After clearing:**
   - Products will re-sync on next checkout
   - Ensure products exist in Dodo dashboard (correct mode)

---

### Issue 2: Webhook Can't Find Order

**Symptoms:**
```
Dodo Payments: Could not find order_id for payment: pay_xxxxx
Dodo Payments: Payment ID mapping not found, trying session ID: cks_xxxxx
```

**Causes:**
1. Webhook arrives before customer completes return URL redirect
2. Payment ID not captured from return URL
3. Race condition in checkout session flow

**Solutions:**

#### Automatic Fix (v0.4.0+)
The webhook handler now uses a three-tier approach following Dodo Payments best practices:
1. **Primary**: Extracts `order_id` from webhook metadata (`wc_order_id`) - eliminates race conditions
2. **Secondary**: Checks `payment_id` mapping in database (for legacy orders)
3. **Tertiary**: Falls back to `checkout_session_id` search (rarely needed)

**This handles race conditions automatically and reduces debug log noise.**

**Note**: The debug logs about "Payment ID mapping not found" are informational and indicate the fallback mechanism is working. These logs only appear when `WP_DEBUG` is enabled. The system will still find and process orders correctly.

#### Manual Verification
Check if order has session ID stored:
```php
$order = wc_get_order($order_id);
$session_id = $order->get_meta('_dodo_checkout_session_id');
echo "Session ID: " . $session_id;
```

---

### Issue 3: 404 Error on Checkout Sessions API

**Symptoms:**
```
Dodo Payments API Error (checkout-sessions) - Status 404
```

**Cause:**
Incorrect API endpoint (fixed in v0.4.0)

**Solution:**
Update to v0.4.0+ which uses the correct `/checkouts` endpoint.

---

### Issue 4: Generic "Error Processing Order" Message

**Symptoms:**
- Customer sees: "There was an error processing your order"
- No specific error details

**Causes:**
1. API key not configured
2. Network/API errors
3. Invalid product configuration

**Solutions:**

#### Check API Key Configuration
1. Go to: WooCommerce > Settings > Payments > Dodo Payments
2. Verify:
   - Test Mode setting matches your intent
   - Correct API key is entered (Test or Live)
   - API key is valid (check Dodo dashboard)

#### Enable Debug Logging
1. Enable WordPress debug logging in `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```

2. Check logs at: `wp-content/debug.log`

3. Look for specific error messages:
   - API authentication errors
   - Product creation failures
   - Network connectivity issues

---

## Debugging Checklist

### Before Contacting Support

1. **Check Plugin Version**
   - Ensure you're running v0.4.0 or later
   - Update if necessary

2. **Verify Mode Configuration**
   - Test Mode: Use Test API key, test products
   - Live Mode: Use Live API key, live products
   - Don't mix modes!

3. **Check Debug Logs**
   - Enable WordPress debug logging
   - Look for "Dodo Payments" entries
   - Note specific error codes

4. **Test Checkout Flow**
   - Add product to cart
   - Proceed to checkout
   - Complete payment
   - Verify order status updates

5. **Verify Webhook Configuration**
   - Webhook URL: `https://yoursite.com/wc-api/dodo_payments`
   - Webhook signing key configured
   - Test webhook delivery from Dodo dashboard

---

## Common Configuration Issues

### Test vs Live Mode Mismatch

**Problem:** Using Test API key with Live products (or vice versa)

**Solution:**
1. Decide which mode to use
2. Configure matching API key
3. Clear product mappings
4. Use products from correct mode

### Missing Webhook Signing Key

**Problem:** Webhooks not processing, no errors

**Solution:**
1. Go to Dodo dashboard > Developer > Webhooks
2. Copy webhook signing key
3. Paste in WooCommerce > Settings > Payments > Dodo Payments
4. Save settings

### Products Not Syncing

**Problem:** Products fail to create in Dodo

**Solution:**
1. Check product has:
   - Valid name
   - Valid price (> 0)
   - Valid currency
2. Check API key has product creation permissions
3. Check debug logs for specific errors

---

## Advanced Troubleshooting

### Inspect Order Meta Data

```php
$order = wc_get_order($order_id);

// Check session ID
$session_id = $order->get_meta('_dodo_checkout_session_id');
echo "Session ID: " . $session_id . "\n";

// Check payment ID
$payment_id = $order->get_meta('_dodo_payment_id');
echo "Payment ID: " . $payment_id . "\n";

// Check subscription ID (if applicable)
$subscription_id = $order->get_meta('_dodo_subscription_id');
echo "Subscription ID: " . $subscription_id . "\n";
```

### Check Database Mappings

```sql
-- Check product mappings
SELECT * FROM wp_dodo_payments_products;

-- Check payment mappings
SELECT * FROM wp_dodo_payments_payment_mapping;

-- Check subscription mappings
SELECT * FROM wp_dodo_payments_subscription_mapping;
```

### Test Webhook Manually

```bash
# Send test webhook (replace with your data)
curl -X POST https://yoursite.com/wc-api/dodo_payments \
  -H "Content-Type: application/json" \
  -H "X-Dodo-Signature: your_signature" \
  -d '{
    "type": "payment.succeeded",
    "data": {
      "payment_id": "pay_test123",
      "checkout_session_id": "cks_test123"
    }
  }'
```

---

## Performance Optimization

### Reduce API Calls

1. **Product Sync:**
   - Products only sync once per WooCommerce product
   - Updates only happen when product changes
   - Mappings cached in database

2. **Webhook Processing:**
   - Webhooks process asynchronously
   - No impact on customer checkout experience

### Monitor Error Rates

Check debug logs periodically:
```bash
# Count Dodo Payments errors
grep "Dodo Payments" wp-content/debug.log | wc -l

# Recent errors
tail -100 wp-content/debug.log | grep "Dodo Payments"
```

---

## Getting Help

### Information to Provide

When contacting support, include:

1. **Plugin Version:** Check in Plugins page
2. **WordPress Version:** Dashboard > Updates
3. **WooCommerce Version:** Dashboard > Updates
4. **PHP Version:** Site Health > Info
5. **Error Logs:** Recent entries from debug.log
6. **Configuration:**
   - Test or Live mode
   - Tax ID collection enabled?
   - Subscription products?
7. **Steps to Reproduce:** Exact steps that cause the issue

### Support Channels

- GitHub Issues: https://github.com/dodopayments/dodo-payments-for-woocommerce
- Dodo Payments Support: support@dodopayments.com
- Documentation: https://docs.dodopayments.com

---

## Version History

### v0.4.0 (2025-11-06)
- ✅ Fixed: Stale product mappings auto-clear
- ✅ Fixed: Webhook race condition with session_id fallback
- ✅ Fixed: Correct API endpoint (/checkouts)
- ✅ Enhanced: Better error messages
- ✅ Enhanced: API key validation

### v0.3.1 (2025-07-09)
- Fixed: Webhook URL compatibility

### v0.3.0 (2025-06-18)
- Added: Subscription support

### v0.2.0 (2025-05-19)
- Added: Coupon code support

---

## Best Practices

1. **Always test in Test Mode first**
2. **Enable debug logging during setup**
3. **Monitor webhooks regularly**
4. **Keep plugin updated**
5. **Backup database before major changes**
6. **Document your configuration**
7. **Test checkout flow after updates**

---

*Last Updated: November 6, 2025*
*Plugin Version: 0.4.0*

