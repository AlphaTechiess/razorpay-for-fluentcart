<?php
/**
 * Plugin Name: Razorpay for FluentCart
 * Description: Razorpay payment gateway for FluentCart with one-time payments, subscriptions, refunds, and zero-trust webhook reconciliation.
 * Author: FluentCart Team
 * Author URI: https://fluentcart.com/about-us
 * Version: 1.0
 * Requires Plugins: fluent-cart
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: razorpay-for-fluentcart
 */

if (!defined('ABSPATH')) exit;

defined('RPFC_VERSION') || define('RPFC_VERSION', '1.0');
defined('RPFC_DIR') || define('RPFC_DIR', plugin_dir_path(__FILE__));
defined('RPFC_URL') || define('RPFC_URL', plugin_dir_url(__FILE__));

function rpfc_get_gateway(): ?\RazorpayFluentCart\Gateway {
    static $gateway = null;
    if ($gateway === null) {
        if (!class_exists(\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::class)) {
            return null;
        }
        require_once RPFC_DIR . 'includes/Settings.php';
        require_once RPFC_DIR . 'includes/API.php';
        require_once RPFC_DIR . 'includes/Gateway.php';
        $gateway = new \RazorpayFluentCart\Gateway();
    }
    return $gateway;
}

add_action('init', static function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bootstrap loader only; AJAX handlers verify nonces and IPN verifies HMAC signatures.
    $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
    if (wp_doing_ajax() && in_array($action, ['rpfc_confirm_payment', 'rpfc_payment_status', 'rpfc_ipn'], true)) {
        rpfc_get_gateway();
        return;
    }

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public FluentCart IPN listener route; webhook signature is verified in Gateway::handleIPN().
    $is_fc_route = isset($_GET['fluent-cart']) || isset($_GET['fluent_cart']);
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public FluentCart IPN listener route; webhook signature is verified in Gateway::handleIPN().
    $method = isset($_GET['method']) ? sanitize_key(wp_unslash($_GET['method'])) : '';
    if ($is_fc_route && $method === 'razorpay') {
        rpfc_get_gateway();
    }
}, 5);

add_action('fluent_cart/register_payment_methods', static function ($data = null) {
    $gateway = rpfc_get_gateway();
    if (!$gateway) return;
    if (is_object($data)) {
        if (method_exists($data, 'registerCustomPaymentMethod')) { $data->registerCustomPaymentMethod('razorpay', $gateway); return; }
        if (method_exists($data, 'register')) { $data->register('razorpay', $gateway); return; }
    }
    if (is_array($data) && is_object($data['gatewayManager'] ?? null) && method_exists($data['gatewayManager'], 'register')) {
        $data['gatewayManager']->register('razorpay', $gateway);
        return;
    }
    if (function_exists('fluent_cart_api')) {
        fluent_cart_api()->registerCustomPaymentMethod('razorpay', $gateway);
    }
});

add_action('admin_notices', static function () {
    if (!current_user_can('activate_plugins') || class_exists(\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway::class)) return;
    echo '<div class="notice notice-error is-dismissible"><p>'
        . esc_html__('Razorpay for FluentCart requires the FluentCart plugin to be installed and activated.', 'razorpay-for-fluentcart')
        . '</p></div>';
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    if (function_exists('fluent_cart_api')) {
        array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?page=fluent-cart#/settings/payments/razorpay')) . '">' . esc_html__('Settings', 'razorpay-for-fluentcart') . '</a>');
    }
    return $links;
});
