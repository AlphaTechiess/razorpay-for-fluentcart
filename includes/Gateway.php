<?php
namespace RazorpayFluentCart;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Payments\Refund;

class Gateway extends AbstractPaymentGateway {
    protected bool $isBooted = false;
    protected static array $runtimePlanCache = [];
    private ?API $apiInstance = null;

    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'custom_payment', 'subscriptions', 'system_subscription'];

    public function __construct() {
        parent::__construct(new Settings());
        $this->boot();
    }

    public function init(): void {
        parent::init();
        $this->boot();
    }

    private function api(): API {
        return $this->apiInstance ??= new API($this->settings);
    }

    public function boot(): void {
        if ($this->isBooted) return;
        $this->isBooted = true;

        add_action('wp_ajax_rpfc_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_nopriv_rpfc_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_rpfc_payment_status', [$this, 'paymentStatus']);
        add_action('wp_ajax_nopriv_rpfc_payment_status', [$this, 'paymentStatus']);
        add_action('wp_ajax_rpfc_ipn', [$this, 'handleIPN']);
        add_action('wp_ajax_nopriv_rpfc_ipn', [$this, 'handleIPN']);
        add_action('fluent_cart/handle_payment_ipn_razorpay', [$this, 'handleIPN']);
        add_action('fluent_cart/subscription_cancelled', [$this, 'cancelVendorSubscription'], 20, 1);
        add_action('fluent_cart/subscription_canceled', [$this, 'cancelVendorSubscription'], 20, 1);
    }

    public function meta(): array {
        $iconFile = RPFC_DIR . 'assets/icon.svg';
        $iconUrl  = RPFC_URL . 'assets/icon.svg' . (file_exists($iconFile) ? '?v=' . filemtime($iconFile) : '');

        return [
            'title'              => __('Razorpay', 'razorpay-for-fluentcart'),
            'route'              => 'razorpay',
            'slug'               => 'razorpay',
            'label'              => 'Razorpay',
            'description'        => __('Accept cards, UPI, netbanking, wallets, and subscriptions with Razorpay.', 'razorpay-for-fluentcart'),
            'logo'               => $iconUrl,
            'icon'               => $iconUrl,
            'brand_color'        => '#0C0D4D',
            'status'             => $this->settings->isActive(),
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function has(string $feature): bool { return in_array($feature, $this->supportedFeatures, true); }
    public function fields(): array { return $this->settings->getFields($this); }
    public function getWebhookInstructions(): array { return $this->settings->getWebhookInstructions(); }
    public function getEnqueueStyleSrc(): array { return []; }

    public function getLocalizeData(): array {
        $upiFile = RPFC_DIR . 'assets/upi.svg';
        $upiUrl  = RPFC_URL . 'assets/upi.svg' . (file_exists($upiFile) ? '?v=' . filemtime($upiFile) : '');

        return [
            'fct_razorpay_data' => [
                'api_key'       => $this->settings->apiKey(),
                'checkout_type' => 'modal',
                'ajax_url'      => admin_url('admin-ajax.php'),
                'confirm_nonce' => wp_create_nonce('rpfc_confirm'),
                'upi_icon'      => $upiUrl,
                'translations'  => [
                    'Processing payment...'     => __('Processing payment...', 'razorpay-for-fluentcart'),
                    'Place Order'               => __('Place Order', 'razorpay-for-fluentcart'),
                    'Available payment methods' => __('Available payment methods', 'razorpay-for-fluentcart'),
                    'Cards'                     => __('Cards', 'razorpay-for-fluentcart'),
                    'UPI'                       => __('UPI', 'razorpay-for-fluentcart'),
                    'Net Banking'               => __('Net Banking', 'razorpay-for-fluentcart'),
                    'Wallets'                   => __('Wallets', 'razorpay-for-fluentcart'),
                ],
            ],
        ];
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array {
        return [
            ['handle' => 'fluent-cart-razorpay-checkout', 'src' => 'https://checkout.razorpay.com/v1/checkout.js'],
            ['handle' => 'fluent-cart-razorpay-custom', 'src' => RPFC_URL . 'assets/checkout.min.js', 'deps' => ['fluent-cart-razorpay-checkout'], 'version' => RPFC_VERSION],
        ];
    }

    public function getOrderInfo($data = null) {
        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'razorpay-for-fluentcart'),
            'data'         => [],
            'payment_args' => [
                'api_key'       => $this->settings->apiKey(),
                'checkout_type' => 'modal',
            ],
        ], 200);
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance) {
        if (!$this->settings->isConfigured()) {
            return ['status' => 'failed', 'message' => __('Razorpay is not fully configured. Please enter Key ID and Key Secret in settings.', 'razorpay-for-fluentcart')];
        }
        return $paymentInstance->subscription
            ? $this->createSubscriptionCheckout($paymentInstance)
            : $this->createOneTimeCheckout($paymentInstance);
    }

    private function createOneTimeCheckout(PaymentInstance $paymentInstance) {
        $order       = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        if (!$order || !$transaction) return ['status' => 'failed', 'message' => __('Unable to initialize payment transaction.', 'razorpay-for-fluentcart')];

        $currency = strtoupper(trim((string)$transaction->currency));
        $amount   = (int)round($transaction->total);
        if (strlen($currency) !== 3 || $amount <= 0) {
            return ['status' => 'failed', 'message' => __('Invalid currency or zero amount for Razorpay checkout.', 'razorpay-for-fluentcart')];
        }

        $result = $this->api()->createOrder([
            'amount'          => $amount,
            'currency'        => $currency,
            'receipt'         => 'FC-' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$transaction->uuid), 0, 36),
            'payment_capture' => 1,
            'notes'           => [
                'Method'                  => 'FluentCart',
                'fluent_transaction_uuid' => (string)$transaction->uuid,
                'fluent_order_id'         => (string)$order->id,
                'description'             => 'Order #' . $order->id,
            ],
        ]);
        if (is_wp_error($result)) return ['status' => 'failed', 'message' => $result->get_error_message()];

        $orderId                  = (string)($result['id'] ?? '');
        $meta                     = (array)$transaction->meta;
        $meta['razorpay_order_id'] = $orderId;
        $meta['razorpay_type']    = 'one_time';
        $transaction->payment_mode = $this->settings->mode();
        $transaction->meta        = $meta;
        $transaction->save();

        if ($orderId) set_transient('rpfc_tx_order_' . md5($orderId), (string)$transaction->uuid, 14 * DAY_IN_SECONDS);

        return $this->checkoutResponse($transaction, ['razorpay_order_id' => $orderId, 'amount' => $amount, 'currency' => $currency], $order);
    }

    private function mapPeriod(string $interval): ?array {
        $map = [
            'weekly'      => ['period' => 'weekly', 'interval' => 1],
            'monthly'     => ['period' => 'monthly', 'interval' => 1],
            'quarterly'   => ['period' => 'monthly', 'interval' => 3],
            'half_yearly' => ['period' => 'monthly', 'interval' => 6],
            'yearly'      => ['period' => 'yearly', 'interval' => 1],
        ];
        return $map[strtolower(trim($interval))] ?? null;
    }

    private function getOrCreatePlan(array $period, int $amount, string $currency, $order, $subscription) {
        $key = 'rpfc_plan_' . $this->settings->mode() . '_' . md5(wp_json_encode([(string)($subscription->product_id ?? ''), $period['period'], $period['interval'], $amount, $currency]));
        if (isset(self::$runtimePlanCache[$key])) return ['id' => self::$runtimePlanCache[$key]];
        if ($cached = get_option($key, '')) return ['id' => (self::$runtimePlanCache[$key] = (string)$cached)];

        $res = $this->api()->createPlan([
            'period'   => $period['period'],
            'interval' => $period['interval'],
            'item'     => ['name' => 'Order #' . ($order->id ?? ''), 'amount' => $amount, 'currency' => $currency, 'description' => 'Order #' . ($order->id ?? '')],
            'notes'    => ['Method' => 'FluentCart', 'fluent_subscription_id' => (string)$subscription->id],
        ]);
        if (is_wp_error($res)) return $res;
        $planId = (string)($res['id'] ?? '');
        if (!$planId) return new \WP_Error('rpfc_plan', __('Razorpay did not return a valid plan ID.', 'razorpay-for-fluentcart'));

        update_option($key, $planId, false);
        self::$runtimePlanCache[$key] = $planId;
        return $res;
    }

    private function createSubscriptionCheckout(PaymentInstance $paymentInstance) {
        $order        = $paymentInstance->order;
        $transaction  = $paymentInstance->transaction;
        $subscription = $paymentInstance->subscription;
        if (!$order || !$transaction || !$subscription) return ['status' => 'failed', 'message' => __('Unable to initialize subscription payment.', 'razorpay-for-fluentcart')];

        $currency        = strtoupper(trim((string)$transaction->currency));
        $recurringAmount = (int)round($subscription->recurring_total);
        $billingInterval = strtolower(trim((string)$subscription->billing_interval));
        if ($billingInterval === 'daily') {
            return ['status' => 'failed', 'message' => __('Razorpay does not support daily subscriptions (minimum billing interval is 7 days). Please use weekly, monthly, quarterly, or yearly.', 'razorpay-for-fluentcart')];
        }
        $period = $this->mapPeriod($billingInterval);
        if (strlen($currency) !== 3 || $recurringAmount <= 0 || !$period) {
            return ['status' => 'failed', 'message' => __('Invalid Razorpay subscription configuration.', 'razorpay-for-fluentcart')];
        }

        $plan = $this->getOrCreatePlan($period, $recurringAmount, $currency, $order, $subscription);
        if (is_wp_error($plan)) return ['status' => 'failed', 'message' => $plan->get_error_message()];

        $totalCount = max(1, (int)($subscription->bill_times ?: ($period['period'] === 'yearly' ? 10 : 120)));
        $subData    = [
            'plan_id'         => (string)$plan['id'],
            'total_count'     => $totalCount,
            'quantity'        => 1,
            'customer_notify' => false,
            'notes'           => [
                'Method'                  => 'FluentCart',
                'fluent_transaction_uuid' => (string)$transaction->uuid,
                'fluent_subscription_id'  => (string)$subscription->id,
                'fluent_order_id'         => (string)$order->id,
            ],
        ];

        $initialAmount = (int)round($transaction->total);
        if ($initialAmount > $recurringAmount) {
            $subData['addons'] = [['item' => ['name' => 'Setup Fee', 'amount' => $initialAmount - $recurringAmount, 'currency' => $currency]]];
        }

        $remoteSub = $this->api()->createSubscription($subData);
        if (is_wp_error($remoteSub)) return ['status' => 'failed', 'message' => $remoteSub->get_error_message()];

        $subId = (string)($remoteSub['id'] ?? '');
        if (!$subId) return ['status' => 'failed', 'message' => __('Razorpay did not return a subscription ID.', 'razorpay-for-fluentcart')];

        $subscription->vendor_subscription_id = $subId;
        $subscription->collection_method      = 'system';
        $subscription->current_payment_method = 'razorpay';
        $subscription->save();

        $meta                             = (array)$transaction->meta;
        $meta['razorpay_subscription_id'] = $subId;
        $meta['razorpay_plan_id']         = (string)$plan['id'];
        $meta['razorpay_type']            = 'subscription';
        $transaction->payment_mode        = $this->settings->mode();
        $transaction->meta                = $meta;
        $transaction->save();

        set_transient('rpfc_tx_sub_' . md5($subId), (string)$transaction->uuid, 14 * DAY_IN_SECONDS);

        return $this->checkoutResponse($transaction, ['razorpay_subscription_id' => $subId], $order);
    }

    private function checkoutResponse($transaction, array $extra, $order): array {
        $order->loadMissing('customer', 'billing_address');
        $cust = $order->customer;
        $bill = $order->billing_address;
        $name = trim((string)($bill->name ?? (($cust->first_name ?? '') . ' ' . ($cust->last_name ?? ''))));

        return [
            'status'     => 'success',
            'nextAction' => 'razorpay',
            'actionName' => 'custom',
            'message'    => __('Opening Razorpay…', 'razorpay-for-fluentcart'),
            'response'   => array_merge([
                'api_key'          => $this->settings->apiKey(),
                'description'      => 'Order #' . ($order->id ?? ''),
                'transaction_uuid' => (string)$transaction->uuid,
                'ajax_url'         => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce('rpfc_confirm'),
                'prefill'          => [
                    'name'    => $name,
                    'email'   => (string)($cust->email ?? ($order->email ?? '')),
                    'contact' => preg_replace('/[^0-9+]/', '', (string)($bill->phone ?? ($cust->phone ?? ''))),
                ],
            ], $extra),
        ];
    }

    private function markSucceeded($transaction, string $paymentId, array $paymentData = []): void {
        if (!$transaction || $transaction->status === 'succeeded') return;

        $method = strtolower(trim((string)($paymentData['method'] ?? '')));
        $map    = ['card' => 'card', 'upi' => 'upi', 'netbanking' => 'net_banking', 'wallet' => 'wallet', 'emi' => 'emi', 'paylater' => 'paylater'];
        $pmType = $map[$method] ?? ($method !== '' ? $method : 'razorpay');

        $transaction->status              = 'succeeded';
        $transaction->payment_method      = 'razorpay';
        $transaction->payment_method_type = $pmType;
        if ($paymentId !== '') $transaction->vendor_charge_id = $paymentId;

        if (!empty($paymentData['card']) && is_array($paymentData['card'])) {
            if (!empty($paymentData['card']['network'])) $transaction->card_brand = (string)$paymentData['card']['network'];
            if (!empty($paymentData['card']['last4'])) $transaction->card_last_4 = (string)$paymentData['card']['last4'];
        }

        $meta                        = (array)$transaction->meta;
        if ($paymentId !== '') $meta['razorpay_payment_id'] = $paymentId;
        $meta['razorpay_method']     = $pmType;
        $transaction->meta           = $meta;
        $transaction->save();

        if ($order = Order::find($transaction->order_id)) {
            try { (new StatusHelper($order))->syncOrderStatuses($transaction); } catch (\Throwable $e) {}
        }
    }

    public function confirmPayment() {
        if (!check_ajax_referer('rpfc_confirm', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed.', 'razorpay-for-fluentcart')], 403);
        }

        $txid = '';
        if (isset($_POST['transaction_id'])) {
            $txid = sanitize_text_field(wp_unslash($_POST['transaction_id']));
        } elseif (isset($_POST['transaction_hash'])) {
            $txid = sanitize_text_field(wp_unslash($_POST['transaction_hash']));
        }
        $pid = isset($_POST['razorpay_payment_id']) ? sanitize_text_field(wp_unslash($_POST['razorpay_payment_id'])) : '';
        $sig = isset($_POST['razorpay_signature']) ? sanitize_text_field(wp_unslash($_POST['razorpay_signature'])) : '';

        $tx = $txid ? OrderTransaction::query()->where('uuid', $txid)->first() : null;
        if (!$tx) wp_send_json_error(['message' => __('Transaction not found.', 'razorpay-for-fluentcart')], 404);
        if ($tx->status === 'succeeded') wp_send_json_success(['redirect_url' => $tx->getReceiptPageUrl()]);
        if (!$pid || !$sig) wp_send_json_error(['message' => __('Incomplete Razorpay payment response.', 'razorpay-for-fluentcart')], 400);

        // Zero-trust: verify HMAC signature against stored order/subscription ID (not untrusted POST)
        $meta      = (array)$tx->meta;
        $storedOrd = (string)($meta['razorpay_order_id'] ?? '');
        $storedSub = (string)($meta['razorpay_subscription_id'] ?? '');
        $secret    = $this->settings->apiSecret();

        $sigPayload = $storedSub ? ($pid . '|' . $storedSub) : ($storedOrd . '|' . $pid);
        if (!$secret || !hash_equals(hash_hmac('sha256', $sigPayload, $secret), $sig)) {
            wp_send_json_error(['message' => __('Invalid Razorpay payment signature.', 'razorpay-for-fluentcart')], 403);
        }

        // Server-side verification via Razorpay API
        $payment = $this->api()->getPayment($pid);
        if (is_wp_error($payment)) wp_send_json_error(['message' => $payment->get_error_message()], 400);

        $status = strtolower((string)($payment['status'] ?? ''));
        if ($status === 'authorized') {
            $cap = $this->api()->capturePayment($pid, (int)round($tx->total), (string)$tx->currency);
            if (!is_wp_error($cap)) {
                $payment = $cap;
                $status  = strtolower((string)($payment['status'] ?? 'captured'));
            }
        }
        if ($status !== 'captured') {
            wp_send_json_error(['message' => __('Payment has not been captured yet.', 'razorpay-for-fluentcart')], 400);
        }

        if ($storedOrd && !empty($payment['order_id']) && $payment['order_id'] !== $storedOrd) {
            wp_send_json_error(['message' => __('Razorpay order ID mismatch.', 'razorpay-for-fluentcart')], 400);
        }
        if ((int)($payment['amount'] ?? 0) !== (int)round($tx->total)) {
            wp_send_json_error(['message' => __('Razorpay payment amount mismatch.', 'razorpay-for-fluentcart')], 400);
        }
        if (!empty($payment['currency']) && strtoupper((string)$payment['currency']) !== strtoupper((string)$tx->currency)) {
            wp_send_json_error(['message' => __('Razorpay payment currency mismatch.', 'razorpay-for-fluentcart')], 400);
        }

        $this->markSucceeded($tx, $pid, $payment);
        wp_send_json_success(['redirect_url' => $tx->getReceiptPageUrl()]);
    }

    public function paymentStatus() {
        if (!check_ajax_referer('rpfc_confirm', '_ajax_nonce', false)) {
            wp_send_json_error(['message' => __('Security verification failed.', 'razorpay-for-fluentcart')], 403);
        }

        $txid = isset($_POST['transaction_id']) ? sanitize_text_field(wp_unslash($_POST['transaction_id'])) : '';
        $tx   = $txid ? OrderTransaction::query()->where('uuid', $txid)->first() : null;
        if (!$tx) wp_send_json_error(['message' => __('Transaction not found.', 'razorpay-for-fluentcart')], 404);
        if ($tx->status === 'succeeded') wp_send_json_success(['transaction_status' => 'succeeded', 'redirect_url' => $tx->getReceiptPageUrl()]);

        $orderId = (string)($tx->meta['razorpay_order_id'] ?? '');
        if ($orderId) {
            $payments = $this->api()->getOrderPayments($orderId);
            if (!is_wp_error($payments) && !empty($payments['items']) && is_array($payments['items'])) {
                foreach ($payments['items'] as $p) {
                    if (strtolower((string)($p['status'] ?? '')) === 'captured' && (int)($p['amount'] ?? 0) === (int)round($tx->total)) {
                        $this->markSucceeded($tx, (string)$p['id'], $p);
                        wp_send_json_success(['transaction_status' => 'succeeded', 'redirect_url' => $tx->getReceiptPageUrl()]);
                    }
                }
            }
        }

        wp_send_json_success(['transaction_status' => (string)$tx->status]);
    }

    public function processRefund($transaction, $amount, $args = []) {
        if (!$transaction) return new \WP_Error('rpfc_refund', __('FluentCart transaction was not found.', 'razorpay-for-fluentcart'));

        $meta      = (array)$transaction->meta;
        $paymentId = (string)($transaction->vendor_charge_id ?: ($meta['razorpay_payment_id'] ?? ''));
        if (!$paymentId) return new \WP_Error('rpfc_refund', __('Razorpay payment ID is missing on this transaction.', 'razorpay-for-fluentcart'));

        $requestedAmount = (int)round($amount);
        if ($requestedAmount <= 0) return new \WP_Error('rpfc_refund', __('Refund amount must be greater than zero.', 'razorpay-for-fluentcart'));

        $lockKey = 'rpfc_refund_lock_' . md5($paymentId . '|' . $requestedAmount);
        $hasLock = add_option($lockKey, time(), '', 'no');
        if (!$hasLock) {
            if ((time() - (int)get_option($lockKey, 0)) > 120) {
                delete_option($lockKey);
                $hasLock = add_option($lockKey, time(), '', 'no');
            } else {
                return new \WP_Error('rpfc_refund_busy', __('A refund for this payment is already in progress.', 'razorpay-for-fluentcart'));
            }
        }

        try {
            $payment = $this->api()->getPayment($paymentId);
            if (is_wp_error($payment)) return $payment;

            if (!in_array(strtolower((string)($payment['status'] ?? '')), ['captured', 'processed'], true)) {
                return new \WP_Error('rpfc_refund', __('This Razorpay payment is not in a refundable state.', 'razorpay-for-fluentcart'));
            }

            $remaining = max(0, (int)($payment['amount'] ?? 0) - (int)($payment['amount_refunded'] ?? 0));
            if ($requestedAmount > $remaining) {
                return new \WP_Error('rpfc_refund', __('Requested refund exceeds remaining refundable amount.', 'razorpay-for-fluentcart'));
            }

            $receipt     = 'FC-' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$transaction->uuid), 0, 36);
            $notes       = ['Method' => 'FluentCart', 'fluent_transaction_uuid' => (string)$transaction->uuid];
            $idempotency = 'fc_' . substr(hash('sha256', $transaction->uuid . '|' . $paymentId . '|' . $requestedAmount), 0, 40);

            $result = $this->api()->createRefund($paymentId, $requestedAmount, $receipt, $notes, $idempotency);
            if (is_wp_error($result)) return $result;

            $refundId = (string)($result['id'] ?? '');
            if (!$refundId) return new \WP_Error('rpfc_refund', __('Razorpay did not return a refund ID.', 'razorpay-for-fluentcart'));

            $pmType = (string)($transaction->payment_method_type ?: 'razorpay');
            if (!empty($args['refund_transaction_id']) && ($refundTx = OrderTransaction::find($args['refund_transaction_id']))) {
                $refundTx->status              = 'refunded';
                $refundTx->vendor_charge_id    = $refundId;
                $refundTx->payment_method      = 'razorpay';
                $refundTx->payment_method_type = $pmType;
                $refundTx->save();
            }

            return $refundId;
        } finally {
            if ($hasLock) delete_option($lockKey);
        }
    }

    public function cancelVendorSubscription($data): void {
        $sub = is_object($data) ? $data : (is_array($data) ? ($data['subscription'] ?? null) : null);
        if (!$sub || ($sub->current_payment_method ?? '') !== 'razorpay' || empty($sub->vendor_subscription_id)) return;
        $this->api()->cancelSubscription((string)$sub->vendor_subscription_id, false);
    }

    private function ackWebhook(string $eventKey = '', int $status = 200, string $msg = 'Webhook processed.'): void {
        if ($eventKey !== '') set_transient($eventKey, 1, 7 * DAY_IN_SECONDS);
        if (function_exists('nocache_headers')) nocache_headers();
        wp_send_json(['status' => ($status >= 200 && $status < 300) ? 'ok' : 'error', 'message' => $msg], $status);
    }

    public function handleIPN() {
        $raw = (string)file_get_contents('php://input');
        $sig = isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])))
            : '';
        if (!$sig && function_exists('getallheaders')) {
            $sig = trim(sanitize_text_field((string)(array_change_key_case((array)getallheaders(), CASE_LOWER)['x-razorpay-signature'] ?? '')));
        }

        // Zero-trust: verify strictly against active mode's webhook secret
        $secret = $this->settings->webhookSecret();
        if (!$raw || !$sig || !$secret || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
            $this->ackWebhook('', 401, 'Invalid Razorpay webhook signature.');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) $this->ackWebhook('', 400, 'Invalid JSON payload.');

        $eventId  = isset($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'])
            ? trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'])))
            : trim(sanitize_text_field((string)($data['event_id'] ?? '')));
        $eventKey = $eventId !== '' ? 'rpfc_evt_' . md5($this->settings->mode() . '|' . $eventId) : '';
        if ($eventKey && get_transient($eventKey)) $this->ackWebhook('', 200, 'Duplicate webhook event already processed.');

        $event   = strtolower(trim((string)($data['event'] ?? '')));
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        // Payment Webhooks
        if (strpos($event, 'payment.') === 0) {
            $payment = is_array($payload['payment']['entity'] ?? null) ? $payload['payment']['entity'] : [];
            $notes   = is_array($payment['notes'] ?? null) ? $payment['notes'] : [];
            if (isset($notes['Method']) && strtolower((string)$notes['Method']) !== 'fluentcart') {
                $this->ackWebhook($eventKey, 200, 'Ignored: event belongs to another method.');
            }

            $pid    = sanitize_text_field((string)($payment['id'] ?? ''));
            $ordId  = sanitize_text_field((string)($payment['order_id'] ?? ''));
            $txUuid = sanitize_text_field((string)($notes['fluent_transaction_uuid'] ?? ($ordId ? get_transient('rpfc_tx_order_' . md5($ordId)) : '')));
            $tx     = $txUuid ? OrderTransaction::query()->where('uuid', $txUuid)->first() : null;
            if (!$tx) $this->ackWebhook($eventKey, 200, 'Ignored: matching FluentCart transaction not found.');

            if ($event === 'payment.captured' || $event === 'payment.authorized') {
                if ($event === 'payment.authorized' && $pid) {
                    $cap = $this->api()->capturePayment($pid, (int)round($tx->total), (string)$tx->currency);
                    if (is_wp_error($cap)) {
                        $this->ackWebhook('', 500, 'Failed to capture authorized payment.');
                    }
                    $payment = $cap;
                }
                if (isset($payment['amount']) && (int)$payment['amount'] !== (int)round($tx->total)) {
                    $this->ackWebhook($eventKey, 200, 'Ignored: Razorpay payment amount mismatch.');
                }
                $this->markSucceeded($tx, $pid, $payment);
                $this->ackWebhook($eventKey, 200, 'Payment captured and FluentCart order updated.');
            }
            if ($event === 'payment.failed' && $tx->status !== 'succeeded') {
                $tx->status = 'failed';
                $tx->save();
                if ($order = Order::find($tx->order_id)) {
                    try { (new StatusHelper($order))->syncOrderStatuses($tx); } catch (\Throwable $e) {}
                }
                $this->ackWebhook($eventKey, 200, 'Payment marked as failed.');
            }
            $this->ackWebhook($eventKey, 200, 'Payment event acknowledged.');
        }

        // Refund Webhooks
        if ($event === 'refund.processed' || $event === 'refund.failed') {
            $refund    = is_array($payload['refund']['entity'] ?? null) ? $payload['refund']['entity'] : [];
            $notes     = is_array($refund['notes'] ?? null) ? $refund['notes'] : [];
            if (isset($notes['Method']) && strtolower((string)$notes['Method']) !== 'fluentcart') {
                $this->ackWebhook($eventKey, 200, 'Ignored: event belongs to another method.');
            }

            $refundId    = sanitize_text_field((string)($refund['id'] ?? ''));
            $paymentId   = sanitize_text_field((string)($refund['payment_id'] ?? ''));
            $amountMinor = (int)($refund['amount'] ?? 0);
            $status      = $event === 'refund.failed' ? 'failed' : 'refunded';

            $refundTx = $refundId ? OrderTransaction::query()->where('vendor_charge_id', $refundId)->first() : null;
            if ($refundTx) {
                if ($refundTx->status !== 'refunded') $refundTx->status = $status;
                if ($amountMinor > 0 && empty($refundTx->total)) $refundTx->total = $amountMinor;
                $refundTx->save();
                $this->ackWebhook($eventKey, 200, 'Refund updated to ' . $status . '.');
            }

            $parent = !empty($notes['fluent_transaction_uuid'])
                ? OrderTransaction::query()->where('uuid', sanitize_text_field($notes['fluent_transaction_uuid']))->first()
                : ($paymentId ? OrderTransaction::query()->where('vendor_charge_id', $paymentId)->first() : null);

            if ($parent && $refundId) {
                Refund::createOrRecordRefund([
                    'vendor_charge_id'    => $refundId,
                    'payment_method'      => 'razorpay',
                    'payment_method_type' => (string)($parent->payment_method_type ?: 'razorpay'),
                    'payment_mode'        => $parent->payment_mode ?? $this->settings->mode(),
                    'status'              => $status,
                    'total'               => $amountMinor,
                ], $parent);
                $this->ackWebhook($eventKey, 200, 'Refund recorded as ' . $status . '.');
            }
            $this->ackWebhook($eventKey, 200, 'Ignored: matching FluentCart refund transaction not found.');
        }

        // Subscription Webhooks
        if (strpos($event, 'subscription.') === 0) {
            $subEnt = is_array($payload['subscription']['entity'] ?? null) ? $payload['subscription']['entity'] : [];
            $subId  = sanitize_text_field((string)($subEnt['id'] ?? ''));
            if ($subId && class_exists(Subscription::class) && ($sub = Subscription::query()->where('vendor_subscription_id', $subId)->first())) {
                $statusMap = [
                    'subscription.authenticated' => 'active',
                    'subscription.activated'     => 'active',
                    'subscription.charged'       => 'active',
                    'subscription.cancelled'     => 'canceled',
                    'subscription.halted'        => 'failing',
                    'subscription.completed'     => 'completed',
                ];
                if (isset($statusMap[$event])) {
                    $sub->status = $statusMap[$event];
                    $sub->save();
                }
                if ($event === 'subscription.charged' && !empty($payload['payment']['entity']['id'])) {
                    $pEnt = $payload['payment']['entity'];
                    if ($txUuid = (string)get_transient('rpfc_tx_sub_' . md5($subId))) {
                        $tx = OrderTransaction::query()->where('uuid', $txUuid)->first();
                        if ($tx && $tx->status !== 'succeeded') $this->markSucceeded($tx, (string)$pEnt['id'], $pEnt);
                    }
                }
                $this->ackWebhook($eventKey, 200, 'Subscription event synced.');
            }
            $this->ackWebhook($eventKey, 200, 'Ignored: matching FluentCart subscription not found.');
        }

        $this->ackWebhook($eventKey, 200, 'Ignored: unhandled Razorpay event (' . $event . ').');
    }
}
