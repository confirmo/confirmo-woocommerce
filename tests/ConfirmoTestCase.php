<?php

use PHPUnit\Framework\TestCase;

/** Thrown in place of `wp_die()`, so a rejected callback can be asserted on. */
class ConfirmoWpDie extends RuntimeException
{
    /** @var int */
    public $status;

    public function __construct(string $message, int $status)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

/**
 * Shared fixtures: a transaction per test, stubbed Confirmo API responses, and
 * builders for the objects the plugin works on.
 */
abstract class ConfirmoTestCase extends TestCase
{
    /** @var array<string, array{status: int, body: mixed}> URL fragment => response */
    private $httpStubs = [];

    /** @var array<int, array{url: string, body: array}> every stubbed request, in order */
    protected $httpCalls = [];

    /** @var string|null */
    private $signingKey;

    /** @var array<string, mixed> the store's own settings, put back in tearDown */
    private $savedOptions = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $wpdb->query('SET autocommit = 0');
        $wpdb->query('START TRANSACTION');

        $this->httpStubs = [];
        $this->httpCalls = [];
        $this->signingKey = null;

        add_filter('pre_http_request', [$this, 'answerHttp'], 10, 3);
        add_filter('wp_die_handler', [$this, 'dieHandler'], 10, 1);
        // Order and subscription emails are a side effect, not the subject.
        add_filter('pre_wp_mail', [$this, 'swallowMail'], 10, 1);

        // Saved and restored explicitly, not left to the transaction: WooCommerce
        // and WCS issue statements that commit it implicitly, which is how a test
        // run once replaced a developer's real API key with the fixture below.
        foreach ([
            'confirmo_gate_config_options',
            'confirmo_subscribe_config_options',
            'woocommerce_confirmo_subscribe_settings',
        ] as $option) {
            $this->savedOptions[$option] = get_option($option);
        }

        // Real settings, so a test never depends on whatever the store happens to
        // have configured.
        update_option('confirmo_gate_config_options', [
            'api_key' => 'test-api-key',
            'callback_password' => 'abcdefghij123456',
            'settlement_currency' => 'USDT',
            'description' => 'Pay with crypto',
            // The mapping WC_Confirmo_Activator installs. updateOrderStatus reads
            // these keys directly, so a test with an empty mapping would only ever
            // exercise a broken store.
            'custom_states' => [
                'prepared' => 'on-hold',
                'active' => 'on-hold',
                'pending_verification' => 'on-hold',
                'confirming' => 'on-hold',
                'paid' => 'completed',
                'expired' => 'failed',
                'error' => 'failed',
            ],
        ]);
        update_option('confirmo_subscribe_config_options', ['enabled' => 'yes']);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        $wpdb->query('SET autocommit = 1');

        foreach ($this->savedOptions as $option => $value) {
            if ($value === false) {
                delete_option($option);
            } else {
                update_option($option, $value);
            }
        }
        $this->savedOptions = [];

        remove_filter('pre_http_request', [$this, 'answerHttp'], 10);
        remove_filter('wp_die_handler', [$this, 'dieHandler'], 10);
        remove_filter('pre_wp_mail', [$this, 'swallowMail'], 10);

        wp_cache_flush();

        // The Subscribe module does not load without WooCommerce Subscriptions,
        // so its classes are absent on a store that only runs the Checkout tests.
        if (class_exists('WC_Confirmo_Subscribe_Signature')) {
            delete_transient(WC_Confirmo_Subscribe_Signature::JWKS_TRANSIENT);
        }

        parent::tearDown();
    }

    // ── Confirmo API ────────────────────────────────────────────────────────

    /**
     * No test may reach the real Confirmo API: any request whose URL matches no
     * stub fails the test rather than travelling.
     *
     * @param mixed $body decoded response body
     */
    protected function stubApi(string $urlFragment, $body, int $status = 200): void
    {
        $this->httpStubs[$urlFragment] = ['status' => $status, 'body' => $body];
    }

    /** @return array|null the decoded request body sent to the first matching URL */
    protected function requestTo(string $urlFragment): ?array
    {
        foreach ($this->httpCalls as $call) {
            if (strpos($call['url'], $urlFragment) !== false) {
                return $call['body'];
            }
        }

        return null;
    }

    /**
     * @param false|array $preempt
     * @param array $args
     * @return array|WP_Error
     */
    public function answerHttp($preempt, $args, $url)
    {
        $body = [];
        if (isset($args['body']) && is_string($args['body'])) {
            $decoded = json_decode($args['body'], true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $this->httpCalls[] = ['url' => $url, 'body' => $body];

        foreach ($this->httpStubs as $fragment => $response) {
            if (strpos($url, $fragment) !== false) {
                return [
                    'headers' => [],
                    'body' => is_string($response['body']) ? $response['body'] : wp_json_encode($response['body']),
                    'response' => ['code' => $response['status'], 'message' => ''],
                    'cookies' => [],
                    'filename' => null,
                ];
            }
        }

        $this->fail('Unstubbed HTTP request to ' . $url);
    }

    // ── wp_die ─────────────────────────────────────────────────────────────

    /** @return true */
    public function swallowMail($preempt)
    {
        return true;
    }

    public function dieHandler(): callable
    {
        return static function ($message, $title = '', $args = []) {
            throw new ConfirmoWpDie(is_string($message) ? $message : '', (int) ($args['response'] ?? 0));
        };
    }

    // ── Webhook signing ────────────────────────────────────────────────────

    /**
     * Signs with a keypair generated for this test and published through a
     * stubbed JWKS, so verification is exercised for real without shipping a key.
     */
    protected function signWebhook(string $eventId, string $body, ?int $timestamp = null): array
    {
        if ($this->signingKey === null) {
            $pair = sodium_crypto_sign_keypair();
            $this->signingKey = sodium_crypto_sign_secretkey($pair);

            $public = sodium_crypto_sign_publickey($pair);
            $this->stubApi('/.well-known/jwks.json', [
                'keys' => [[
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => rtrim(strtr(base64_encode($public), '+/', '-_'), '='),
                ]],
            ]);
        }

        $timestamp = $timestamp ?? time();
        $signature = sodium_crypto_sign_detached($eventId . '.' . $timestamp . '.' . $body, $this->signingKey);

        return [
            'webhook-id' => $eventId,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => 'v1a,' . base64_encode($signature),
        ];
    }

    // ── The Checkout callback endpoint ─────────────────────────────────────

    /**
     * Drives the real `handleNotification()` the way a Confirmo callback does,
     * and returns the HTTP status it answered with.
     *
     * @param string|null $signature null signs the body correctly
     */
    protected function postCallback(string $body, ?string $signature = null): int
    {
        global $wp_query;

        if ($signature === null) {
            $password = (string) (get_option('confirmo_gate_config_options')['callback_password'] ?? '');
            $signature = hash('sha256', $body . $password);
        }

        $previousQuery = $wp_query;
        $wp_query = new WP_Query();
        $wp_query->query_vars['confirmo-notification'] = '1';
        $_SERVER['HTTP_BP_SIGNATURE'] = $signature;

        $gateway = new WC_Confirmo_Gateway();

        try {
            ConfirmoInputStream::with($body, static function () use ($gateway) {
                $gateway->handleNotification();
            });
        } catch (ConfirmoWpDie $e) {
            return $e->status;
        } finally {
            $wp_query = $previousQuery;
            unset($_SERVER['HTTP_BP_SIGNATURE']);
        }

        $this->fail('handleNotification() returned without answering the request');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    protected function makeSimpleProduct(string $price = '25.00'): WC_Product_Simple
    {
        $product = new WC_Product_Simple();
        $product->set_name('Test Widget');
        $product->set_regular_price($price);
        $product->save();

        return $product;
    }

    /** A subscription product mapped to a VARIABLE Confirmo plan, which is the only sellable shape. */
    protected function makeSubscriptionProduct(string $price = '29.00', string $planId = 'plan-uuid', string $period = 'month'): WC_Product
    {
        $product = new WC_Product_Subscription();
        $product->set_name('Test Membership');
        $product->set_regular_price($price);
        $product->save();

        $id = $product->get_id();
        update_post_meta($id, '_subscription_price', $price);
        update_post_meta($id, '_subscription_period', $period);
        update_post_meta($id, '_subscription_period_interval', '1');
        update_post_meta($id, '_subscription_length', '0');
        update_post_meta($id, '_subscription_trial_length', '0');
        update_post_meta($id, '_subscription_sign_up_fee', '0');

        WC_Confirmo_Subscribe_Plan::store($id, $planId, 'VARIABLE', strtoupper($period) === 'YEAR' ? 'ANNUAL' : 'MONTHLY');

        return wc_get_product($id);
    }

    /** WCS refuses to create a subscription for a guest, so every order gets a customer. */
    protected function makeCustomer(): int
    {
        $unique = wp_generate_password(8, false);
        $userId = wp_insert_user([
            'user_login' => 'buyer-' . $unique,
            'user_email' => 'buyer-' . $unique . '@example.com',
            'user_pass' => wp_generate_password(),
            'role' => 'customer',
        ]);

        if (is_wp_error($userId)) {
            $this->fail('could not create the customer: ' . $userId->get_error_message());
        }

        return (int) $userId;
    }

    protected function makeOrder(WC_Product $product, int $quantity = 1): WC_Order
    {
        $order = wc_create_order(['customer_id' => $this->makeCustomer()]);
        $order->add_product($product, $quantity);
        $order->set_billing_email('buyer@example.com');
        $order->set_billing_first_name('Test');
        $order->set_billing_last_name('Buyer');
        $order->set_billing_country('CZ');
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    /**
     * The order plus the WooCommerce subscription WCS would have created at
     * checkout, which is what the Subscribe gateway expects to find.
     *
     * @return array{0: WC_Order, 1: WC_Subscription}
     */
    protected function makeSubscriptionOrder(WC_Product $product, int $quantity = 1): array
    {
        $order = $this->makeOrder($product, $quantity);

        $subscription = wcs_create_subscription([
            'order_id' => $order->get_id(),
            'status' => 'pending',
            'billing_period' => get_post_meta($product->get_id(), '_subscription_period', true),
            'billing_interval' => 1,
            'customer_id' => $order->get_customer_id(),
        ]);

        if (is_wp_error($subscription)) {
            $this->fail('could not create the WooCommerce subscription: ' . $subscription->get_error_message());
        }

        $subscription->add_product($product, $quantity);
        $subscription->set_payment_method('confirmo_subscribe');
        $subscription->calculate_totals();
        $subscription->save();

        return [$order, $subscription];
    }
}
