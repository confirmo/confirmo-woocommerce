<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * WC Confirmo Gateway class - main plugin class that extends default WC Payment Gateway
 *
 * @property string $id
 * @property $method_title
 * @property string $method_description
 * @property $title
 * @property $description
 * @property string $enabled
 */
class WC_Confirmo_Gateway extends WC_Payment_Gateway
{
    protected string $apiKey;
    protected ?string $settlementCurrency;
    protected string $callbackPassword;
    protected WC_Confirmo_Loader $loader;
    protected $wpdb;
    public string $pluginName;
    public string $pluginBaseDir;
    private string $apiBaseUrl;
    public static array $allowedCurrencies = [
        'USDT' => 'USDT',
        'USDC' => 'USDC',
        'EUR' => 'EUR',
        'USD' => 'USD',
        'CZK' => 'CZK',
        null => 'Crypto Settlement (In Kind)'
    ];
    public static array $orderStatuses = [
        'pending',
        'on-hold',
        'processing',
        'completed',
        'failed'
    ];
    public static array $confirmoStatuses = [
        'prepared' => 'Prepared',
        'active' => 'Active',
        'confirming' => 'Confirming',
        'paid' => 'Paid',
        'expired' => 'Expired',
        'error' => 'Error'
    ];

    public function __construct()
    {
        $this->wpdb = $GLOBALS['wpdb'];

        $this->id = "confirmo";
        $this->method_title = __("Confirmo", 'confirmo-for-woocommerce');
        $this->method_description = __("Settings have been moved. Please configure the gateway ", 'confirmo-for-woocommerce') . "<a href='" . admin_url('admin.php?page=confirmo-payment') . "'>" . __("here", 'confirmo-for-woocommerce') . "</a>.";
        $this->title = __("Confirmo", 'confirmo-for-woocommerce');
        $this->description = get_option('confirmo_gate_config_options')['description'];
        $this->enabled = $this->get_option('enabled');
        $this->apiKey = get_option('confirmo_gate_config_options')['api_key'];
        $this->settlementCurrency = get_option('confirmo_gate_config_options')['settlement_currency'];
        $this->callbackPassword = get_option('confirmo_gate_config_options')['callback_password'];
        $this->apiBaseUrl = get_option('confirmo_base_url');
        // If needed, other initializations can be done here.
    }

    /**
     * Register all hooks
     *
     * @return void
     */
    public function run(): void
    {
        $this->defineHooks();

        $this->loader->run();
    }

    /**
     * Adds all necessary hooks to loader class to be registered to WP upon run() call
     *
     * @return void
     */
    private function defineHooks(): void
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/WC_Confirmo_Loader.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/WC_Confirmo_Settings.php';
        $this->loader = new WC_Confirmo_Loader();

        $this->loader->addAction('init', [$this, 'addEndpoints']);

        $this->loader->addAction('admin_init', [WC_Confirmo_Settings::class, 'register']);

        $this->loader->addAction('admin_menu', [$this, 'adminMenu']);
        $this->loader->addAction('admin_notices', function () {
            settings_errors('confirmo_gate_config_config');
        });

        $this->loader->addAction('template_redirect', [$this, 'handleNotification']);
        $this->loader->addAction('template_redirect', [$this, 'customPaymentTemplateRedirect']);

        $this->loader->addAction('init', [$this, 'loadTextDomain']);
        $this->loader->addAction('plugins_loaded', [$this, 'updateDbCheck']);

        $this->loader->addAction('before_woocommerce_init', [$this, 'declareCartCheckoutBlocksCompatibility']);
        $this->loader->addAction('woocommerce_blocks_loaded', [$this, 'registerBlockPaymentMethodType']);
        $this->loader->addAction('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        $this->loader->addAction('woocommerce_email_after_order_table', [$this, 'addUrlToEmails'], 10, 4);
        $this->loader->addAction('woocommerce_admin_order_data_after_billing_address', [$this, 'addUrlToEditOrder']);

        $this->loader->addAction('admin_init', [$this, 'downloadLogs']);
        $this->loader->addAction('admin_post_confirmo_delete_logs', [$this, 'deleteLogs']);
        $this->loader->addAction('confirmo_purge_old_logs_hook', [$this, 'purgeOldLogs']);

        $this->loader->addFilter('query_vars', [$this, 'addQueryVars']);

        $this->loader->addFilter('plugin_action_links_' . $this->pluginName, [$this, 'addSettingsLink']);
        $this->loader->addFilter('woocommerce_payment_gateways', [$this, 'addGatewayClass']);
        $this->loader->addFilter('woocommerce_thankyou_order_received_text', [$this, 'customOrderStatusThankyouText'], 10, 2);
    }

    /**
     * Check stored plugin version, compare DB table structure and update if needed
     *
     * @return void
     */
    public static function updateDbCheck(): void
    {
        global $confirmo_version;

        if (get_site_option('confirmo_version') != $confirmo_version) {
            WC_Confirmo_Activator::activate();
        }
    }

    /**
     * Display debug information page
     *
     * @return void
     */
    public function debugPageContent(): void
    {
        $wpdb = $this->wpdb;
        global $wp_filesystem;

        $table_name = $wpdb->prefix . "confirmo_logs";
        $threshold_date = gmdate('Y-m-d', strtotime("-1 day"));

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE time >= %s ORDER BY time ASC",
                $table_name,
                $threshold_date
            )
        );

        WP_Filesystem();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('Confirmo Debug Information', 'confirmo-for-woocommerce')) . '</h1>';
        echo '<p>' . esc_html(__('If you encounter any issues, please download these debug logs and send them to plugin support.', 'confirmo-for-woocommerce')) . '</p>';
        echo "<p>SHA-256 Hash: " . esc_html(hash('sha256', $wp_filesystem->get_contents(__FILE__))) . "</p>";

        if (!empty($logs)) {
            echo '<table class="widefat fixed" cellspacing="0">';
            echo '<thead><tr><th>' . esc_html(__('Time', 'confirmo-for-woocommerce')) . '</th><th>' . esc_html(__('Order ID', 'confirmo-for-woocommerce')) . '</th><th>' . esc_html(__('API Response', 'confirmo-for-woocommerce')) . '</th><th>' . esc_html(__('Redirect URL', 'confirmo-for-woocommerce')) . '</th><th>' . esc_html(__('Version', 'confirmo-for-woocommerce')) . '</th></thead>';
            echo '<tbody>';
            foreach ($logs as $log) {
                echo '<tr>';
                echo '<td>' . esc_html($log->time) . '</td>';
                echo '<td>' . (isset($log->order_id) ? esc_html($log->order_id) : 'N/A') . '</td>';
                echo '<td>' . (isset($log->api_response) ? esc_html($log->api_response) : 'N/A') . '</td>';
                echo '<td>' . (isset($log->hook) ? esc_html($log->hook) : "N/A") . '</td>';
                echo '<td>' . (isset($log->version) ? esc_html($log->version) : "N/A") . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="confirmo_download_logs" value="1">';
            echo '<p><button type="submit" class="button button-primary">' . esc_html(__('Download Debug Logs', 'confirmo-for-woocommerce')) . '</button></p>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="confirmo_delete_logs">';
            echo '<p><button type="submit" class="button button-secondary">' . esc_html(__('Delete all logs', 'confirmo-for-woocommerce')) . '</button></p>';
            echo '</form>';
        } else {
            echo '<p>' . esc_html(__('No debug logs available for the last day.', 'confirmo-for-woocommerce')) . '</p>';
        }
    }

    /**
     * Displays config page
     *
     * @return void
     */
    public function configPageContent(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        echo '<form method="post" action="options.php">';
        wp_nonce_field('confirmo-payment-gate-config', 'confirmo_config_nonce');
        settings_fields('confirmo-payment-gate-config');
        do_settings_sections('confirmo-payment-gate-config');
        submit_button(__('Save Settings', 'confirmo-for-woocommerce'));
        echo '</form>';
        echo '</div>';
    }

    /**
     * Creates redirect payment
     *
     * @return void
     */
    public function customPaymentTemplateRedirect(): void
    {
        global $wp_query;

        if (isset($wp_query->query_vars['confirmo-custom-payment'])) {
            if (!isset($_POST['currency'])) {
                wp_die(esc_html(__('Error: Missing currency.', 'confirmo-for-woocommerce')));
            }

            if (!isset($_POST['amount'])) {
                wp_die(esc_html(__('Error: Missing amount.', 'confirmo-for-woocommerce')));
            }

            $currency = sanitize_text_field(wp_unslash($_POST['currency']));
            $amount = sanitize_text_field(wp_unslash($_POST['amount']));
            $api_key = get_option('woocommerce_confirmo_api_key');

            if (empty($api_key)) {
                wp_die(esc_html(__('Error: API key is missing.', 'confirmo-for-woocommerce')));
            }

            $response = $this->createPayment($currency, $amount, $api_key);

            if (is_wp_error($response)) {
                wp_die(esc_html(__('Error:', 'confirmo-for-woocommerce') . ' ' . $response->get_error_message()));
                return;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if ($response_data) {
                $this->addDebugLog('custom-redirect-payment', wp_json_encode($response_data), 'create_payment');
            } else {
                $this->addDebugLog(null, "Missing order_id or response_data", 'create_payment');
            }

            if (isset($response_data['url'])) {
                wp_redirect($response_data['url']);
                exit;
            } else {
                wp_die(esc_html(__('Error: Payment URL not received.', 'confirmo-for-woocommerce')));
            }
        }
    }

    /**
     * Register custom endpoints
     *
     * @return void
     */
    public function addEndpoints(): void
    {
        add_rewrite_rule('^confirmo-notification/?', '?confirmo-notification=1', 'top');
        add_rewrite_rule('^confirmo-custom-payment/?', 'index.php?confirmo-custom-payment=1', 'top');
    }

    /**
     * Purges logs older than 30 days from DB, hooked to run periodically
     *
     * @return void
     */
    public static function purgeOldLogs()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . "confirmo_logs";

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE time < %d",
                $table_name,
                strtotime('-30 days', current_time('timestamp'))
            )
        );
    }

    /**
     * Exports stored logs to csv
     *
     * @return void
     */
    public function downloadLogs(): void
    {
        $table_name = $this->wpdb->prefix . "confirmo_logs";
        $wpdb = $this->wpdb;

        if (isset($_POST['confirmo_download_logs'])) {
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM %i ORDER BY time ASC",
                    $table_name
                )
            );

            header('Content-Type: text/csv');
            header("Content-Disposition: attachment; filename=confirmo_debug_logs.csv");
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

            $handle = fopen('php://output', 'w');
            ob_clean();

            fputcsv($handle, [__('Time', 'confirmo-for-woocommerce'), __('Order ID', 'confirmo-for-woocommerce'), __('API Response', 'confirmo-for-woocommerce'), __('Plugin version', 'confirmo-for-woocommerce')]);

            foreach ($logs as $log) {
                fputcsv($handle, [$log->time, $log->order_id, $log->api_response, $log->version]);
            }

            ob_flush();
            exit;
        }
    }

    /**
     * Deletes all logs from DB
     *
     * @return void
     */
    public function deleteLogs(): void
    {
        $table_name = $this->wpdb->prefix . "confirmo_logs";

        if (isset($_POST['action']) && $_POST['action'] === 'confirmo_delete_logs') {
            $wpdb = $this->wpdb;

            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM %i",
                    $table_name
                )
            );

            wp_redirect(admin_url('admin.php?page=confirmo-logs'));
            exit;
        }
    }

    /**
     * Registers plugin admin WP menu
     *
     * @return void
     */
    public function adminMenu(): void
    {
        add_menu_page(
            __('Confirmo payment gate', 'confirmo-for-woocommerce'),
            __('Confirmo Payment', 'confirmo-for-woocommerce'),
            'manage_options',
            'confirmo-payment',
            [$this, 'configPageContent'],
            'dashicons-money-alt',
            100
        );

        add_submenu_page(
            'confirmo-payment',
            __('Settings', 'confirmo-for-woocommerce'),
            __('Settings', 'confirmo-for-woocommerce'),
            'manage_options',
            'confirmo-payment',
            [$this, 'configPageContent']
        );

        add_submenu_page(
            'confirmo-payment',
            __('Logs', 'confirmo-for-woocommerce'),
            __('Logs', 'confirmo-for-woocommerce'),
            'manage_options',
            'confirmo-logs',
            [$this, 'debugPageContent']
        );
    }

    /**
     * Adds invoice URL to edit order page to WC admin
     *
     * @param $order
     * @return void
     */
    public function addUrlToEditOrder($order): void
    {
        $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);

        if ($confirmo_redirect_url) {
            echo '<p><strong>' . esc_html(__('Confirmo Payment URL:', 'confirmo-for-woocommerce')) . '</strong> <a href="' . esc_url($confirmo_redirect_url) . '" target="_blank">' . esc_url($confirmo_redirect_url) . '</a></p>';
        }
    }

    /**
     * Adds invoice URL to emails after order table
     *
     * @param $order
     * @param $sent_to_admin
     * @param $plain_text
     * @param $email
     * @return void
     */
    public function addUrlToEmails($order, $sent_to_admin, $plain_text, $email): void
    {
        if ($email->id == 'new_order' || $email->id == 'customer_on_hold_order') {
            $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);

            if ($confirmo_redirect_url) {
                echo $plain_text ? esc_html(__('Confirmo Payment URL:', 'confirmo-for-woocommerce')) . esc_url($confirmo_redirect_url) . "\n" : "<p><strong>" . esc_html(__('Confirmo Payment URL:', 'confirmo-for-woocommerce')) . "</strong> <a href='" . esc_url($confirmo_redirect_url) . "'>" . esc_url($confirmo_redirect_url) . "</a></p>";
            }
        }
    }

    /**
     * Enqueues plugins JS
     *
     * @return void
     */
    public function enqueueScripts(): void
    {
        global $confirmo_version;

        wp_enqueue_script('confirmo-custom-script', plugins_url('public/js/confirmo-crypto-gateway.js', $this->pluginBaseDir), ['jquery'], $confirmo_version, true);

        $image_url = plugins_url('public/img/confirmo.png', $this->pluginBaseDir);
        wp_localize_script('confirmo-custom-script', 'confirmoParams', [
            'imageUrl' => $image_url
        ]);
    }

    /**
     * Registers custom block if supported
     *
     * @return void
     */
    public function registerBlockPaymentMethodType(): void
    {
        // Check if the required class exists
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        // Include the custom Blocks Checkout class
        require_once plugin_dir_path(__FILE__) . 'class-confirmo-blocks.php';

        // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                // Register an instance of Confirmo_Blocks
                $payment_method_registry->register(new Confirmo_Blocks($this->pluginBaseDir));
            }
        );
    }

    /**
     * Compatibility with WooCommerce Blocks
     *
     * @return void
     */
    public function declareCartCheckoutBlocksCompatibility(): void
    {
        // Check if the required class exists
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            // Declare compatibility for 'cart_checkout_blocks'
            FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    /**
     * Loads translations
     *
     * @return void
     */
    public function loadTextDomain(): void
    {
        load_plugin_textdomain('confirmo-for-woocommerce', false, dirname($this->pluginName) . '/languages');
    }

    /**
     * Adds Confirmo payment plugin class to WC gateways
     *
     * @param array $gateways
     * @return array
     */
    public function addGatewayClass(array $gateways): array
    {
        $gateways[] = 'WC_Confirmo_Gateway';
        return $gateways;
    }

    /**
     * Registers custom query variables
     *
     * @param array $query_vars
     * @return array
     */
    public function addQueryVars(array $query_vars): array
    {
        $query_vars[] = 'confirmo-custom-payment';
        $query_vars[] = 'confirmo-notification';
        return $query_vars;
    }

    /**
     * Adds link to plugin settings to WP plugin overview page
     *
     * @param array $links
     * @return array
     */
    public function addSettingsLink(array $links): array
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=confirmo-payment') . '">' . __('Settings', 'confirmo-for-woocommerce') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Adds custom text to order status page
     *
     * @param string $original_text
     * @param $order
     * @return string
     */
    public function customOrderStatusThankyouText(string $original_text, $order): string
    {
        if (!$order) return $original_text;

        $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);
        $status = $order->get_status();
        $custom_text = '';

        switch ($status) {
            case 'on-hold':
                if ($confirmo_redirect_url) {
                    $custom_text = __('Your order will be completed once the payment receives sufficient confirmations.', 'confirmo-for-woocommerce') . '<br><br>' . __('Your Confirmo Payment URL:', 'confirmo-for-woocommerce') . ' <a href="' . $confirmo_redirect_url . '">' . $confirmo_redirect_url . '</a>';
                } else {
                    $custom_text = __('Your order is currently on hold, awaiting your payment.', 'confirmo-for-woocommerce');
                }
                break;
            case 'processing':
                if ($confirmo_redirect_url) {
                    $custom_text = __('Your payment has been completed.', 'confirmo-for-woocommerce') . '<br><br>' . __('Your Confirmo Payment URL:', 'confirmo-for-woocommerce') . ' <a href="' . $confirmo_redirect_url . '">' . $confirmo_redirect_url . '</a>';
                } else {
                    $custom_text = __('Your payment has been completed.', 'confirmo-for-woocommerce');
                }
                break;
        }

        if ($custom_text) {
            return $original_text . '<p>' . $custom_text . '</p>';
        } else {
            return $original_text;
        }
    }

    /**
     * Handles incoming invoice notification
     *
     * @return void
     */
    public function handleNotification(): void
    {
        global $wp_query;

        if (isset($wp_query->query_vars['confirmo-notification'])) {
            $json = file_get_contents('php://input');
            if (empty($json)) {
                wp_die('No data', '', ['response' => 400]);
            }

            // Validation callback password
            if (!empty($this->callbackPassword)) {
                $signature = hash('sha256', $json . $this->callbackPassword);
                if (!isset($_SERVER['HTTP_BP_SIGNATURE']) || $_SERVER['HTTP_BP_SIGNATURE'] !== $signature) {
                    $this->addDebugLog(null, "Confirmo: Signature validation failed!", 'handleNotification');
                    wp_die('Invalid signature', '', ['response' => 403]);
                }
            } else {
                $this->addDebugLog(null, "Confirmo: No callback password set, proceeding without validation.", 'handleNotification');
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                $this->addDebugLog(null, "Confirmo: Invalid JSON data received.", 'handleNotification');
                wp_die('Invalid data', '', ['response' => 400]);
            }

            // Sanitizace dat
            $data = $this->sanitizeArray($data);
            $order_id = $data['reference'];
            $order = wc_get_order($order_id);

            if (!$order) {
                $this->addDebugLog($order_id, "Confirmo: Failed to retrieve order with reference: " . $order_id, 'handleNotification');
                wp_die('Order not found', '', ['response' => 404]);
            }

            // Invoice status verification via API Confirmo
            $verified_status = $this->verifyInvoiceStatus($data['id']);

            // Checking if the states are compatible
            if ($verified_status !== false) {
                $this->updateOrderStatus($order, strtolower($verified_status));
                wp_die('OK', '', ['response' => 200]);
            } else {
                $this->addDebugLog($order_id, "Confirmo: Error fetching invoice status for order: " . $order_id, 'handleNotification');
                wp_die('Error fetching invoice status', '', ['response' => 409]);
            }
        }
    }

    /**
     * WC_Payment_Gateway parent function for payment processing
     * https://developer.woocommerce.com/docs/woocommerce-payment-gateway-api/
     *
     * @param $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        global $woocommerce;
        global $confirmo_version;

        $order = wc_get_order($order_id);
        $order_currency = $order->get_currency();
        $total_amount = $order->get_total();
        $product_name = '';
        $product_description = '';
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_name .= $product->get_name() . ' + ';
            $product_description .= $product->get_name() . ' (' . $item->get_quantity() . ') + ';
        }

        $product_name = rtrim($product_name, ' + ');
        $product_description = rtrim($product_description, ' + ');
        $customer_email = $order->get_billing_email();

        $url = $this->apiBaseUrl . '/api/v3/invoices';

        $notify_url = $this->generateNotifyUrl();
        $return_url = $order->get_checkout_order_received_url();

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
            'X-Payment-Module' => 'WooCommerce',
            'Payment-Module-Version' => $confirmo_version,
        ];

        if ($this->settlementCurrency === 'Crypto Settlement (In Kind)') {
            $this->settlementCurrency = null;
        }

        $customer_profile = [
            'profileId' => $order->get_billing_email(),
            'type' => $order->get_billing_company() ? 'company' : 'individual',
            'streetAddress' => $order->get_billing_address_1(),
            'city' => $order->get_billing_city(),
            'postalCode' => $order->get_billing_postcode(),
            'country' => $order->get_billing_country(),
        ];

        if ($order->get_billing_company()) {
            $customer_profile['registeredName'] = $order->get_billing_company();    
        } else {
            $customer_profile['firstName'] = $order->get_billing_first_name();
            $customer_profile['lastName'] = $order->get_billing_last_name();
        }

        $body = [
            'settlement' => ['currency' => $this->settlementCurrency],
            'product' => ['name' => $product_name, 'description' => $product_description],
            'invoice' => ['currencyFrom' => $order_currency, 'amount' => $total_amount],
            'notificationUrl' => $notify_url,
            'notifyUrl' => $notify_url,
            'returnUrl' => $return_url,
            'reference' => strval($order_id),
            'customerEmail' => $customer_email,
            'customerProfile' => $customer_profile
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'method' => 'POST',
            'data_format' => 'body'
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wc_add_notice(__('Payment error: ', 'confirmo-for-woocommerce') . $error_message, 'error');
            return [];
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (!isset($response_data['url'])) {
            $this->addDebugLog($order_id, $response_body, 'process_payment');
            wc_add_notice(__('Payment error: The Confirmo API response did not contain a url.', 'confirmo-for-woocommerce'), 'error');
            return [];
        }

        $confirmo_redirect_url = $response_data['url'];
        update_post_meta($order_id, '_confirmo_redirect_url', $confirmo_redirect_url);

        // Change: Set initial order status to 'pending'
        $order->update_status('pending', __('Awaiting Confirmo payment.', 'confirmo-for-woocommerce'));

        wc_reduce_stock_levels($order_id);
        $woocommerce->cart->empty_cart();

        if ($order_id && $response_data) {
            $this->addDebugLog($order_id, wp_json_encode($response_data), $confirmo_redirect_url);
        } else {
            $this->addDebugLog(null, "Missing order_id or response_data", 'process_payment');
        }

        return [
            'result' => 'success',
            'redirect' => $confirmo_redirect_url
        ];
    }

    /**
     * WC_Payment_Gateway parent function for setting up config form in admin
     * https://developer.woocommerce.com/docs/woocommerce-payment-gateway-api/
     *
     * @return void
     */
    public function init_form_fields(): void
    {
        $settlement_currency_options = [
            'BTC' => 'BTC',
            'CZK' => 'CZK',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'HUF' => 'HUF',
            'PLN' => 'PLN',
            'USD' => 'USD',
            'USDC' => 'USDC',
            'USDT' => 'USDT',
            '' => __('No conversion (the currency stays as it is)', 'confirmo-for-woocommerce'),
        ];

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'confirmo-for-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Confirmo Payment', 'confirmo-for-woocommerce'),
                'default' => 'no'
            ],
            'api_key' => [
                'title' => __('API Key', 'confirmo-for-woocommerce'),
                'type' => 'text',
                'description' => __('Enter your Confirmo API Key here', 'confirmo-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'required'
                ]
            ],
            'callback_password' => [
                'title' => __('Callback Password', 'confirmo-for-woocommerce'),
                'type' => 'text',
                'description' => __('Enter your Confirmo Callback Password', 'confirmo-for-woocommerce'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'required'
                ]
            ],
            'settlement_currency' => [
                'title' => __('Settlement Currency', 'confirmo-for-woocommerce'),
                'type' => 'select',
                'desc_tip' => true,
                'description' => __('Settlement currency refers to the currency in which a crypto payment is finalized or settled.', 'confirmo-for-woocommerce'),
                'options' => $settlement_currency_options,
            ],
        ];
    }

    /**
     * Generate URL to handle incoming invoice notifications
     *
     * @return string
     */
    private function generateNotifyUrl(): string
    {
        // Getting the base URL using the home_url function, which automatically resolves language variants
        $notify_url = home_url('?confirmo-notification=1');

        // Sanitizing the URL so that it does not contain invalid characters
        $notify_url = esc_url($notify_url);

        // Check if the URL contains a pipe or other invalid characters
        if (strpos($notify_url, '|') !== false) {
            // If a pipe character is found, remove it
            $notify_url = str_replace('|', '', $notify_url);
        }

        return $notify_url;
    }

    /**
     * Verifies invoice status against Confirmo API
     *
     * @param $invoice_id
     * @return false|mixed
     */
    private function verifyInvoiceStatus($invoice_id)
    {
        $api_key = $this->apiKey;
        $url = $this->apiBaseUrl . '/api/v3/invoices/' . $invoice_id;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ]
        ]);

        if (is_wp_error($response)) {
            $this->addDebugLog($invoice_id,"Confirmo: Error verifying invoice status: " . $response->get_error_message(), 'verifyInvoiceStatus');
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $invoice_data = json_decode($response_body, true);

        if (isset($invoice_data['status'])) {
            return $invoice_data['status'];
        }

        return false;
    }

    /**
     * Update order status based on Confirmo status
     *
     * @param $order
     * @param string $confirmo_status
     * @return void
     */
    private function updateOrderStatus($order, string $confirmo_status): void
    {
        $options = get_option('confirmo_gate_config_options');
        $values = $options['custom_states'];

        switch ($confirmo_status) {
            case 'prepared':
                $message = __('Payment instructions created, awaiting payment.', 'confirmo-for-woocommerce');
                break;
            case 'active':
                $message = __('Client selects crypto payment method, awaiting payment.', 'confirmo-for-woocommerce');
                break;
            case 'confirming':
                $message = __('Payment received, awaiting confirmations', 'confirmo-for-woocommerce');
                break;
            case 'paid':
                $message = __('Payment confirmed, letting woocommerce decide whether to complete order or set to processing', 'confirmo-for-woocommerce');
                break;
            case 'expired':
                $message =  __('Payment expired or insufficient amount', 'confirmo-for-woocommerce');
                break;
            case 'error':
                $message = __('Payment confirmation failed', 'confirmo-for-woocommerce');
                break;
            default:
                $this->addDebugLog($order->get_id(), "Received unknown status: " . $confirmo_status, 'order_status_update');
                return;
        }

        if ($values[$confirmo_status] === 'completed' || $values[$confirmo_status] === 'processing') {
            $order->payment_complete();
        }

        $changed = $order->update_status($values[$confirmo_status], $message);
    
        if (!$changed) {
            $this->addDebugLog($order->get_id(), __('Order update status failed', 'confirmo-for-woocommerce'), 'order_status_update');
        }
        
        $this->addDebugLog($order->get_id(), "Order status updated to: " . $order->get_status() . " based on Confirmo status: " . $confirmo_status, 'order_status_update');
    }

    /**
     * Adds custom debug log to DB
     *
     * @param $order_id
     * @param string $api_response
     * @param string $hook
     * @return void
     */
    private function addDebugLog($order_id, string $api_response, string $hook): void
    {
        global $confirmo_version;
        $table_name = $this->wpdb->prefix . "confirmo_logs";

        $this->wpdb->insert($table_name, [
            'time' => current_time('mysql'),
            'order_id' => $order_id,
            'api_response' => $api_response,
            'hook' => $hook,
            'version' => $confirmo_version
        ]);
    }

    /**
     * Sanitizes array recursively
     *
     * @param array $array
     * @return array
     */
    private function sanitizeArray(array $array): array
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->sanitizeArray($value);
            } else {
                $array[$key] = sanitize_text_field($value);
            }
        }
        return $array;
    }

    /**
     * Creates payment (invoice) via Confirmo API
     *
     * @param string $currency
     * @param string $amount
     * @param string $api_key
     * @return mixed
     */
    private function createPayment(string $currency, string $amount, string $api_key)
    {
        $notification_url = home_url('confirmo-notification');
        $return_url = wc_get_cart_url();
        $url = $this->apiBaseUrl . '/api/v3/invoices';

        $data = [
            'settlement' => ['currency' => $currency],
            'product' => ['name' => __('Custom Payment', 'confirmo-for-woocommerce'), 'description' => __('Payment via Confirmo Button', 'confirmo-for-woocommerce')],
            'invoice' => ['currencyFrom' => $currency, 'amount' => $amount],
            'notificationUrl' => $notification_url,
            'returnUrl' => $return_url,
            'reference' => 'custom-button-payment'
        ];

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-Payment-Module' => 'WooCommerce'
            ],
            'body' => wp_json_encode($data)
        ]);

        return $response;
    }

}
