<?php
namespace RazorpayFluentCart;

if (!defined('ABSPATH')) exit;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class Settings extends BaseGatewaySettings {
    public $methodHandler = 'fluent_cart_payment_settings_razorpay';

    private array $cachedSecrets = [];
    private ?string $cachedMode = null;

    public static function getDefaults(): array {
        return [
            'is_active'           => 'no',
            'payment_mode'        => 'live',
            'live_api_key'        => '',
            'live_key_secret'     => '',
            'live_webhook_secret' => '',
            'test_api_key'        => '',
            'test_key_secret'     => '',
            'test_webhook_secret' => '',
        ];
    }

    public static function beforeSettingsUpdate($data, $oldSettings = []): array {
        if (!is_array($data)) return [];
        if (!is_array($oldSettings)) $oldSettings = [];

        foreach (['live_key_secret', 'test_key_secret', 'live_webhook_secret', 'test_webhook_secret'] as $key) {
            $newVal = isset($data[$key]) ? trim((string)$data[$key]) : '';
            $oldVal = isset($oldSettings[$key]) ? (string)$oldSettings[$key] : '';
            if ($newVal === '' || preg_match('/^\*+$/', $newVal)) {
                $data[$key] = $oldVal;
            } elseif (class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && method_exists(Helper::class, 'encryptKey')) {
                if (!Helper::isValueEncrypted($newVal)) {
                    $data[$key] = Helper::encryptKey($newVal);
                }
            }
        }

        foreach (['live_api_key', 'test_api_key'] as $k) {
            if (isset($data[$k])) $data[$k] = sanitize_text_field(trim((string)$data[$k]));
        }
        if (isset($data['payment_mode'])) $data['payment_mode'] = ($data['payment_mode'] === 'test') ? 'test' : 'live';
        if (isset($data['is_active'])) $data['is_active'] = ($data['is_active'] === 'yes') ? 'yes' : 'no';

        return $data;
    }

    public function get($key = '', $default = null) {
        if ($key === '' || $key === null) return $this->settings;
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function mode(): string {
        if ($this->cachedMode !== null) return $this->cachedMode;
        if (class_exists('\FluentCart\Api\StoreSettings')) {
            try {
                $orderMode = (new \FluentCart\Api\StoreSettings())->get('order_mode');
                if ($orderMode === 'test' || $orderMode === 'live') return $this->cachedMode = $orderMode;
            } catch (\Throwable $e) {}
        }
        $pm = (string)$this->get('payment_mode', '');
        if ($pm === 'test' || $pm === 'live') return $this->cachedMode = $pm;
        $key = (string)($this->get('live_api_key', '') ?: $this->get('test_api_key', '') ?: $this->get('api_key', ''));
        return $this->cachedMode = (strpos($key, 'rzp_test_') === 0 ? 'test' : 'live');
    }

    public function getMode(): string { return $this->mode(); }
    public function isTest(): bool { return $this->mode() === 'test'; }
    public function isActive(): bool { return $this->get('is_active', 'no') === 'yes'; }
    public function isConfigured(): bool { return $this->apiKey() !== '' && $this->apiSecret() !== ''; }

    public function apiKey(?string $mode = null): string {
        $m   = $mode ?: $this->mode();
        $key = (string)$this->get($m === 'test' ? 'test_api_key' : 'live_api_key', '');
        return trim($key !== '' ? $key : (string)$this->get('api_key', ''));
    }

    public function secret(string $key): string {
        if (isset($this->cachedSecrets[$key])) return $this->cachedSecrets[$key];
        $val = (string)$this->get($key, '');
        if ($val === '') return $this->cachedSecrets[$key] = '';
        if (class_exists(Helper::class) && method_exists(Helper::class, 'isValueEncrypted') && Helper::isValueEncrypted($val)) {
            $dec = Helper::decryptKey($val);
            return $this->cachedSecrets[$key] = ($dec === false ? '' : trim((string)$dec));
        }
        return $this->cachedSecrets[$key] = trim($val);
    }

    public function apiSecret(?string $mode = null): string {
        $m   = $mode ?: $this->mode();
        $sec = $this->secret($m === 'test' ? 'test_key_secret' : 'live_key_secret');
        return $sec !== '' ? $sec : $this->secret('api_secret');
    }

    public function webhookSecret(?string $mode = null): string {
        $m   = $mode ?: $this->mode();
        $sec = $this->secret($m === 'test' ? 'test_webhook_secret' : 'live_webhook_secret');
        return $sec !== '' ? $sec : $this->secret('webhook_secret');
    }

    public function getWebhookUrl(): string {
        return add_query_arg(['fluent-cart' => 'fct_payment_listener_ipn', 'method' => 'razorpay'], home_url('/'));
    }

    public function getWebhookInstructions(): array {
        return [
            'title'       => __('Webhook URL', 'razorpay-for-fluentcart'),
            'webhook_url' => esc_url_raw($this->getWebhookUrl()),
            'description' => __('Configure this webhook URL and secret in your Razorpay Dashboard for real-time payment and subscription status synchronization.', 'razorpay-for-fluentcart'),
            'steps'       => [
                'title' => __('How to configure?', 'razorpay-for-fluentcart'),
                'list'  => [
                    __('Log in to your Razorpay Dashboard and navigate to Settings &rarr; Webhooks', 'razorpay-for-fluentcart'),
                    __('Click "Add New Webhook" and paste the Webhook URL above', 'razorpay-for-fluentcart'),
                    __('Enter your Webhook Secret (matching the secret saved in credentials above)', 'razorpay-for-fluentcart'),
                    __('Select the required webhook events listed below and save', 'razorpay-for-fluentcart'),
                ],
            ],
            'events'      => [
                'title' => __('Required Webhook Events', 'razorpay-for-fluentcart'),
                'list'  => [
                    'payment.authorized',
                    'payment.captured',
                    'payment.failed',
                    'refund.processed',
                    'refund.failed',
                ],
            ],
        ];
    }

    public function getSubscriptionWebhookInstructions(): array {
        return [
            'events' => [
                'title' => __('Subscription Webhook Events', 'razorpay-for-fluentcart'),
                'list'  => [
                    'subscription.authenticated',
                    'subscription.activated',
                    'subscription.charged',
                    'subscription.cancelled',
                    'subscription.halted',
                    'subscription.completed',
                ],
            ],
        ];
    }

    public function getFields($gateway = null): array {
        $fields = [];
        if ($gateway && method_exists($gateway, 'renderStoreModeNotice') && ($notice = $gateway->renderStoreModeNotice())) {
            $fields['notice'] = ['value' => $notice, 'label' => __('Store Mode notice', 'razorpay-for-fluentcart'), 'type' => 'notice'];
        }

        $fields['payment_mode'] = [
            'type'   => 'tabs',
            'schema' => [
                [
                    'type'   => 'tab',
                    'label'  => __('Live credentials', 'razorpay-for-fluentcart'),
                    'value'  => 'live',
                    'schema' => [
                        'live_api_key'        => ['type' => 'text', 'label' => __('Live API Key', 'razorpay-for-fluentcart'), 'placeholder' => 'rzp_live_xxxxxxxxxxxxx', 'value' => $this->get('live_api_key', '')],
                        'live_key_secret'     => ['type' => 'password', 'label' => __('Live Key Secret', 'razorpay-for-fluentcart'), 'placeholder' => __('Your live key secret', 'razorpay-for-fluentcart'), 'value' => $this->get('live_key_secret', '')],
                        'live_webhook_secret' => ['type' => 'password', 'label' => __('Live Webhook Secret', 'razorpay-for-fluentcart'), 'placeholder' => __('Your live webhook secret', 'razorpay-for-fluentcart'), 'value' => $this->get('live_webhook_secret', '')],
                    ],
                ],
                [
                    'type'   => 'tab',
                    'label'  => __('Test credentials', 'razorpay-for-fluentcart'),
                    'value'  => 'test',
                    'schema' => [
                        'test_api_key'        => ['type' => 'text', 'label' => __('Test API Key', 'razorpay-for-fluentcart'), 'placeholder' => 'rzp_test_xxxxxxxxxxxxx', 'value' => $this->get('test_api_key', '')],
                        'test_key_secret'     => ['type' => 'password', 'label' => __('Test Key Secret', 'razorpay-for-fluentcart'), 'placeholder' => __('Your test key secret', 'razorpay-for-fluentcart'), 'value' => $this->get('test_key_secret', '')],
                        'test_webhook_secret' => ['type' => 'password', 'label' => __('Test Webhook Secret', 'razorpay-for-fluentcart'), 'placeholder' => __('Your test webhook secret', 'razorpay-for-fluentcart'), 'value' => $this->get('test_webhook_secret', '')],
                    ],
                ],
            ],
        ];

        $fields['webhook_info'] = [
            'value' => $this->getWebhookInstructions(),
            'label' => __('Webhook Configuration', 'razorpay-for-fluentcart'),
            'type'  => 'html_attr',
        ];

        $fields['subscription_webhook_info'] = [
            'value' => $this->getSubscriptionWebhookInstructions(),
            'label' => __('Subscription Webhook Events', 'razorpay-for-fluentcart'),
            'type'  => 'html_attr',
        ];

        $fields['subscription_webhook_note'] = [
            'value' => '<p style="margin:0;">' . esc_html__('If you are selling subscriptions, enable these events too.', 'razorpay-for-fluentcart') . '</p>',
            'label' => __('Subscription Note', 'razorpay-for-fluentcart'),
            'type'  => 'notice',
        ];

        return $fields;
    }
}
