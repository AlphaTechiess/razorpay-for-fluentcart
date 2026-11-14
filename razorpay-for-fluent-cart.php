<?php
/**
 * Plugin Name: Razorpay for FluentCart
 * Description: Production-grade Razorpay payment gateway for FluentCart with one-time payments, subscriptions, refunds, and zero-trust webhook reconciliation.
 * Author: FluentCart Team
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author URI: https://fluentcart.com/about-us
 * License: GPLv2 or later
 * Text Domain: razorpay-for-fluentcart
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RPFC_VERSION', '1.0.0');
define('RPFC_DIR', plugin_dir_path(__FILE__));
define('RPFC_URL', plugin_dir_url(__FILE__));

if (is_dir(RPFC_DIR . 'languages')) {
    add_action('init', function () {
        load_plugin_textdomain(
            'razorpay-for-fluentcart',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    });
}

/**
 * Singleton gateway loader.
 *
 * @return \RazorpayFluentCart\Gateway|null
 */
function rpfc_get_gateway() {
    static $gateway = null;
    if ($gateway === null) {
        if (!class_exists('\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway')) {
            return null;
        }
        require_once RPFC_DIR . 'includes/Settings.php';
        require_once RPFC_DIR . 'includes/API.php';
        require_once RPFC_DIR . 'includes/Gateway.php';
        $gateway = new \RazorpayFluentCart\Gateway();
    }
    return $gateway;
}

/**
 * Early boot for Razorpay AJAX actions and IPN webhooks.
 */
add_action('init', function () {
    if (wp_doing_ajax() && isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['rpfc_confirm_payment', 'fluent_cart_razorpay_confirm_payment', 'rpfc_payment_status', 'rpfc_ipn'], true)) {
        rpfc_get_gateway();
    } elseif (isset($_GET['fluent-cart']) && isset($_GET['method']) && $_GET['method'] === 'razorpay') {
        rpfc_get_gateway();
    }
}, 5);

/**
 * Register Razorpay payment method with FluentCart.
 */
add_action('fluent_cart/register_payment_methods', function ($data = null) {
    $gateway = rpfc_get_gateway();
    if (!$gateway) {
        return;
    }

    if (is_object($data)) {
        if (method_exists($data, 'registerCustomPaymentMethod')) {
            $data->registerCustomPaymentMethod('razorpay', $gateway);
            return;
        }
        if (method_exists($data, 'register')) {
            $data->register('razorpay', $gateway);
            return;
        }
    }

    if (is_array($data) && isset($data['gatewayManager']) && is_object($data['gatewayManager']) && method_exists($data['gatewayManager'], 'register')) {
        $data['gatewayManager']->register('razorpay', $gateway);
        return;
    }

    if (function_exists('fluent_cart_api')) {
        fluent_cart_api()->registerCustomPaymentMethod('razorpay', $gateway);
    }
});

/**
 * Admin notice if FluentCart is not active.
 */
add_action('admin_notices', function () {
    if (!class_exists('\FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway') && current_user_can('activate_plugins')) {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Razorpay for FluentCart requires the FluentCart plugin to be installed and activated.', 'razorpay-for-fluentcart')
            . '</p></div>';
    }
});

/**
 * Register official WordPress uninstall hook as fallback safety net.
 */
function rpfc_plugin_uninstall() {
    require_once RPFC_DIR . 'uninstall.php';
}
register_uninstall_hook(__FILE__, 'rpfc_plugin_uninstall');
