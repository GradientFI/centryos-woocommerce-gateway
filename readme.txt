=== CentryOS Payment Gateway for WooCommerce ===
Contributors: centryos
Tags: woocommerce, payment, gateway, centryos
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.5.1
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

= Can I sell subscription products? =

Yes. As of 1.5.0 you can tag a simple product as a subscription under its "CentryOS Subscription" tab — set the recurring rate, the billing interval, and an optional free trial. The full cart total is charged once at checkout and only the subscription rate recurs each cycle. View and cancel active subscriptions under WooCommerce > CentryOS Subscriptions. Requires the CentryOS recurring payments API to be enabled for your account.

= What are the minimum requirements? =

* WordPress 5.8 or higher
* PHP 7.4 or higher
* WooCommerce 6.0 or higher

== Changelog ==

= 1.5.1 =
* Added: Pay by Bank — a new option in the gateway's Payment Options that lets customers pay directly from their bank account (open banking) at CentryOS checkout. Opt-in; enable it under WooCommerce > Settings > Payments > CentryOS. Availability depends on your CentryOS/Stripe account (USD / US bank accounts).

= 1.5.0 =
* Added: Subscriptions — tag a simple product as recurring in the new "CentryOS Subscription" product tab (recurring rate, billing interval, and an optional free trial with day/week/month/year units)
* Added: recurring checkout — the full cart total is charged once, then only the subscription rate recurs each cycle; a subscription and one-time products can share the same cart
* Added: subscription tracking with next renewal date, plus a new WooCommerce > CentryOS Subscriptions screen to view subscriptions and cancel them (at the end of the current paid period)
* Added: automatic renewal orders — each renewal creates a linked WooCommerce order at the recurring rate
* Added: "Add subscription to cart" button label on subscription products (shop and product pages)
* Added: handling for COLLECTION.RECURRING.* webhooks and centryos_webhook_subscription_* action hooks for integrations
* Note: requires the CentryOS recurring payments API (cancel endpoint + recurring amount/trial support) enabled for your account

= 1.4.0 =
* Added: Checkout Mode setting — choose between the existing redirect flow and a new embedded mode that renders the CentryOS payment page inside an iframe on the WooCommerce order-pay page. On success the buyer is redirected to the standard order-received page. Success is detected via a `centryos_payment_complete` postMessage event with an AJAX status poll as a fallback. Requires CentryOS framing to be enabled for your domain.
* Changed: in embedded mode, new orders are placed in Pending payment instead of On hold so the order-pay page can render. Redirect mode is unchanged.

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

= 1.5.1 =
Adds an optional Pay by Bank checkout method. No action required for existing stores; enable it under the gateway's Payment Options if you want to offer bank (open banking) payments.

= 1.5.0 =
Adds CentryOS subscriptions: sell products that bill on a recurring schedule, track renewals, and cancel from WooCommerce. Requires the CentryOS recurring payments API (cancel endpoint plus recurring amount/trial support) to be enabled for your account. Existing one-time payment behavior is unchanged.

= 1.4.0 =
Adds an opt-in embedded checkout mode that keeps buyers on your site inside an iframe. Existing installs default to the redirect flow — no action required unless you want to enable embedded.

= 1.3.0 =
Behavior change: webhooks with non-success/non-failure statuses no longer mark orders as failed. Failure handling is now idempotent. All webhook events are logged unconditionally.

= 1.1.0 =
Initial release of CentryOS Payment Gateway for WooCommerce.

