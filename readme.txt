=== CentryOS Payment Gateway for WooCommerce ===
Contributors: centryos
Tags: woocommerce, payment, gateway, centryos
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments via CentryOS hosted payment links. Fully compatible with WooCommerce Blocks.

== Description ==

CentryOS Payment Gateway for WooCommerce allows you to accept payments through CentryOS hosted payment links. This plugin is fully compatible with WooCommerce Blocks and provides a seamless payment experience for your customers.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/centryos-woocommerce-gateway` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Configure the plugin settings in WooCommerce > Settings > Payments > CentryOS.
4. Enter your CentryOS API credentials to start accepting payments.

== Frequently Asked Questions ==

= Does this plugin work with WooCommerce Blocks? =

Yes, this plugin is fully compatible with WooCommerce Blocks and the new checkout experience.

= What are the minimum requirements? =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* WooCommerce 6.0 or higher

== Changelog ==

= Unreleased =
* Added: Checkout Mode setting — choose between the existing redirect flow and a new embedded mode that renders the CentryOS payment page inside an iframe on the WooCommerce order-pay page. On success the buyer is redirected to the standard order-received page. Requires CentryOS framing to be enabled for your domain.

= 1.3.0 =
* Added: refund support via the gateway
* Added: cart items and address details forwarded with payment links
* Added: unconditional webhook logging (PHP error_log + WooCommerce > Status > Logs, source `centryos-webhook`)
* Changed: webhook now requires an explicit failure status (FAILED/FAILURE/DECLINED/CANCELLED/EXPIRED) before marking orders failed; unknown/pending events are acknowledged without mutating the order
* Changed: failed-payment processing is now idempotent — duplicate failure webhooks no longer re-mark the order or re-fire integration hooks
* Fixed: case-sensitivity inconsistency in webhook success detection
* Fixed: invalid JSON payloads now return an explicit 400 instead of falling through validation
* Removed: dead `get_credential` method from the webhook handler

= 1.2.0 =
* Added: API Environment selector (Staging/Production) in gateway settings
* Fixed: WooCommerce Blocks compatibility and settings form field visibility

= 1.1.0 =
* Initial release

== Upgrade Notice ==

= 1.3.0 =
Behavior change: webhooks with non-success/non-failure statuses no longer mark orders as failed. Failure handling is now idempotent. All webhook events are logged unconditionally.

= 1.1.0 =
Initial release of CentryOS Payment Gateway for WooCommerce.

