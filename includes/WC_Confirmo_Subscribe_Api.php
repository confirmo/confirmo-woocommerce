<?php

/**
 * One Confirmo API key covers both Checkout and Subscribe, so the key is read
 * from the plugin's main settings rather than a Subscribe-specific one.
 */
class WC_Confirmo_Subscribe_Api
{
    const MAIN_OPTION = 'confirmo_gate_config_options';

    /** Short enough that a plan created in the Confirmo dashboard shows up promptly. */
    const PLAN_TRANSIENT_PREFIX = 'confirmo_subscribe_';
    const PLAN_TTL = 120;

    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = (string) ($apiKey ?? self::configuredApiKey());
    }

    public static function configuredApiKey(): string
    {
        $options = get_option(self::MAIN_OPTION, []);
        return is_array($options) && isset($options['api_key']) ? (string) $options['api_key'] : '';
    }

    private function baseUrl(): string
    {
        return defined('CONFIRMO_API_URL') ? CONFIRMO_API_URL : 'https://api.confirmo.com';
    }

    /**
     * @return array|WP_Error decoded response body, or WP_Error on failure
     */
    private function request(string $method, string $path, ?array $body = null)
    {
        $args = [
            'method' => $method,
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'X-Payment-Module' => 'WooCommerce',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->baseUrl() . $path, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'confirmo_subscribe_api',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __('Confirmo Subscribe API returned HTTP %d', 'confirmo-for-woocommerce'),
                    $code
                ),
                $data
            );
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Only VARIABLE plans are sellable here: the product owns the price. And only
     * ACTIVE ones — an archived plan keeps billing its existing subscribers but
     * cannot take new ones, so offering it would map a product nobody can buy.
     *
     * @return array|WP_Error
     */
    public function listVariablePlans()
    {
        return $this->cached('plans', function () {
            $data = $this->request('GET', '/api/v3/subscriptions/plans');
            if (is_wp_error($data)) {
                return $data;
            }
            $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];
            return array_values(array_filter($items, static function ($p) {
                return is_array($p)
                    && ($p['type'] ?? '') === 'VARIABLE'
                    && ($p['status'] ?? '') === 'ACTIVE';
            }));
        });
    }

    /**
     * @return array|WP_Error
     */
    public function getPlan(string $planId)
    {
        return $this->cached('plan_' . md5($planId), function () use ($planId) {
            return $this->request('GET', '/api/v3/subscriptions/plans/' . rawurlencode($planId));
        });
    }

    /**
     * Plans are read on every render of the product screen's general panel, at
     * the request timeout above and twice over when a mapped plan is missing from
     * the list. Uncached, an unreachable Confirmo made the WooCommerce product
     * editor hang for every product in the store, mapped or not.
     *
     * Failures are never cached: a merchant who has just fixed their API key
     * should not wait out a TTL to see it work.
     *
     * @return array|WP_Error
     */
    private function cached(string $key, callable $fetch)
    {
        $transient = self::PLAN_TRANSIENT_PREFIX . $key;

        $cached = get_transient($transient);
        if (is_array($cached)) {
            return $cached;
        }

        $fresh = $fetch();
        if (!is_wp_error($fresh)) {
            set_transient($transient, $fresh, self::PLAN_TTL);
        }

        return $fresh;
    }

    /**
     * @return array|WP_Error
     */
    public function createSubscription(array $body)
    {
        return $this->request('POST', '/api/v3/subscriptions', $body);
    }

    /**
     * @return array|WP_Error
     */
    public function getSubscription(string $subscriptionId)
    {
        return $this->request('GET', '/api/v3/subscriptions/' . rawurlencode($subscriptionId));
    }

    /**
     * @return array|WP_Error
     */
    public function cancelSubscription(string $subscriptionId)
    {
        return $this->request('POST', '/api/v3/subscriptions/' . rawurlencode($subscriptionId) . '/cancel');
    }

}
