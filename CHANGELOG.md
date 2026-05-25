# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- (Future changes will be listed here)

## [1.4.0] - 2026-05-25

### Added
- Embedded checkout mode: a new `Checkout Mode` setting renders the CentryOS payment page inside an iframe on the WooCommerce order-pay page instead of redirecting off-site. On payment success the buyer is redirected to the standard WooCommerce order-received page. Detection uses a `postMessage` listener (event `centryos_payment_complete`, fast path) with an AJAX poll of the order status as a resilient fallback once the webhook flips the order to paid. Requires CentryOS framing to be enabled for the merchant origin.
- New `assets/js/centryos-embed.js` script and `centryos_check_order_status` admin-ajax endpoint that powers the embedded flow.

### Changed
- In embedded mode, newly-created orders are placed in `pending` (Pending payment) instead of `on-hold` so WooCommerce's order-pay page actually renders the receipt hook. Redirect mode behavior is unchanged.

## [1.3.0] - 2026-05-18

### Added
- Refund support via the gateway
- Cart items and address details forwarded with payment link requests
- Unconditional webhook logging to PHP `error_log` and WooCommerce Status > Logs (source: `centryos-webhook`); no longer gated by `WP_DEBUG`

### Changed
- Webhook handler now requires an explicit failure status (`FAILED`, `FAILURE`, `DECLINED`, `CANCELLED`, `CANCELED`, `EXPIRED`) before marking an order failed. Unknown or pending statuses are acknowledged with HTTP 200 without mutating the order
- Failed-payment processing is idempotent — duplicate failure webhooks for an already-failed/cancelled/refunded order short-circuit without re-running `update_status`, `wc_increase_stock_levels`, or the `centryos_webhook_payment_failed` action
- Success-status detection normalized to a single case-insensitive check
- JSON payload validation runs after signature verification and returns an explicit `400 Invalid JSON payload` on malformed bodies

### Removed
- Dead `get_credential` instance method on the all-static `CentryOS_Webhook_Handler` class

## [1.2.0] - 2025-11-12

### Added
- API Environment selector dropdown (Staging/Production) in gateway settings
- Support for switching between staging and production API endpoints
- Comprehensive .gitignore file for WordPress plugin development

### Fixed
- Payment gateway settings form fields not appearing in WooCommerce admin
- WooCommerce Blocks compatibility issues ("incompatible with block based checkout")
- JavaScript integration using correct gateway name and settings keys
- Payment options array handling to prevent type errors

### Changed
- Updated API client to support dynamic environment selection
- Improved blocks support initialization with proper safety checks
- Enhanced form fields with proper field types and descriptions

## [1.1.0] - 2025-11-11

### Added
- Separated concerns into multiple class files for better maintainability
  - `CentryOS_API_Client` class for API communication
  - `CentryOS_Blocks_Support` class for WooCommerce Blocks integration
  - `CentryOS_Webhook_Handler` class for webhook processing
- Support for wp-config.php credentials (enhanced security)
  - `CENTRYOS_CLIENT_ID` constant
  - `CENTRYOS_API_SECRET` constant
  - `CENTRYOS_WEBHOOK_SECRET` constant
- Proper internationalization support with text domain
- `uninstall.php` for proper plugin cleanup
- `CHANGELOG.md` for version tracking
- Payment link expiration configuration (days)
- Customer pays processing fee option
- Multiple payment method support (Card, Google Pay, Apple Pay, Cash App)

### Changed
- Restructured codebase into organized directory structure
  - `/includes/` for class files
  - `/assets/` for CSS, JS, and images
  - `/languages/` for translation files
- Improved error handling and logging throughout
- Updated webhook endpoint to use custom REST API namespace
- Converted `customerPays` to boolean in API payload
- Fixed payment options multiselect to use proper key-value pairs
- Enhanced admin settings UI with better field descriptions

### Fixed
- Payment options multiselect field handling
- Webhook signature verification
- API endpoint URL construction

### Security
- Added support for storing credentials in wp-config.php (outside database)
- Improved admin panel to show when credentials are from wp-config.php
- Added proper sanitization and escaping throughout codebase
- Password fields for sensitive credentials

## [1.0.3] - 2025-11-11

### Added
- Initial release
- Basic payment gateway functionality
- Webhook support for payment status updates
- WooCommerce Blocks compatibility
- Payment link creation via CentryOS API
- Order status management (on-hold → processing/complete)
- Redirect to CentryOS hosted payment page
- Return URL handling after payment

### Fixed
- Initial release - no fixes needed

---

## Version History

- **1.3.0** - Refunds, payment-link cart/address payload, hardened webhook handling and unconditional logging
- **1.2.0** - API environment selector, blocks compatibility fixes, form fields improvements
- **1.1.0** - Major refactoring with improved security and structure
- **1.0.3** - Initial public release

## Upgrade Notes

### Upgrading to 1.3.0

- **Breaking Changes**: Webhook semantics changed — previously, *any* non-success webhook payload would mark the order as failed. The handler now only marks orders failed on explicit failure statuses; pending/unknown statuses return 200 without changing order state. If the CentryOS sender relies on the old behavior to fail orders via custom statuses, those statuses must be added to the failure allow-list in `is_payment_failed()`.
- **Recommended Actions**:
  - Verify webhook URL on the CentryOS side: `/wp-json/v1/wh/centryos/payment-complete`
  - Confirm `CENTRYOS_WEBHOOK_SECRET` is defined in `wp-config.php`
  - Check **WooCommerce > Status > Logs** (source: `centryos-webhook`) after the first webhook hit to confirm logging is wired
- **Deprecations**: None

### Upgrading to 1.2.0

- **Breaking Changes**: None
- **Recommended Actions**:
  - Configure API Environment (Staging/Production) in gateway settings
  - Verify WooCommerce Blocks checkout compatibility
  - Test payment gateway settings form fields are visible
- **Deprecations**: None

### Upgrading to 1.1.0

- **Breaking Changes**: None
- **Recommended Actions**:
  - Review and migrate credentials to wp-config.php for enhanced security
  - Verify payment gateway settings after upgrade
  - Test checkout flow with WooCommerce Blocks if using block-based checkout
- **Deprecations**: None
