# Implementation Comparison: Overlay Checkout & Invoice API

## Overview
This document compares the current implementation with the official Dodo Payments documentation to identify discrepancies and required fixes.

---

## 1. Overlay Checkout SDK Implementation

### ✅ **Correctly Implemented**

1. **SDK Initialization**
   - ✅ Uses correct CDN URL: `https://cdn.jsdelivr.net/npm/dodopayments-checkout@latest/dist/index.js`
   - ✅ Initializes with `mode` ('test' or 'live')
   - ✅ Sets up `onEvent` callback handler
   - ✅ Handles all documented events correctly

2. **Event Handling**
   - ✅ Handles `checkout.opened`
   - ✅ Handles `checkout.closed`
   - ✅ Handles `checkout.payment_page_opened`
   - ✅ Handles `checkout.customer_details_submitted`
   - ✅ Handles `checkout.redirect`
   - ✅ Handles `checkout.error`

3. **Checkout Opening**
   - ✅ Uses `DodoPayments.Checkout.open({ checkoutUrl: ... })`
   - ✅ Passes checkout session URL correctly

### ❌ **Critical Issues Found**

#### Issue #1: CDN Global Variable Mismatch

**Documentation States:**
When using CDN, the SDK is exposed as `DodoPaymentsCheckout.DodoPayments`, not just `DodoPayments`.

**CDN Example from Docs:**
```javascript
<script src="https://cdn.jsdelivr.net/npm/dodopayments-checkout@latest/dist/index.js"></script>
<script>
    DodoPaymentsCheckout.DodoPayments.Initialize({
        mode: "test",
        onEvent: (event) => { ... }
    });
    
    DodoPaymentsCheckout.DodoPayments.Checkout.open({
        checkoutUrl: "..."
    });
</script>
```

**Current Implementation:**
```javascript
// assets/js/dodo-checkout-overlay.js
DodoPayments.Initialize({ ... });  // ❌ WRONG - should be DodoPaymentsCheckout.DodoPayments
DodoPayments.Checkout.open({ ... });  // ❌ WRONG
```

**Impact:** The SDK may not initialize correctly when loaded via CDN, causing checkout overlay to fail.

**Fix Required:** Update JavaScript to use `DodoPaymentsCheckout.DodoPayments` when detecting CDN usage, or verify if the CDN exposes both namespaces.

---

## 2. Invoice API Implementation

### ✅ **Correctly Implemented**

1. **API Endpoint**
   - ✅ Uses correct endpoint: `GET /invoices/payments/{payment_id}`
   - ✅ Handles 404 responses (invoice not found)
   - ✅ Handles error responses appropriately

2. **Caching**
   - ✅ Caches invoice URL in order meta (`_dodo_invoice_url`)
   - ✅ Checks cache before making API call

3. **Integration**
   - ✅ Displays invoice link on order details page
   - ✅ Custom My Account endpoint for invoice viewing
   - ✅ Proper permission checks

### ❌ **Critical Issues Found**

#### Issue #2: Invoice API Response Format Mismatch

**Documentation States:**
- Endpoint: `GET /invoices/payments/{payment_id}`
- **Response Type:** `application/pdf` (binary PDF document)
- The response is a PDF blob, not JSON

**API Reference:**
```
Response: 200
Content-Type: application/pdf
Body: PDF document (binary)
```

**Current Implementation:**
```php
// includes/class-dodo-payments-api.php
$response_body = json_decode(wp_remote_retrieve_body($res), true);

// Expects JSON with fields like:
// - invoice_pdf_url
// - pdf_url
// - invoice_url
// - url
// - invoice_pdf
```

**Impact:** The current implementation expects JSON response with URL fields, but the API returns a binary PDF. This will cause the invoice retrieval to fail.

**Fix Required:** 
1. Check if the API actually returns JSON with a URL (documentation may be outdated)
2. OR handle binary PDF response:
   - Save PDF to temporary file
   - Return file URL or serve directly
   - OR check if API has alternative endpoint that returns JSON

**Recommended Approach:**
- First, test the actual API response format
- If it returns JSON with URL → current implementation is correct
- If it returns PDF → need to handle binary response or find alternative endpoint

---

## 3. Additional Observations

### Missing Features (Not Critical)

1. **Checkout Status Check**
   - Documentation shows: `DodoPayments.Checkout.isOpen()`
   - Not implemented in current code
   - Could be useful for preventing duplicate opens

2. **Checkout Close Method**
   - Documentation shows: `DodoPayments.Checkout.close()`
   - Not implemented in current code
   - Could be useful for manual close handling

### Best Practices Compliance

✅ **Following Best Practices:**
- Initializes SDK before opening checkout
- Implements error handling in event callback
- Uses test/live mode correctly
- Handles all relevant events
- Uses valid checkout URLs from create checkout session API

---

## 4. Recommended Fixes

### Priority 1: Critical Fixes

1. **Fix CDN Global Variable** (Overlay Checkout)
   - Update `dodo-checkout-overlay.js` to use `DodoPaymentsCheckout.DodoPayments`
   - OR verify actual CDN behavior and document

2. **Fix Invoice API Response Handling** (Invoice)
   - Test actual API response format
   - Update implementation to handle correct response type
   - If PDF: implement file handling or find JSON endpoint

### Priority 2: Enhancements

1. **Add Checkout Status Check**
   - Implement `DodoPayments.Checkout.isOpen()` check before opening
   - Prevent duplicate overlay opens

2. **Add Manual Close Handler**
   - Implement `DodoPayments.Checkout.close()` method
   - Add UI button to close overlay manually if needed

---

## 5. Testing Checklist

### Overlay Checkout
- [ ] Verify SDK loads correctly from CDN
- [ ] Test initialization with both test and live modes
- [ ] Verify overlay opens correctly
- [ ] Test all event handlers fire correctly
- [ ] Test redirect handling
- [ ] Test error handling

### Invoice API
- [ ] Test actual API response format (JSON vs PDF)
- [ ] Verify invoice URL retrieval works
- [ ] Test invoice caching
- [ ] Test invoice display on order page
- [ ] Test My Account endpoint
- [ ] Test permission checks

---

## Summary

**Critical Issues:** 2
- CDN global variable mismatch (Overlay Checkout)
- Invoice API response format mismatch

**Enhancements:** 2
- Add checkout status check
- Add manual close handler

**Overall Status:** Implementation is mostly correct but has 2 critical issues that need immediate attention before production use.

