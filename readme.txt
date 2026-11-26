=== Razorpay for FluentCart ===
Contributors: fluentcart
Tags: razorpay, fluentcart, payment gateway, upi, subscriptions
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept one-time payments, subscriptions, and refunds in FluentCart via Razorpay with UPI, cards, netbanking, wallets, and webhook sync.

== Description ==

Razorpay for FluentCart integrates Razorpay Checkout directly into your FluentCart store. Accept payments via UPI, Credit/Debit Cards, NetBanking, and Wallets with support for one-time payments, recurring subscriptions, refunds, and real-time webhook verification.

= Key Features =

* **One-Time Payments & Subscriptions:** Full support for standard orders and recurring subscription billing.
* **Multiple Payment Methods:** Accept UPI, Credit/Debit Cards, NetBanking, and Wallets inside the Razorpay Checkout modal.
* **Live & Test Modes:** Switch seamlessly between Live and Test credentials.
* **Zero-Trust Verification:** Server-side HMAC signature validation and API verification before marking orders paid.
* **Automated Webhook Sync:** Real-time synchronization for payment captures, failures, refunds, and subscription lifecycle events.
* **Clean Uninstall:** Removes stored gateway credentials on uninstall without deleting FluentCart orders or transaction history.

= External Services =

This plugin connects to Razorpay's API (`https://api.razorpay.com`) and loads Razorpay Checkout (`https://checkout.razorpay.com/v1/checkout.js`) to process payments, manage subscriptions, and issue refunds. Customer name, email, phone number, and order details are transmitted to Razorpay during checkout.

* [Razorpay Terms of Use](https://razorpay.com/terms/)
* [Razorpay Privacy Policy](https://razorpay.com/privacy/)

== Installation ==

1. Ensure that **FluentCart** is installed and activated.
2. Upload the `razorpay-for-fluentcart` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP via **Plugins > Add New**.
3. Activate **Razorpay for FluentCart** in WordPress.
4. Navigate to **FluentCart > Settings > Payment Settings > Razorpay**.
5. Enter your Razorpay Key ID, Key Secret, and Webhook Secret, and configure the provided Webhook URL in your Razorpay Dashboard.

== Frequently Asked Questions ==

= Does this plugin require FluentCart? =

Yes. FluentCart must be installed and activated for this payment gateway to work.

= Does it support recurring subscriptions? =

Yes. Razorpay Plans and Subscriptions are supported out of the box when webhooks are configured.

== Changelog ==

= 1.0 =
* Initial release with one-time payments, subscriptions, refunds, and webhook reconciliation.
