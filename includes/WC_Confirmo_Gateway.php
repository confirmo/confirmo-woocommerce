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
 * @method get_option(string $string)
 */
class WC_Confirmo_Gateway extends WC_Payment_Gateway
{
    protected string $apiKey;
    protected string $settlementCurrency;
    protected string $callbackPassword;
    protected WC_Confirmo_Loader $loader;
    protected $wpdb;
    public string $pluginName;
    private array $allowedCurrencies = [
        'BTC',
        'CZK',
        'EUR',
        'GBP',
        'HUF',
        'PLN',
        'USD',
        'USDC',
        'USDT',
        ''
    ];

    public function __construct()
    {
        $this->wpdb = $GLOBALS['wpdb'];

        $this->id = "confirmo";
        $this->method_title = __("Confirmo", 'confirmo-payment-gateway');
        $this->method_description = __("Settings have been moved. Please configure the gateway ", 'confirmo-payment-gateway') . "<a href='" . admin_url('admin.php?page=confirmo-payment-gate-config') . "'>" . __("here", 'confirmo-payment-gateway') . "</a>.";
        $this->title = __("Confirmo", 'confirmo-payment-gateway');
        $this->description = $this->get_option('description');
        $this->apiKey = $this->get_option('api_key');
        $this->settlementCurrency = $this->get_option('settlement_currency');
        $this->callbackPassword = $this->get_option('callback_password');
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
        $this->loader = new WC_Confirmo_Loader();

        $this->loader->addAction('init', [$this, 'addEndpoints']);

        $this->loader->addAction('admin_init', [$this, 'registerPaymentGateConfigSettings']);

        $this->loader->addAction('admin_menu', [$this, 'adminMenu']);

        $this->loader->addAction('template_redirect', [$this, 'handleNotification']);
        $this->loader->addAction('template_redirect', [$this, 'customPaymentTemplateRedirect']);

        $this->loader->addAction('plugins_loaded', [$this, 'loadTextDomain']);
        $this->loader->addAction('plugins_loaded', [$this, 'updateDbCheck']);

        $this->loader->addAction('before_woocommerce_init', [$this, 'declareCartCheckoutBlocksCompatibility']);
        $this->loader->addAction('woocommerce_blocks_loaded', [$this, 'registerBlockPaymentMethodType']);
        $this->loader->addAction('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        $this->loader->addAction('woocommerce_email_after_order_table', [$this, 'addUrlToEmails']);
        $this->loader->addAction('woocommerce_admin_order_data_after_billing_address', [$this, 'addUrlToEditOrder']);
        $this->loader->addAction('wp_ajax_confirmo_custom_payment', [$this, 'customPayment']);
        $this->loader->addAction('wp_ajax_nopriv_confirmo_custom_payment', [$this, 'customPayment']);

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
     * Validate submitted settings options
     *
     * @param array $input
     * @return array
     */
    public function validateConfigOptions(array $input): array
    {
        $new_input = [];
        $option_key = 'woocommerce_confirmo_settings';
        $settings = get_option($option_key, []);

        if (isset($input['description'])) {
            $description = sanitize_text_field($input['description']);
            $new_input['description'] = $description;
            $this->setWCOption("confirmo", "description", $description);
        }

        if (isset($input['enabled'])) {
            $new_input['enabled'] = $input['enabled'] === 'on' ? 'yes' : 'no';
            $this->setWCOption("confirmo", "enabled", $new_input['enabled']);
        }

        if (isset($input['api_key'])) {
            $api_key = sanitize_text_field($input['api_key']);
            if (strlen($api_key) == 64 && ctype_alnum($api_key)) {
                $new_input['api_key'] = $api_key;
                $this->setWCOption("confirmo", "api_key", $new_input['api_key']);
            } else {
                $new_input['api_key'] = $settings['api_key'] ?? '';
                add_settings_error('api_key', 'api_key_error', __('API Key must be exactly 64 alphanumeric characters', 'confirmo-payment-gateway'), 'error');
            }
        }

        if (isset($input['callback_password'])) {
            $callback_password = sanitize_text_field($input['callback_password']);
            if (strlen($callback_password) == 16 && ctype_alnum($callback_password)) {
                $new_input['callback_password'] = $callback_password;
                $this->setWCOption("confirmo", "callback_password", $new_input['callback_password']);
            } else {
                $new_input['callback_password'] = $settings['callback_password'] ?? '';
                add_settings_error('callback_password', 'callback_password_error', __('Callback Password must be 16 alphanumeric characters', 'confirmo-payment-gateway'), 'error');
            }
        }

        if (isset($input['settlement_currency'])) {
            $settlement_currency = $input['settlement_currency']; //This is a number, 0-8..
            if ($this->allowedCurrencies[$settlement_currency]) {
                $this->setWCOption("confirmo", "settlement_currency", $this->allowedCurrencies[$settlement_currency]);
                $new_input['settlement_currency'] = $this->allowedCurrencies[$settlement_currency];
            } else {
                $new_input['settlement_currency'] = $settings['settlement_currency'] ?? '';
                add_settings_error('settlement_currency', 'settlement_currency_error', __('Invalid settlement currency selected.', 'confirmo-payment-gateway'), 'error');
            }
        }

        return $new_input;
    }

    public function configSectionCallback(): void
    {
        echo '<p>' . esc_html__('Adjust the settings for Confirmo payment gateway.', 'confirmo-payment-gateway') . '</p>';
    }

    public function configEnabledCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $checked = isset($options['enabled']) && $options['enabled'] ? 'checked' : '';
        echo '<input type="checkbox" id="enabled" name="confirmo_gate_config_options[enabled]" ' . esc_attr($checked) . '>';
    }

    public function configApiKeyCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $value = isset($options['api_key']) ? esc_attr($options['api_key']) : '';
        echo('<input type="text" id="api_key" name="confirmo_gate_config_options[api_key]" value="' . esc_attr($value) . '" size="70" maxlength="64" required>');
    }

    public function configCallbackPasswordCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $value = isset($options['callback_password']) ? esc_attr($options['callback_password']) : '';
        echo('<input type="text" id="callback_password" name="confirmo_gate_config_options[callback_password]" value="' . esc_attr($value) . '" required>');
    }

    public function configSettlementCurrencyCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $current_value = $options['settlement_currency'] ?? 'test';
        echo '<select id="settlement_currency" name="confirmo_gate_config_options[settlement_currency]">';
        foreach ($this->allowedCurrencies as $key => $label) {
            $selected = ($label == $current_value) ? 'selected' : '';
            echo('<option value="' . esc_attr($key) . '" ' . esc_attr($selected) . '>' . esc_html($label) . '</option>');
        }
        echo '</select>';
    }

    public function configDescriptionCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $value = isset($options['description']) ? esc_textarea($options['description']) : '';
        echo '<textarea id="description" name="confirmo_gate_config_options[description]" rows="5" cols="50">' . esc_textarea($value) . '</textarea>';
    }

    /**
     * Displays main plugin page content
     *
     * @return void
     */
    public function mainPageContent(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('Confirmo Cryptocurrency Payment Gateway', 'confirmo-payment-gateway')) . '</h1>';
        echo '<p>' . esc_html(__('Crypto payments made easy with industry leaders. Confirmo.net', 'confirmo-payment-gateway')) . '</p>';

        echo '<h2>' . esc_html(__('Enable the future of payments today', 'confirmo-payment-gateway')) . '</h2>';
        echo '<p>' . esc_html(__('Start accepting cryptocurrency payments with Confirmo, one of the fastest growing companies in crypto payments! We provide a payment gateway used by Forex brokers, prop trading companies, e-commerce merchants, and luxury businesses worldwide. Our clients include FTMO, My Forex Funds, Alza and many more. All rely on our easily integrated solutions, low fees, and top-class customer support.', 'confirmo-payment-gateway')) . '</p>';

        echo '<h2>' . esc_html__('Installing the plugin', 'confirmo-payment-gateway') . '</h2>';
        echo '<h3>' . esc_html__('WordPress plugins:', 'confirmo-payment-gateway') . '</h3>';
        echo '<ol>';
        echo '<li>' . esc_html__('In your WordPress dashboard, go to Plugins – Add New, and search for \'Confirmo Cryptocurrency Payment Gateway\'.', 'confirmo-payment-gateway') . '</li>';
        echo '<li>' . esc_html__('Click Download, and then activate the plugin.', 'confirmo-payment-gateway') . '</li>';
        echo '<li>' . esc_html__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
        echo '</ol>';

        echo '<h3>' . esc_html(__('Upload:', 'confirmo-payment-gateway')) . '</h3>';
        echo '<ol>';
        echo '<li>' . esc_html(__('Download and extract the .zip file.', 'confirmo-payment-gateway')) . '</li>';
        echo '<li>' . esc_html(__('In your WordPress dashboard, go to Plugins – Add New – Upload Plugin, and upload the extracted folder. Activate the plugin.', 'confirmo-payment-gateway')) . '</li>';
        echo '<li>' . esc_html(__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway')) . '</li>';
        echo '</ol>';

        echo '<h3>' . esc_html__('FTP or File Manager:', 'confirmo-payment-gateway') . '</h3>';
        echo '<ol>';
        echo '<li>' . esc_html__('Download and extract the .zip file.', 'confirmo-payment-gateway') . '</li>';
        echo '<li>' . esc_html__('Copy the extracted contents into your WordPress installation under wp-content/plugins.', 'confirmo-payment-gateway') . '</li>';
        echo '<li>' . esc_html__('In your WordPress dashboard, go to Plugins – Installed plugins – Confirmo Cryptocurrency Payment Gateway. Activate the plugin.', 'confirmo-payment-gateway') . '</li>';
        echo '<li>' . esc_html__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
        echo '</ol>';


        echo '<h2>' . esc_html(__('Connecting the plugin to your Confirmo account:', 'confirmo-payment-gateway')) . '</h2>';
        echo '<p>' . esc_html(__('Create an account at', 'confirmo-payment-gateway')) . ' <a href="https://confirmo.net">Confirmo.net</a> ' . esc_html(__('and then go to Settings – API Keys – Create API key. You will be required to complete an e-mail verification, after which you will receive the API key. Once you have it, go to WooCommerce – Settings – Payments, and enable Confirmo as a payment method. Paste the API key into the respective field.', 'confirmo-payment-gateway')) . '</p>';

        echo '<p>' . esc_html(__('To generate a callback password, return to the Confirmo dashboard and go to Settings – Callback password. You will be prompted to complete a second e-mail verification and then provided with the callback password. Again, paste it into the respective field in WooCommerce – Settings – Payments. Callback passwords help increase the security of the API integration. Never share your API key or callback password with anyone!', 'confirmo-payment-gateway')) . '</p>';

        echo '<p>' . esc_html(__('Finally, choose your desired Settlement currency. Make sure to save your changes by clicking the button at the bottom. When the plugin is activated, Confirmo will appear as a payment option in your website\'s WooCommerce checkout. <b>Congratulations, you can now start receiving cryptocurrency payments!</b>', 'confirmo-payment-gateway')) . '</p>';

        echo '<p>' . esc_html(__('Read more at', 'confirmo-payment-gateway')) . ' <a href="https://confirmo.net">Confirmo.net</a>. ' . esc_html(__('Should you encounter any difficulties, contact us at', 'confirmo-payment-gateway')) . ' <a href="mailto:support@confirmo.net">support@confirmo.net</a></p>';

        echo '</div>';
    }

    /**
     * Displays payment button generator page
     *
     * @return void
     */
    public function generatorPageContent(): void
    {
        $currency_options = [
            'BTC' => 'BTC',
            'CZK' => 'CZK',
            'EUR' => 'EUR',
            'GBP' => 'GBP',
            'HUF' => 'HUF',
            'PLN' => 'PLN',
            'USD' => 'USD',
            'USDC' => 'USDC',
            'USDT' => 'USDT',
            '' => __('Keep it in kind (no conversion)', 'confirmo-payment-gateway'),
        ];

        $current_currency = $this->getWCOption("confirmo", "settlement_currency")();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Payment Button Generator', 'confirmo-payment-gateway') . '</h1>';
        echo '<form method="post" action="">';
        wp_nonce_field('confirmo_set_style', 'confirmo_set_style_nonce');
        echo '<table class="form-table">';
        echo '<tr valign="top">';
        echo '<th scope="row">' . esc_html(__('Currency', 'confirmo-payment-gateway')) . '</th>';
        echo '<td>';
        echo '<select name="confirmo_currency" required>';
        foreach ($currency_options as $value => $label) {
            echo '<option value="' . esc_attr($value) . ' ' . selected($current_currency, $value) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr valign="top">';
        echo '<th scope="row">' . esc_html(__('Amount', 'confirmo-payment-gateway')) . '</th>';
        echo '<td>';
        echo '<input type="text" name="confirmo_amount" value="0" required/>';
        echo '</td>';
        echo '</tr>';
        echo '<tr valign="top">';
        echo '<th scope="row">' . esc_html(__('Button Color', 'confirmo-payment-gateway')) . '</th>';
        echo '<td>';
        echo '<input type="color" name="confirmo_button_color" value="#000000" required/>';
        echo '</td>';
        echo '</tr>';
        echo '<tr valign="top">';
        echo '<th scope="row">' . esc_html(__('Text Color', 'confirmo-payment-gateway')) . '</th>';
        echo '<td>';
        echo '<input type="color" name="confirmo_text_color" value="#FFFFFF" required/>';
        echo '</td>';
        echo '</tr>';
        echo '<tr valign="top">';
        echo '<th scope="row">' . esc_html(__('Border Radius (px)', 'confirmo-payment-gateway')) . '</th>';
        echo '<td>';
        echo '<input type="number" name="confirmo_border_radius" value="0" required/>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        echo '<p class="submit">';
        echo '<button type="submit" class="button-primary">' . esc_html(__('Generate Shortcode', 'confirmo-payment-gateway')) . '</button>';
        echo '</p>';
        echo '</form>';
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST["confirmo_set_style_nonce"]) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST["confirmo_set_style_nonce"])), "confirmo_set_style")) {
            if (!isset($_POST['confirmo_currency'], $_POST['confirmo_amount'], $_POST['confirmo_button_color'], $_POST['confirmo_text_color'], $_POST['confirmo_border_radius'])) {
                wp_die(esc_html(__('Error: Missing POST data.', 'confirmo-payment-gateway')));
            }

            $currency = sanitize_text_field(wp_unslash($_POST['confirmo_currency']));
            $amount = sanitize_text_field(wp_unslash($_POST['confirmo_amount']));
            $button_color = sanitize_hex_color(wp_unslash($_POST['confirmo_button_color']));
            $text_color = sanitize_hex_color(wp_unslash($_POST['confirmo_text_color']));
            $border_radius = intval($_POST['confirmo_border_radius']);

            echo "<h2>" . esc_html(__('Generated Shortcode:', 'confirmo-payment-gateway')) . "</h2>";
            echo "<code>[confirmo currency=\"" . esc_attr($currency) . "\" amount=\"" . esc_attr($amount) . "\" button_color=\"" . esc_attr($button_color) . "\" text_color=\"" . esc_attr($text_color) . "\" border_radius=\"" . esc_attr($border_radius) . "\"]</code>";
        }
        echo '</div>';
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

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i ORDER BY time ASC",
                $table_name
            )
        );

        WP_Filesystem();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(__('Confirmo Debug Information', 'confirmo-payment-gateway')) . '</h1>';
        echo '<p>' . esc_html(__('If you encounter any issues, please download these debug logs and send them to plugin support.', 'confirmo-payment-gateway')) . '</p>';
        echo "<p>SHA-256 Hash: " . esc_html(hash('sha256', $wp_filesystem->get_contents(__FILE__))) . "</p>";

        if (!empty($logs)) {
            echo '<table class="widefat fixed" cellspacing="0">';
            echo '<thead><tr><th>' . esc_html(__('Time', 'confirmo-payment-gateway')) . '</th><th>' . esc_html(__('Order ID', 'confirmo-payment-gateway')) . '</th><th>' . esc_html(__('API Response', 'confirmo-payment-gateway')) . '</th><th>' . esc_html(__('Redirect URL', 'confirmo-payment-gateway')) . '</th></thead>';
            echo '<tbody>';
            foreach ($logs as $log) {
                echo '<tr>';
                echo '<td>' . esc_html($log->time) . '</td>';
                echo '<td>' . (isset($log->order_id) ? esc_html($log->order_id) : 'N/A') . '</td>';
                echo '<td>' . (isset($log->api_response) ? esc_html($log->api_response) : 'N/A') . '</td>';
                echo '<td>' . (isset($log->hook) ? esc_html($log->hook) : "N/A") . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="confirmo_download_logs" value="1">';
            echo '<p><button type="submit" class="button button-primary">' . esc_html(__('Download Debug Logs', 'confirmo-payment-gateway')) . '</button></p>';
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="confirmo_delete_logs">';
            echo '<p><button type="submit" class="button button-secondary">' . esc_html(__('Delete all logs', 'confirmo-payment-gateway')) . '</button></p>';
            echo '</form>';
        } else {
            echo '<p>' . esc_html(__('No debug logs available for the last day.', 'confirmo-payment-gateway')) . '</p>';
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
        submit_button(__('Save Settings', 'confirmo-payment-gateway'));
        echo '</form>';
        echo '</div>';
    }

    /**
     * Create AJAX payment
     *
     * @return void
     */
    public function customPayment(): void
    {
        if (!isset($_POST['currency'])) {
            wp_send_json_error(__('Error: Currency is missing.', 'confirmo-payment-gateway'));
        }

        if (!isset($_POST['amount'])) {
            wp_send_json_error(__('Error: Amount is missing.', 'confirmo-payment-gateway'));
        }

        $currency = sanitize_text_field(wp_unslash($_POST['currency']));
        $amount = sanitize_text_field(wp_unslash($_POST['amount']));
        $api_key = get_option('woocommerce_confirmo_api_key');

        if (empty($api_key)) {
            wp_send_json_error(__('Error: API key is missing.', 'confirmo-payment-gateway'));
        }

        $response = $this->createPayment($currency, $amount, $api_key);

        if (is_wp_error($response)) {
            wp_send_json_error(__('Error: ', 'confirmo-payment-gateway') . $response->get_error_message());
            return;
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if ($response_data) {
            $this->addDebugLog('custom-button-payment', wp_json_encode($response_data), 'create_payment');
        } else {
            error_log("Missing order_id or response_data");
        }

        if (isset($response_data['url'])) {
            wp_send_json_success(['url' => $response_data['url']]);
        } else {
            wp_send_json_error(__('Error: Payment URL not received.', 'confirmo-payment-gateway'));
        }
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
                wp_die(esc_html(__('Error: Missing currency.', 'confirmo-payment-gateway')));
            }

            if (!isset($_POST['amount'])) {
                wp_die(esc_html(__('Error: Missing amount.', 'confirmo-payment-gateway')));
            }

            $currency = sanitize_text_field(wp_unslash($_POST['currency']));
            $amount = sanitize_text_field(wp_unslash($_POST['amount']));
            $api_key = get_option('woocommerce_confirmo_api_key');

            if (empty($api_key)) {
                wp_die(esc_html(__('Error: API key is missing.', 'confirmo-payment-gateway')));
            }

            $response = $this->createPayment($currency, $amount, $api_key);

            if (is_wp_error($response)) {
                wp_die(esc_html(__('Error:', 'confirmo-payment-gateway') . ' ' . $response->get_error_message()));
                return;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if ($response_data) {
                $this->addDebugLog('custom-redirect-payment', wp_json_encode($response_data), 'create_payment');
            } else {
                error_log("Missing order_id or response_data");
            }

            if (isset($response_data['url'])) {
                wp_redirect($response_data['url']);
                exit;
            } else {
                wp_die(esc_html(__('Error: Payment URL not received.', 'confirmo-payment-gateway')));
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
    public function purgeOldLogs()
    {
        $table_name = $this->wpdb->prefix . "confirmo_logs";
        $wpdb = $this->wpdb;

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

            fputcsv($handle, [__('Time', 'confirmo-payment-gateway'), __('Order ID', 'confirmo-payment-gateway'), __('API Response', 'confirmo-payment-gateway')]);

            foreach ($logs as $log) {
                fputcsv($handle, [$log->time, $log->order_id, $log->api_response]);
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
     * Registers plugin settings
     *
     * @return void
     */
    public function registerPaymentGateConfigSettings(): void
    {
        register_setting('confirmo-payment-gate-config', 'confirmo_gate_config_options', [$this, 'validateConfigOptions']);

        add_settings_section(
            'confirmo_gate_config_main',
            __('Main Settings', 'confirmo-payment-gateway'),
            [$this, 'configSectionCallback'],
            'confirmo-payment-gate-config'
        );

        add_settings_field(
            'enabled',
            __('Enable/Disable', 'confirmo-payment-gateway'),
            [$this, 'configEnabledCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'confirmo-payment-gateway'),
            [$this, 'configApiKeyCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'callback_password',
            __('Callback Password', 'confirmo-payment-gateway'),
            [$this, 'configCallbackPasswordCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'settlement_currency',
            __('Settlement Currency', 'confirmo-payment-gateway'),
            [$this, 'configSettlementCurrencyCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'description',
            __('Description on checkout page', 'confirmo-payment-gateway'),
            [$this, 'configDescriptionCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );
    }

    /**
     * Registers plugin admin WP menu
     *
     * @return void
     */
    public function adminMenu(): void
    {
        add_menu_page(
            __('Confirmo Payment', 'confirmo-payment-gateway'),
            __('Confirmo Payment', 'confirmo-payment-gateway'),
            'manage_options',
            'confirmo-payment',
            [$this, 'mainPageContent'],
            'dashicons-money-alt',
            100
        );

        add_submenu_page(
            'confirmo-payment',
            __('Payment Button Generator', 'confirmo-payment-gateway'),
            __('Payment Button Generator', 'confirmo-payment-gateway'),
            'manage_options',
            'confirmo-payment-generator',
            [$this, 'generatorPageContent']
        );

        add_submenu_page(
            'confirmo-payment',
            __('Payment Gate Config', 'confirmo-payment-gateway'),
            __('Payment Gate Config', 'confirmo-payment-gateway'),
            'manage_options',
            'confirmo-payment-gate-config',
            [$this, 'configPageContent']
        );

        add_submenu_page(
            'confirmo-payment',
            __('Logs', 'confirmo-payment-gateway'),
            __('Logs', 'confirmo-payment-gateway'),
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
            echo '<p><strong>' . esc_html(__('Confirmo Payment URL:', 'confirmo-payment-gateway')) . '</strong> <a href="' . esc_url($confirmo_redirect_url) . '" target="_blank">' . esc_url($confirmo_redirect_url) . '</a></p>';
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
                echo $plain_text ? esc_html(__('Confirmo Payment URL:', 'confirmo-payment-gateway')) . esc_url($confirmo_redirect_url) . "\n" : "<p><strong>" . esc_html(__('Confirmo Payment URL:', 'confirmo-payment-gateway')) . "</strong> <a href='" . esc_url($confirmo_redirect_url) . "'>" . esc_url($confirmo_redirect_url) . "</a></p>";
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

        wp_enqueue_script('confirmo-custom-script', plugins_url('public/js/confirmo-crypto-gateway.js', __FILE__), ['jquery'], $confirmo_version, true);

        $image_url = plugins_url('public/img/confirmo.png', __FILE__);
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
                $payment_method_registry->register(new Confirmo_Blocks);
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
        load_plugin_textdomain('confirmo-payment-gateway', false, dirname($this->pluginName) . '/languages');
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
        $settings_link = '<a href="' . admin_url('admin.php?page=confirmo-payment-gate-config') . '">' . __('Settings', 'confirmo-payment-gateway') . '</a>';
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
                    $custom_text = __('Your order will be completed once the payment receives sufficient confirmations.', 'confirmo-payment-gateway') . '<br><br>' . __('Your Confirmo Payment URL:', 'confirmo-payment-gateway') . ' <a href="' . $confirmo_redirect_url . '">' . $confirmo_redirect_url . '</a>';
                } else {
                    $custom_text = __('Your order is currently on hold, awaiting your payment.', 'confirmo-payment-gateway');
                }
                break;
            case 'processing':
                if ($confirmo_redirect_url) {
                    $custom_text = __('Your payment has been completed.', 'confirmo-payment-gateway') . '<br><br>' . __('Your Confirmo Payment URL:', 'confirmo-payment-gateway') . ' <a href="' . $confirmo_redirect_url . '">' . $confirmo_redirect_url . '</a>';
                } else {
                    $custom_text = __('Your payment has been completed.', 'confirmo-payment-gateway');
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
                    error_log("Confirmo: Signature validation failed!");
                    wp_die('Invalid signature', '', ['response' => 403]);
                }
            } else {
                error_log("Confirmo: No callback password set, proceeding without validation.");
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                error_log("Confirmo: Invalid JSON data received.");
                wp_die('Invalid data', '', ['response' => 400]);
            }

            // Sanitizace dat
            $data = $this->sanitizeArray($data);
            $order_id = $data['reference'];
            $order = wc_get_order($order_id);

            if (!$order) {
                error_log("Confirmo: Failed to retrieve order with reference: " . $order_id);
                wp_die('Order not found', '', ['response' => 404]);
            }

            // Invoice status verification via API Confirmo
            $verified_status = $this->verifyInvoiceStatus($data['id']);

            // Checking if the states are compatible
            if ($verified_status !== false) {
                $this->updateOrderStatus($order, strtolower($verified_status));
                wp_die('OK', '', ['response' => 200]);
            } else {
                error_log("Confirmo: Error fetching invoice status for order: " . $order_id);
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

        $api_key = $this->get_option('api_key');
        $url = 'https://confirmo.net/api/v3/invoices';

        $notify_url = $this->generateNotifyUrl();
        $return_url = $order->get_checkout_order_received_url();
        $settlement_currency = $this->get_option('settlement_currency');

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
            'X-Payment-Module' => 'WooCommerce'
        ];

        $body = [
            'settlement' => ['currency' => $settlement_currency],
            'product' => ['name' => $product_name, 'description' => $product_description],
            'invoice' => ['currencyFrom' => $order_currency, 'amount' => $total_amount],
            'notificationUrl' => $notify_url,
            'notifyUrl' => $notify_url,
            'returnUrl' => $return_url,
            'reference' => strval($order_id),
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => wp_json_encode($body),
            'method' => 'POST',
            'data_format' => 'body'
        ]);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            wc_add_notice(__('Payment error: ', 'confirmo-payment-gateway') . $error_message, 'error');
            return [];
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (!isset($response_data['url'])) {
            $this->addDebugLog($order_id, $response_body, 'process_payment');
            wc_add_notice(__('Payment error: The Confirmo API response did not contain a url.', 'confirmo-payment-gateway'), 'error');
            return [];
        }

        $confirmo_redirect_url = $response_data['url'];
        update_post_meta($order_id, '_confirmo_redirect_url', $confirmo_redirect_url);

        // Change: Set initial order status to 'pending'
        $order->update_status('pending', __('Awaiting Confirmo payment.', 'confirmo-payment-gateway'));

        wc_reduce_stock_levels($order_id);
        $woocommerce->cart->empty_cart();

        if ($order_id && $response_data) {
            $this->addDebugLog($order_id, wp_json_encode($response_data), $confirmo_redirect_url);
        } else {
            error_log("Missing order_id or response_data");
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
            '' => __('No conversion (the currency stays as it is)', 'confirmo-payment-gateway'),
        ];

        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'confirmo-payment-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable Confirmo Payment', 'confirmo-payment-gateway'),
                'default' => 'no'
            ],
            'api_key' => [
                'title' => __('API Key', 'confirmo-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter your Confirmo API Key here', 'confirmo-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'required'
                ]
            ],
            'callback_password' => [
                'title' => __('Callback Password', 'confirmo-payment-gateway'),
                'type' => 'text',
                'description' => __('Enter your Confirmo Callback Password', 'confirmo-payment-gateway'),
                'default' => '',
                'desc_tip' => true,
                'custom_attributes' => [
                    'required' => 'required'
                ]
            ],
            'settlement_currency' => [
                'title' => __('Settlement Currency', 'confirmo-payment-gateway'),
                'type' => 'select',
                'desc_tip' => true,
                'description' => __('Settlement currency refers to the currency in which a crypto payment is finalized or settled.', 'confirmo-payment-gateway'),
                'options' => $settlement_currency_options,
            ],
        ];
    }

    private function setWCOption(string $gateway_id, string $option_key, $new_value)
    {
        $options = get_option('woocommerce_' . $gateway_id . '_settings');

        // If the options are not an array, initialize it
        if (!is_array($options)) {
            $options = [];
        }

        // Set the new option key and value
        $options[$option_key] = $new_value;

        // Update the WooCommerce settings for the gateway
        update_option('woocommerce_' . $gateway_id . '_settings', $options);
    }

    private function getWCOption(string $gateway_id, string $option_key)
    {
        $options = get_option('woocommerce_' . $gateway_id . '_settings');
        if (is_array($options) && isset($options[$option_key])) {
            return $options[$option_key];
        }
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
        $api_key = $this->get_option('api_key');
        $url = 'https://confirmo.net/api/v3/invoices/' . $invoice_id;

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log("Confirmo: Error verifying invoice status: " . $response->get_error_message());
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
        switch ($confirmo_status) {
            case 'prepared':
                $order->update_status('on-hold', __('Payment instructions created, awaiting payment.', 'confirmo-payment-gateway'));
                break;
            case 'active':
                $order->update_status('on-hold', __('Client selects crypto payment method, awaiting payment.', 'confirmo-payment-gateway'));
                break;
            case 'confirming':
                $order->update_status('on-hold', __('Payment received, awaiting confirmations', 'confirmo-payment-gateway'));
                break;
            case 'paid':
                $order->payment_complete();
                break;
            case 'expired':
                $order->update_status('failed', __('Payment expired or insufficient amount', 'confirmo-payment-gateway'));
                break;
            case 'error':
                $order->update_status('failed', __('Payment confirmation failed', 'confirmo-payment-gateway'));
                break;
            default:
                $this->addDebugLog($order->get_id(), "Received unknown status: " . $confirmo_status, 'order_status_update');
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
        $table_name = $this->wpdb->prefix . "confirmo_logs";

        $this->wpdb->insert($table_name, [
            'time' => current_time('mysql'),
            'order_id' => $order_id,
            'api_response' => $api_response,
            'hook' => $hook
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
        $url = 'https://confirmo.net/api/v3/invoices';

        $data = [
            'settlement' => ['currency' => $currency],
            'product' => ['name' => __('Custom Payment', 'confirmo-payment-gateway'), 'description' => __('Payment via Confirmo Button', 'confirmo-payment-gateway')],
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
