# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- (Future changes will be listed here)

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

- **1.2.0** - API environment selector, blocks compatibility fixes, form fields improvements
- **1.1.0** - Major refactoring with improved security and structure
- **1.0.3** - Initial public release

## Upgrade Notes

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
