<?php
namespace RazorpayFluentCart;

class API
{
    /** @var Settings */
    private $settings;

    /** @var string */
    private $authHeader = '';

    /** @var string */
    private static $userAgent = '';

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function request($method, $path, $body = [], $headers = [])
    {
        $key = $this->settings->apiKey();
        $secret = $this->settings->apiSecret();

        if (!$key || !$secret) {
            return new \WP_Error('rpfc_credentials', __('Razorpay Key ID and Key Secret are required.', 'razorpay-for-fluentcart'));
        }

        if (!$this->authHeader) {
            $this->authHeader = 'Basic ' . base64_encode($key . ':' . $secret);
        }

        if (!self::$userAgent) {
            self::$userAgent = 'FluentCart-Razorpay/' . (defined('RPFC_VERSION') ? RPFC_VERSION : '1.0.0') . '; WordPress/' . get_bloginfo('version');
        }

        $method = strtoupper($method);
        $url = 'https://api.razorpay.com/v1' . $path;

        if ($method === 'GET' && !empty($body)) {
            $url = add_query_arg($body, $url);
        }

        $defaultHeaders = [
            'Authorization' => $this->authHeader,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => self::$userAgent,
        ];

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => $headers ? array_merge($defaultHeaders, $headers) : $defaultHeaders,
        ];

        if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['raw_response' => $raw];
        }

        if ($code >= 400) {
            $error = isset($data['error']) && is_array($data['error']) ? $data['error'] : [];
            $message = $error['description']
                ?? $data['message']
                ?? $error['reason']
                ?? $error['code']
                ?? '';

            if (!$message) {
                if ($code === 401) {
                    $message = __('Razorpay authentication failed. Please verify your Key ID and Key Secret in settings.', 'razorpay-for-fluentcart');
                } elseif ($code === 404) {
                    $message = __('The requested Razorpay entity was not found.', 'razorpay-for-fluentcart');
                } else {
                    $message = sprintf(__('Razorpay API request failed (HTTP %d).', 'razorpay-for-fluentcart'), $code);
                }
            }

            $details = [];
            if (!empty($error['code'])) {
                $details[] = (string) $error['code'];
            }
            if (!empty($error['field'])) {
                $details[] = sprintf(__('field: %s', 'razorpay-for-fluentcart'), (string) $error['field']);
            }
            if ($details) {
                $message .= ' [' . implode(', ', $details) . ']';
            }

            return new \WP_Error('rpfc_api', $message, [
                'status'   => $code,
                'response' => $data,
            ]);
        }

        return $data;
    }

    public function createOrder(array $data)
    {
        return $this->request('POST', '/orders', $data);
    }

    public function getOrder($id)
    {
        return $this->request('GET', '/orders/' . rawurlencode($id));
    }

    public function getOrderPayments($orderId)
    {
        return $this->request('GET', '/orders/' . rawurlencode($orderId) . '/payments');
    }

    public function capturePayment($paymentId, $amount, $currency)
    {
        return $this->request('POST', '/payments/' . rawurlencode($paymentId) . '/capture', [
            'amount'   => (int) $amount,
            'currency' => strtoupper($currency),
        ]);
    }

    public function getPayment($id)
    {
        return $this->request('GET', '/payments/' . rawurlencode($id));
    }

    public function createRefund($paymentId, $amount, $receipt = '', $notes = [], $idempotencyKey = '')
    {
        $body = ['amount' => (int) $amount];
        if ($receipt !== '') {
            $body['receipt'] = substr((string) $receipt, 0, 40);
        }
        if (!empty($notes)) {
            $body['notes'] = $notes;
        }

        $headers = [];
        if ($idempotencyKey !== '') {
            $headers['X-Refund-Idempotency'] = $idempotencyKey;
        }

        return $this->request(
            'POST',
            '/payments/' . rawurlencode($paymentId) . '/refund',
            $body,
            $headers
        );
    }

    public function createPlan(array $data)
    {
        return $this->request('POST', '/plans', $data);
    }

    public function getPlan($planId)
    {
        return $this->request('GET', '/plans/' . rawurlencode($planId));
    }

    public function createSubscription(array $data)
    {
        return $this->request('POST', '/subscriptions', $data);
    }

    public function getSubscription($id)
    {
        return $this->request('GET', '/subscriptions/' . rawurlencode($id));
    }

    public function cancelSubscription($id, $cancelAtCycleEnd = false)
    {
        return $this->request('POST', '/subscriptions/' . rawurlencode($id) . '/cancel', [
            'cancel_at_cycle_end' => (bool) $cancelAtCycleEnd,
        ]);
    }
}
