# Dodo Payments for WooCommerce - Feature Analysis

## Current Features (v0.4.0)

### Payment Processing
- ✅ **One-time Payments** - Legacy payment API integration
- ✅ **Checkout Sessions API** - Modern checkout experience (when tax ID collection enabled)
- ✅ **Payment Link Generation** - Redirect-based checkout flow
- ✅ **Payment Status Webhooks** - Real-time payment status updates
- ✅ **Payment ID Mapping** - Database mapping between WooCommerce orders and Dodo Payments

### Subscription Management
- ✅ **Subscription Product Support** - Create and sync subscription products
- ✅ **Subscription Lifecycle** - Cancel, suspend, reactivate subscriptions
- ✅ **Subscription Webhooks** - Handle subscription status changes
- ✅ **Renewal Orders** - Automatic renewal order creation
- ✅ **Subscription ID Mapping** - Database mapping for subscriptions
- ✅ **Trial Period Support** - Convert WooCommerce trial periods to Dodo format

### Product Management
- ✅ **Product Synchronization** - Auto-sync WooCommerce products to Dodo Payments
- ✅ **Product Image Sync** - Upload product images to Dodo Payments
- ✅ **Product Updates** - Sync product changes (name, description, price)
- ✅ **Stale Mapping Cleanup** - Auto-clear mappings when products deleted in Dodo
- ✅ **Tax Category Configuration** - Support for digital_products, saas, e_book, edtech
- ✅ **Tax-Inclusive Pricing** - Configurable tax-inclusive option

### Discounts & Coupons
- ✅ **Percentage Discount Codes** - Sync WooCommerce percentage coupons
- ✅ **Coupon Mapping** - Database mapping for coupons
- ✅ **Coupon Updates** - Sync coupon changes to Dodo Payments

### Tax Features
- ✅ **Global Tax Category** - Set default tax category for all products
- ✅ **Tax-Inclusive Pricing** - Global setting for tax-inclusive prices
- ✅ **Tax ID/VAT Collection** - Collect business tax IDs during checkout (via Checkout Sessions)

### Webhook Handling
- ✅ **Payment Webhooks** - payment.succeeded, payment.failed, payment.cancelled, payment.processing
- ✅ **Refund Webhooks** - refund.succeeded, refund.failed
- ✅ **Subscription Webhooks** - subscription.active, subscription.renewed, subscription.on_hold, subscription.cancelled, subscription.failed, subscription.expired
- ✅ **Webhook Signature Verification** - Secure webhook validation
- ✅ **Session ID Fallback** - Find orders by session_id if payment_id mapping missing

### Technical Features
- ✅ **HPOS Compatibility** - High-Performance Order Storage support
- ✅ **Test/Live Mode** - Separate API keys for testing and production
- ✅ **Error Handling** - Comprehensive error logging and user-friendly messages
- ✅ **Database Tables** - Custom tables for product, payment, subscription, and coupon mappings

---

## Missing Features - Recommended Additions

### 🔥 High Priority Features

#### 1. **Overlay Checkout SDK Integration** ⭐
**Status:** Not Implemented  
**Documentation:** https://docs.dodopayments.com/developer-resources/overlay-checkout

**Description:**
Embed the Dodo Payments checkout overlay directly in your WooCommerce checkout page instead of redirecting to an external URL. Provides a seamless, modern checkout experience.

**Benefits:**
- Better user experience (no page redirect)
- Reduced cart abandonment
- Real-time event handling
- Customizable checkout flow

**Implementation Notes:**
- Install `dodopayments-checkout` npm package or use CDN
- Initialize SDK with mode and event handlers
- Replace redirect with `DodoPayments.Checkout.open()` call
- Handle checkout events (opened, closed, payment_page_opened, customer_details_submitted, redirect, error)
- Works with existing Checkout Sessions API

**Code Example:**
```javascript
// Initialize SDK
DodoPayments.Initialize({
  mode: "live", // or "test"
  onEvent: (event) => {
    switch (event.event_type) {
      case "checkout.opened":
        // Handle checkout opened
        break;
      case "checkout.closed":
        // Handle checkout closed
        break;
      case "checkout.error":
        // Handle errors
        break;
    }
  },
});

// Open checkout overlay
DodoPayments.Checkout.open({
  checkoutUrl: checkoutSession.checkout_url
});
```

**WooCommerce Integration:**
- Add JavaScript to checkout page
- Replace `process_payment()` redirect with overlay trigger
- Handle success/failure via event callbacks
- Update order status based on checkout events

---

#### 2. **Manual Refund Creation**
**Status:** Webhook handling exists, but no manual refund UI  
**API:** `POST /refunds`

**Description:**
Allow store admins to create refunds directly from WooCommerce order edit page, syncing refunds to Dodo Payments.

**Benefits:**
- Streamlined refund process
- Better admin experience
- Automatic sync with Dodo Payments

**Implementation:**
- Add "Refund via Dodo Payments" button in order edit page
- Create refund API method in `Dodo_Payments_API` class
- Handle partial and full refunds
- Update order notes and status
- Sync refund status via webhooks

**API Endpoint:**
```php
POST /refunds
{
  "payment_id": "pay_123",
  "amount": 5000, // in cents
  "reason": "Customer request"
}
```

---

#### 3. **Customer Portal Integration**
**Status:** Not Implemented  
**API:** `POST /customers/{customer_id}/customer-portal/session`

**Description:**
Allow customers to manage their subscriptions, view payment history, and update billing information through Dodo Payments Customer Portal.

**Benefits:**
- Self-service subscription management
- Reduced support burden
- Better customer experience

**Implementation:**
- Add "Manage Subscription" link in My Account page
- Create customer portal session API method
- Redirect customers to portal URL
- Handle return URL after portal session

**WooCommerce Integration:**
- Add endpoint: `/my-account/dodo-payments-portal/`
- Create portal session when customer clicks link
- Store customer_id mapping (WooCommerce user → Dodo customer)

---

### 🟡 Medium Priority Features

#### 4. **Product Addons Support**
**Status:** Not Implemented  
**API:** `POST /addons`, `GET /addons`, `PATCH /addons/{id}`

**Description:**
Support for product addons that can be attached to main subscription products. Useful for upsells and feature add-ons.

**Benefits:**
- Upsell opportunities
- Flexible product offerings
- Better revenue optimization

**Implementation:**
- Create addon product type in WooCommerce
- Sync addons to Dodo Payments
- Include addons in checkout session/product cart
- Support addon management in admin

---

#### 5. **Usage-Based Billing**
**Status:** Not Implemented  
**API:** `POST /meters/{meter_id}/events`, `GET /subscriptions/{subscription_id}/usage`

**Description:**
Bill customers based on actual consumption/usage. Send usage events and track consumption for metered billing.

**Benefits:**
- Flexible pricing models
- Pay-per-use billing
- Better for SaaS and API products

**Implementation:**
- Create meter management UI
- Send usage events API method
- Track usage in WooCommerce orders
- Display usage history to customers
- Integration with WooCommerce measurement/usage tracking plugins

**API Example:**
```php
POST /meters/{meter_id}/events
{
  "event_type": "api_call",
  "timestamp": "2025-09-03T10:00:00Z",
  "value": 1,
  "properties": {
    "request_id": "req_xyz789"
  }
}
```

---

#### 6. **Customer Management**
**Status:** Not Implemented  
**API:** `POST /customers`, `GET /customers`, `GET /customers/{id}`, `PATCH /customers/{id}`

**Description:**
Full CRUD operations for customers in Dodo Payments. Sync WooCommerce customers to Dodo Payments.

**Benefits:**
- Better customer data management
- Unified customer records
- Support for customer-specific features

**Implementation:**
- Create customer on first order
- Sync customer updates
- Store customer_id mapping
- Customer lookup/management UI

---

#### 7. **Metadata Support**
**Status:** Not Implemented  
**API:** Available in checkout sessions and payments

**Description:**
Add custom metadata to checkout sessions, payments, and subscriptions for better tracking and integration.

**Benefits:**
- Custom tracking fields
- Integration with other systems
- Better order management

**Implementation:**
- Add metadata field to checkout session creation
- Include WooCommerce order ID, customer ID, etc.
- Store metadata in order meta
- Display metadata in admin

---

#### 8. **Multiple Discount Types**
**Status:** Only percentage discounts supported  
**API:** Discount codes API supports multiple types

**Description:**
Support for fixed amount discounts, free shipping, and other discount types beyond percentage.

**Benefits:**
- More flexible pricing strategies
- Better coupon management
- Support for all WooCommerce discount types

**Implementation:**
- Extend `sync_coupon()` method
- Map WooCommerce discount types to Dodo discount types
- Handle fixed amount discounts
- Support for other discount types

---

#### 9. **Currency Selection / Billing Currency Override**
**Status:** Not Implemented  
**API:** `billing_currency` parameter in checkout sessions

**Description:**
Allow customers to select their preferred billing currency or override the default currency for checkout.

**Benefits:**
- Multi-currency support
- Better international sales
- Currency conversion options

**Implementation:**
- Add currency selector to checkout
- Pass `billing_currency` to checkout session
- Enable `allow_currency_selection` feature flag
- Requires adaptive currency enabled in Dodo account

---

#### 10. **Payment Methods Selection**
**Status:** Not Implemented  
**API:** `payment_methods` parameter in checkout sessions

**Description:**
Specify which payment methods to offer during checkout (card, bank_transfer, etc.).

**Benefits:**
- Control payment options
- Regional payment method support
- Better checkout customization

**Implementation:**
- Add payment methods selector in gateway settings
- Pass `payment_methods` array to checkout session
- Support for multiple payment methods

---

### 🟢 Lower Priority Features

#### 11. **Customer Wallets**
**Status:** Not Implemented  
**API:** `GET /customers/{id}/wallets`, `GET /customers/{id}/wallets/ledger-entries`, `POST /customers/{id}/wallets/ledger-entries`

**Description:**
Manage customer wallets, view balances, and create ledger entries (credits/debits).

**Benefits:**
- Store credit system
- Manual adjustments
- Better customer account management

**Implementation:**
- Display wallet balance in My Account
- Admin UI for wallet management
- Create ledger entries API methods
- Integration with WooCommerce store credit plugins

---

#### 12. **Subscription Upgrades & Downgrades**
**Status:** Not Implemented  
**Documentation:** https://docs.dodopayments.com/developer-resources/subscription-upgrade-downgrade-guide

**Description:**
Allow customers to upgrade or downgrade their subscription plans with prorated billing.

**Benefits:**
- Flexible subscription management
- Better customer retention
- Revenue optimization

**Implementation:**
- Add upgrade/downgrade UI in My Account
- Handle plan changes via API
- Calculate prorated amounts
- Update WooCommerce subscription

---

#### 13. **Purchasing Power Parity (PPP)**
**Status:** Mentioned in code but not configurable  
**API:** `purchasing_power_parity` in product price

**Description:**
Enable purchasing power parity pricing to adjust prices based on customer location.

**Benefits:**
- Better international pricing
- Increased conversion rates
- Fair pricing across regions

**Implementation:**
- Add PPP toggle in product settings
- Enable PPP in product sync
- Requires PPP enabled in Dodo account

---

#### 14. **Refund Receipts**
**Status:** Not Implemented  
**API:** `GET /invoices/refunds/{refund_id}`

**Description:**
Retrieve and display refund receipts for customers and admins.

**Benefits:**
- Better documentation
- Customer transparency
- Compliance requirements

**Implementation:**
- Add refund receipt link in order details
- Display receipt URL from API
- Email receipt to customer

---

## Implementation Priority Recommendations

### Phase 1 (Immediate Value)
1. **Overlay Checkout SDK** - Major UX improvement
2. **Manual Refund Creation** - Essential admin feature
3. **Customer Portal** - High customer value

### Phase 2 (Enhanced Functionality)
4. **Product Addons** - Revenue optimization
5. **Multiple Discount Types** - Better coupon support
6. **Customer Management** - Foundation for other features

### Phase 3 (Advanced Features)
7. **Usage-Based Billing** - Niche but powerful
8. **Currency Selection** - International expansion
9. **Subscription Upgrades/Downgrades** - Advanced subscription management

### Phase 4 (Nice to Have)
10. **Customer Wallets** - Specialized use case
11. **Payment Methods Selection** - Advanced customization
12. **Metadata Support** - Integration enhancement
13. **PPP Support** - International pricing
14. **Refund Receipts** - Documentation feature

---

## Technical Considerations

### Overlay Checkout Implementation
- Requires JavaScript/TypeScript integration
- Can use CDN or npm package
- Need to handle WordPress nonce/security
- Consider compatibility with WooCommerce checkout blocks
- Test with various themes

### API Rate Limits
- Consider rate limiting for product sync
- Batch operations where possible
- Cache product mappings

### Error Handling
- Comprehensive error logging
- User-friendly error messages
- Fallback mechanisms
- Retry logic for failed API calls

### Testing
- Test with WooCommerce Subscriptions plugin
- Test with various product types
- Test webhook handling
- Test refund flows
- Test subscription lifecycle

---

## References

- [Dodo Payments Overlay Checkout Guide](https://docs.dodopayments.com/developer-resources/overlay-checkout)
- [Dodo Payments API Reference](https://docs.dodopayments.com/api-reference)
- [Checkout Sessions API](https://docs.dodopayments.com/developer-resources/checkout-session)
- [Usage-Based Billing Guide](https://docs.dodopayments.com/developer-resources/usage-based-billing-guide)
- [Subscription Upgrade & Downgrade Guide](https://docs.dodopayments.com/developer-resources/subscription-upgrade-downgrade-guide)

---

**Last Updated:** 2025-01-XX  
**Plugin Version:** 0.4.0  
**Analysis Date:** 2025-01-XX

