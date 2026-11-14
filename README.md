# Razorpay for FluentCart

[![License: GPL v3](https://img.shields.io/badge/License-GPL%20v3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.6%2B-21759b.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net)
[![FluentCart](https://img.shields.io/badge/FluentCart-Compatible-0c83e2.svg)](https://fluentcart.com)
[![Bundle Size](https://img.shields.io/badge/Size-%3C%2098%20KB-success.svg)](#architecture--performance)

The official **Razorpay Payment Gateway Addon for FluentCart**. Built from the ground up for high-conversion checkout performance, rock-solid recurring billing, and zero-trust webhook synchronization with zero bloat.

---

## ✨ Features

- **⚡ Modern Checkout Embed**: Seamless inline payment method showcase displaying Cards, UPI (BHIM), Net Banking, and Wallets with zero skeleton loading delay.
- **🔄 Complete Subscription Engine**: Native recurring billing supporting fixed cycles, indefinite renewals, free trials, setup fees, and automated cancellations.
- **🛡️ Zero-Trust Webhooks**: Secure, tamper-proof webhook handling powered by HMAC-SHA256 signature verification with fallback API reconciliation.
- **💸 Automated Refunds**: Process full or partial refunds directly inside your FluentCart admin dashboard with real-time balance safeguards and idempotency protection.
- **🎛️ Dual-Mode Credentials**: Dedicated tabbed management for Live and Test credentials that automatically sync with FluentCart's store mode.
- **🚀 Ultra Lightweight**: Under **98 KB** total footprint with zero external dependencies, zero SDK bloat, and pure vanilla JavaScript.
- **🧹 Clean Lifecycle & Uninstaller**: Leaves zero database footprint upon plugin deletion across single-site and WordPress multisite installations.

---

## 💳 Supported Payment Methods

Customers can pay using any major Indian or international payment method supported by Razorpay:

| Method | Description |
| :--- | :--- |
| **Credit / Debit Cards** | Visa, MasterCard, RuPay, Maestro, American Express, Diners Club |
| **UPI** | Google Pay, PhonePe, Paytm, BHIM, and any UPI app / VPA |
| **Net Banking** | 50+ Indian commercial and public sector banks |
| **Wallets** | PhonePe, Mobikwik, Freecharge, Airtel Money, and more |
| **International Cards** | Global currencies supported via Razorpay International Payments |

---

## 📋 Requirements

- **WordPress**: 5.6 or higher
- **PHP**: 7.4 or higher
- **FluentCart**: Installed and activated
- **Razorpay Account**: Active account with Key ID and Key Secret ([Sign up](https://razorpay.com))

---

## 🚀 Installation

1. Download the latest release `.zip` file from the [Releases](https://github.com/fluentcart/razorpay-for-fluent-cart/releases) section.
2. In your WordPress admin dashboard, navigate to **Plugins &rarr; Add New &rarr; Upload Plugin**.
3. Select the downloaded `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.

---

## ⚙️ Configuration

### 1. Enter Credentials
1. Go to **FluentCart &rarr; Settings &rarr; Payment Methods &rarr; Razorpay**.
2. Toggle the gateway status to **Active**.
3. Switch between the **Live credentials** and **Test credentials** tabs to input:
   - **Key ID** (`rzp_live_...` or `rzp_test_...`)
   - **Key Secret**
   - **Webhook Secret** (optional, recommended)
4. Click **Save Settings**.

### 2. Configure Webhooks in Razorpay Dashboard
1. Copy the **Webhook URL** displayed in the FluentCart Razorpay settings.
2. Open your [Razorpay Dashboard](https://dashboard.razorpay.com/) and go to **Settings &rarr; Webhooks**.
3. Click **Add New Webhook** and paste the URL.
4. Enter your **Webhook Secret** (must match the secret entered in FluentCart).
5. Under **Active Events**, select the following events:
   - `payment.authorized`
   - `payment.captured`
   - `payment.failed`
   - `refund.processed`
   - `subscription.authenticated`
   - `subscription.activated`
   - `subscription.charged`
   - `subscription.cancelled`
   - `subscription.halted`
   - `subscription.completed`
6. Click **Save Webhook**.

---

## 🔒 Security & Reliability

- **HMAC-SHA256 Signature Verification**: Every checkout completion and incoming webhook payload is verified using cryptographically secure HMAC-SHA256 hashing.
- **Zero-Trust Fallback**: If a webhook secret is omitted, the gateway queries Razorpay's authoritative REST API directly before fulfilling any order or subscription.
- **Idempotency Protection**: All refund and order transactions employ unique idempotency tokens, preventing accidental duplicate charges or refunds even on network retries.

---

## 🛠️ Developer Hooks & Extensibility

### Currency Filter
Customize supported currencies via the WordPress filter:
```php
add_filter('fluent_cart/razorpay_supported_currencies', function ($currencies) {
    // Add custom currency code if supported by your Razorpay account
    $currencies[] = 'EUR';
    return $currencies;
});
```

---

## 📄 License

This plugin is licensed under the [GNU General Public License v3.0 or later](https://www.gnu.org/licenses/gpl-3.0.html).
