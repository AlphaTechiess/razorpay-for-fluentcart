<?php
namespace RazorpayFluentCart;

if (!defined('ABSPATH')) exit;

class API {
    private Settings $settings;
    private static string $userAgent = '';

    public function __construct(Settings $settings) {
        $this->settings = $settings;
    }

    public function request(string $method, string $path, array $body = [], array $headers = []) {
        $key    = $this->settings->apiKey();
        $secret = $this->settings->apiSecret();
        if (!$key || !$secret) {
            return new \WP_Error('rpfc_credentials', __('Razorpay Key ID and Key Secret are required.', 'razorpay-for-fluentcart'));
        }

        if (self::$userAgent === '') {
            self::$userAgent = 'FluentCart-Razorpay/' . (defined('RPFC_VERSION') ? RPFC_VERSION : '1.0') . '; WordPress/' . get_bloginfo('version');
        }

        $verb = strtoupper($method);
        $url  = 'https://api.razorpay.com/v1' . $path;
        if ($verb === 'GET' && !empty($body)) $url = add_query_arg($body, $url);

        $defaultHeaders = [
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic Auth header required by Razorpay REST API.
            'Authorization' => 'Basic ' . base64_encode($key . ':' . $secret),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => self::$userAgent,
        ];

        $args = [
            'method'  => $verb,
            'timeout' => 30,
            'headers' => $headers ? array_merge($defaultHeaders, $headers) : $defaultHeaders,
        ];
        if (!empty($body) && in_array($verb, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) return $res;

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw  = (string)wp_remote_retrieve_body($res);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw_response' => $raw];

        if ($code >= 400) {
            $err = is_array($data['error'] ?? null) ? $data['error'] : [];
            $msg = (string)($err['description'] ?? ($data['message'] ?? ($err['reason'] ?? ($err['code'] ?? ''))));
            if ($msg === '') {
                if ($code === 401) {
                    $msg = __('Razorpay authentication failed. Please verify your Key ID and Key Secret.', 'razorpay-for-fluentcart');
                } else {
                    /* translators: %d: HTTP status code returned by the Razorpay API. */
                    $msg = sprintf(__('Razorpay API request failed (HTTP %d).', 'razorpay-for-fluentcart'), $code);
                }
            }
            return new \WP_Error('rpfc_api', $msg, ['status' => $code, 'response' => $data]);
        }

        return $data;
    }

    public function createOrder(array $data) { return $this->request('POST', '/orders', $data); }
    public function getOrderPayments(string $orderId) { return $this->request('GET', '/orders/' . rawurlencode($orderId) . '/payments'); }
    public function getPayment(string $id) { return $this->request('GET', '/payments/' . rawurlencode($id)); }
    public function capturePayment(string $paymentId, int $amount, string $currency) {
        return $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/capture', ['amount' => $amount, 'currency' => strtoupper($currency)]);
    }
    public function createRefund(string $paymentId, int $amount, string $receipt = '', array $notes = [], string $idempotencyKey = '') {
        $body = ['amount' => $amount];
        if ($receipt !== '') $body['receipt'] = substr($receipt, 0, 40);
        if ($notes) $body['notes'] = $notes;
        $headers = $idempotencyKey !== '' ? ['X-Refund-Idempotency' => $idempotencyKey] : [];
        return $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/refund', $body, $headers);
    }
    public function createPlan(array $data) { return $this->request('POST', '/plans', $data); }
    public function createSubscription(array $data) { return $this->request('POST', '/subscriptions', $data); }
    public function cancelSubscription(string $id, bool $cancelAtCycleEnd = false) {
        return $this->request('POST', '/subscriptions/' . rawurlencode($id) . '/cancel', ['cancel_at_cycle_end' => $cancelAtCycleEnd]);
    }
}
