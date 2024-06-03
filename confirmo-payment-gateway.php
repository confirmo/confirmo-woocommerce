<?php
/*
Plugin Name: Confirmo Cryptocurrency Payment Gateway
Description: Accept most used cryptocurrency in your WooCommerce store with the Confirmo Cryptocurrency Payment Gateway as easily as with a bank card.
Version: 2.0.6
Author: Confirmo.net
Author URI: https://confirmo.net
Text Domain: confirmo-payment-gateway
Domain Path: /languages
*/

// Načítání překladů
function confirmo_load_textdomain() {
    load_plugin_textdomain('confirmo-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'confirmo_load_textdomain');

// Make sure WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function confirmo_custom_payment() {
        $currency = sanitize_text_field($_POST['currency']);
        $amount = sanitize_text_field($_POST['amount']);

        $options = get_option('woocommerce_confirmo_settings');
        $api_key = isset($options['api_key']) ? $options['api_key'] : '';

        if (empty($api_key)) {
            wp_send_json_error(__('Error: API key is missing.', 'confirmo-payment-gateway'));
        }

        $notification_url = home_url('confirmo-notification');
        $return_url = wc_get_cart_url();
        $url = 'https://confirmo.net/api/v3/invoices';

        $data = array(
            'settlement' => array('currency' => $currency),
            'product' => array('name' => __('Custom Payment', 'confirmo-payment-gateway'), 'description' => __('Payment via Confirmo Button', 'confirmo-payment-gateway')),
            'invoice' => array('currencyFrom' => $currency, 'amount' => $amount),
            'notificationUrl' => $notification_url,
            'returnUrl' => $return_url,
            'reference' => 'custom-button-payment'
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-Payment-Module' => 'WooCommerce'
            ),
            'body' => json_encode($data)
        ));

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        $order_id = 'custom-button-payment'; // Tady je potřeba použít skutečné ID objednávky, pokud je dostupné

        error_log("Order ID before logging: " . $order_id);
        error_log("API Response before logging: " . json_encode($response_data));

        if ($order_id && $response_data) {
            add_confirmo_debug_log($order_id, json_encode($response_data));
        } else {
            error_log("Missing order_id or response_data");
        }

        if (isset($response_data['url'])) {
            wp_send_json_success(array('url' => $response_data['url']));
        } else {
            wp_send_json_error(__('Error: Payment URL not received.', 'confirmo-payment-gateway'));
        }
    }

    add_action('wp_ajax_confirmo_custom_payment', 'confirmo_custom_payment');
    add_action('wp_ajax_nopriv_confirmo_custom_payment', 'confirmo_custom_payment');

    function confirmo_enqueue_scripts() {
        wp_enqueue_script('confirmo-custom-script', plugins_url('public/js/confirmo-crypto-gateway.js', __FILE__), array('jquery'), null, true);

        $image_url = plugins_url('public/img/confirmo.png', __FILE__);
        wp_localize_script('confirmo-custom-script', 'confirmoParams', array(
            'imageUrl' => $image_url
        ));

        wp_enqueue_script('confirmo-button-click-handler', plugins_url('public/js/confirmo-button-click-handler.js', __FILE__), array('jquery'), null, true);

        wp_localize_script('confirmo-button-click-handler', 'confirmoButtonParams', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
        ));
    }
    add_action('wp_enqueue_scripts', 'confirmo_enqueue_scripts');

    function woocommerce_confirmo_init() {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        class WC_Confirmo_Gateway extends WC_Payment_Gateway {
            function __construct() {
                $this->id = "confirmo";
                $this->method_title = __("Confirmo", 'confirmo-payment-gateway');
                $this->method_description = __("Crypto payments made easy with industry leaders.", 'confirmo-payment-gateway');
                $this->enabled = "yes";
                $this->title = __("Crypto Payment", 'confirmo-payment-gateway');
                $this->description = __("Pay with Bitcoin, Lightning, Stablecoins and other Crypto via Confirmo Cryptocurrency Payment Gateway.", 'confirmo-payment-gateway');
                $this->supports = array();
                $this->init_form_fields();
                $this->init_settings();
                $this->api_key = $this->get_option('api_key');
                $this->settlement_currency = $this->get_option('settlement_currency');
                $this->callback_password = $this->get_option('callback_password');

                if (is_admin()) {
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                }
            }

            public function init_form_fields() {
                $settlement_currency_options = array(
                    'BTC' => 'BTC',
                    'CZK' => 'CZK',
                    'EUR' => 'EUR',
                    'GBP' => 'GBP',
                    'HUF' => 'HUF',
                    'PLN' => 'PLN',
                    'USD' => 'USD',
                    '' => __('Keep it in kind (no conversion)', 'confirmo-payment-gateway'),
                );

                $this->form_fields = array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'confirmo-payment-gateway'),
                        'type' => 'checkbox',
                        'label' => __('Enable Confirmo Payment', 'confirmo-payment-gateway'),
                        'default' => 'no'
                    ),
                    'api_key' => array(
                        'title' => __('API Key', 'confirmo-payment-gateway'),
                        'type' => 'text',
                        'description' => __('Enter your Confirmo API Key here', 'confirmo-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                        'custom_attributes' => array(
                            'required' => 'required'
                        )
                    ),
                    'callback_password' => array(
                        'title' => __('Callback Password', 'confirmo-payment-gateway'),
                        'type' => 'text',
                        'description' => __('Enter your Confirmo Callback Password', 'confirmo-payment-gateway'),
                        'default' => '',
                        'desc_tip' => true,
                        'custom_attributes' => array(
                            'required' => 'required'
                        )
                    ),
                    'settlement_currency' => array(
                        'title' => __('Settlement Currency', 'confirmo-payment-gateway'),
                        'type' => 'select',
                        'desc_tip' => true,
                        'description' => __('Settlement currency refers to the currency in which a crypto payment is finalized or settled.', 'confirmo-payment-gateway'),
                        'options' => $settlement_currency_options,
                    ),
                );
            }

            private function validate_and_sanitize_settlement_currency($settlement_currency) {
                $allowed_currencies = array(
                    'BTC',
                    'CZK',
                    'EUR',
                    'GBP',
                    'HUF',
                    'PLN',
                    'USD',
                    ''
                );

                if (!in_array($settlement_currency, $allowed_currencies, true)) {
                    throw new Exception(__("Invalid settlement currency selected.", 'confirmo-payment-gateway'));
                }

                return $settlement_currency;
            }

            private function validate_and_sanitize_api_key($api_key) {
                if (strlen($api_key) != 64) {
                    throw new Exception(__("API Key must be 64 characters long.", 'confirmo-payment-gateway'));
                }

                if (!ctype_alnum($api_key)) {
                    throw new Exception(__("API Key must only contain alphanumeric characters.", 'confirmo-payment-gateway'));
                }

                return sanitize_text_field($api_key);
            }

            private function validate_and_sanitize_callback_password($callback_password) {
                if (strlen($callback_password) != 16) {
                    throw new Exception(__("Callback Password must be 16 characters long.", 'confirmo-payment-gateway'));
                }

                if (!ctype_alnum($callback_password)) {
                    throw new Exception(__("Callback Password must only contain alphanumeric characters.", 'confirmo-payment-gateway'));
                }

                return sanitize_text_field($callback_password);
            }

            public function process_admin_options() {
                try {
                    $api_key = $this->validate_and_sanitize_api_key($_POST['woocommerce_confirmo_api_key']);
                    $_POST['woocommerce_confirmo_api_key'] = $api_key;

                    $callback_password = $this->validate_and_sanitize_callback_password($_POST['woocommerce_confirmo_callback_password']);
                    $_POST['woocommerce_confirmo_callback_password'] = $callback_password;

                    $settlement_currency = $this->validate_and_sanitize_settlement_currency($_POST['woocommerce_confirmo_settlement_currency']);
                    $_POST['woocommerce_confirmo_settlement_currency'] = $settlement_currency;

                    return parent::process_admin_options();
                } catch (Exception $e) {
                    if (false === get_transient('confirmo_error_message')) {
                        WC_Admin_Settings::add_error($e->getMessage());
                        set_transient('confirmo_error_message', true, 10);
                    }

                    return false;
                }
            }

            public function process_payment($order_id) {
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
                $notify_url = home_url('confirmo-notification');
                $return_url = $order->get_checkout_order_received_url();
                $settlement_currency = $this->get_option('settlement_currency');

                $headers = array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                    'X-Payment-Module' => 'WooCommerce'
                );

                $body = array(
                    'settlement' => array('currency' => $settlement_currency),
                    'product' => array('name' => $product_name, 'description' => $product_description),
                    'invoice' => array('currencyFrom' => $order_currency, 'amount' => $total_amount),
                    'notificationUrl' => $notify_url,
                    'notifyUrl' => $notify_url,
                    'returnUrl' => $return_url,
                    'reference' => strval($order_id),
                );

                $response = wp_remote_post($url, array(
                    'headers' => $headers,
                    'body' => json_encode($body),
                    'method' => 'POST',
                    'data_format' => 'body'
                ));

                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    wc_add_notice(__('Payment error: ', 'confirmo-payment-gateway') . $error_message, 'error');
                    return;
                }

                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);

                error_log("Order ID before logging: " . $order_id);
                error_log("API Response before logging: " . json_encode($response_data));

                if ($order_id && $response_data) {
                    add_confirmo_debug_log($order_id, json_encode($response_data));
                } else {
                    error_log("Missing order_id or response_data");
                }

                if (!isset($response_data['url'])) {
                    wc_add_notice(__('Payment error: The Confirmo API response did not contain a url.', 'confirmo-payment-gateway'), 'error');
                    return;
                }

                $confirmo_redirect_url = $response_data['url'];
                update_post_meta($order_id, '_confirmo_redirect_url', $confirmo_redirect_url);
                $order->update_status('on-hold', __('Awaiting Confirmo payment.', 'confirmo-payment-gateway'));
                wc_reduce_stock_levels($order_id);
                $woocommerce->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $confirmo_redirect_url
                );
            }

            public function add_confirmo_actions() {
                add_action('init', array($this, 'add_confirmo_endpoint'));
                add_filter('query_vars', array($this, 'add_confirmo_query_var'));
                add_action('template_redirect', array($this, 'handle_confirmo_notification'));
            }

            public function add_confirmo_endpoint() {
                add_rewrite_rule('^confirmo-notification/?', 'index.php?confirmo-notification=1', 'top');
            }

            public function add_confirmo_query_var($query_vars) {
                $query_vars[] = 'confirmo-notification';
                return $query_vars;
            }

            public function handle_confirmo_notification() {
                global $wp_query;

                if (isset($wp_query->query_vars['confirmo-notification'])) {
                    $json = file_get_contents('php://input');

                    if (!empty($this->callback_password)) {
                        $signature = hash('sha256', $json . $this->callback_password);
                        if ($_SERVER['HTTP_BP_SIGNATURE'] !== $signature) {
                            error_log("Signature validation failed!");
                        }
                    } else {
                        error_log("No callback password set, proceeding without validation.");
                    }

                    $data = json_decode($json, true);
                    $order_id = $data['reference'];
                    $order = wc_get_order($order_id);

                    if (!$order) {
                        error_log("Failed to retrieve order with reference: " . $order_id);
                        exit;
                    }

                    switch ($data['status']) {
                        case 'active':
                            $order->update_status('on-hold', __('A new invoice with payment instructions was created.', 'confirmo-payment-gateway'));
                            break;
                        case 'confirming':
                            $order->update_status('on-hold', __('The payment was received, the amount is correct or higher.', 'confirmo-payment-gateway'));
                            break;
                        case 'paid':
                            $order->update_status('processing', __('The required amount has been confirmed.', 'confirmo-payment-gateway'));
                            break;
                        case 'expired':
                            $order->update_status('failed', __('A payment has not been announced to the network within its active period, or the amount sent is lower than was requested.', 'confirmo-payment-gateway'));
                            break;
                        case 'error':
                            $order->update_status('failed', __('Confirming failed.', 'confirmo-payment-gateway'));
                            break;
                    }

                    exit;
                }
            }
        }

        function add_confirmo_gateway_class($gateways) {
            $gateways[] = 'WC_Confirmo_Gateway';
            return $gateways;
        }
        add_filter('woocommerce_payment_gateways', 'add_confirmo_gateway_class');

        function add_confirmo_url_to_emails($order, $sent_to_admin, $plain_text, $email) {
            if ($email->id == 'new_order' || $email->id == 'customer_on_hold_order') {
                $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);
                if ($confirmo_redirect_url) {
                    echo $plain_text ? __('Confirmo Payment URL:', 'confirmo-payment-gateway') . " $confirmo_redirect_url\n" : "<p><strong>" . __('Confirmo Payment URL:', 'confirmo-payment-gateway') . "</strong> <a href='$confirmo_redirect_url'>$confirmo_redirect_url</a></p>";
                }
            }
        }
        add_action('woocommerce_email_after_order_table', 'add_confirmo_url_to_emails', 10, 4);

        function add_confirmo_url_to_edit_order($order) {
            $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);

            if ($confirmo_redirect_url) {
                echo '<p><strong>' . __('Confirmo Payment URL:', 'confirmo-payment-gateway') . '</strong> <a href="' . $confirmo_redirect_url . '" target="_blank">' . $confirmo_redirect_url . '</a></p>';
            }
        }
        add_action('woocommerce_admin_order_data_after_billing_address', 'add_confirmo_url_to_edit_order', 10, 1);

        function custom_order_status_thank_you_text($original_text, $order) {
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
        add_filter('woocommerce_thankyou_order_received_text', 'custom_order_status_thank_you_text', 10, 2);

        function confirmo_payment_menu() {
            add_menu_page(
                __('Confirmo Payment', 'confirmo-payment-gateway'),
                __('Confirmo Payment', 'confirmo-payment-gateway'),
                'manage_options',
                'confirmo-payment',
                'confirmo_main_page_content',
                'dashicons-admin-generic',
                100
            );

            add_submenu_page(
                'confirmo-payment',
                __('Payment Button Generator', 'confirmo-payment-gateway'),
                __('Payment Button Generator', 'confirmo-payment-gateway'),
                'manage_options',
                'confirmo-payment-generator',
                'confirmo_payment_generator_page_content'
            );

            add_submenu_page(
                'confirmo-payment',
                __('Logs', 'confirmo-payment-gateway'),
                __('Logs', 'confirmo-payment-gateway'),
                'manage_options',
                'confirmo-logs',
                'confirmo_debug_page_content'
            );

            add_submenu_page(
                'confirmo-payment',
                __('Confirmo Help', 'confirmo-payment-gateway'),
                __('Confirmo Help', 'confirmo-payment-gateway'),
                'manage_options',
                'confirmo-help',
                'confirmo_help_page_content'
            );
        }
        add_action('admin_menu', 'confirmo_payment_menu');

        function confirmo_main_page_content() {
            echo '<div class="wrap">';
            echo '<h1>' . __('Confirmo Cryptocurrency Payment Gateway', 'confirmo-payment-gateway') . '</h1>';
            echo '<p>' . __('Crypto payments made easy with industry leaders. Confirmo.net', 'confirmo-payment-gateway') . '</p>';

            echo '<h2>' . __('Enable the future of payments today', 'confirmo-payment-gateway') . '</h2>';
            echo '<p>' . __('Start accepting cryptocurrency payments with Confirmo, one of the fastest growing companies in crypto payments! We provide a payment gateway used by Forex brokers, prop trading companies, e-commerce merchants, and luxury businesses worldwide. Our clients include FTMO, My Forex Funds, Alza and many more. All rely on our easily integrated solutions, low fees, and top-class customer support.', 'confirmo-payment-gateway') . '</p>';

            echo '<h2>' . __('Installing the plugin', 'confirmo-payment-gateway') . '</h2>';
            echo '<h3>' . __('WordPress plugins:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Add New, and search for \'Confirmo Cryptocurrency Payment Gateway\'.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('Click Download, and then activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h3>' . __('Upload:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('Download and extract the .zip file.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Add New – Upload Plugin, and upload the extracted folder. Activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h3>' . __('FTP or File Manager:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('Download and extract the .zip file.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('Copy the extracted contents into your WordPress installation under wp-content/plugins.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Installed plugins – Confirmo Cryptocurrency Payment Gateway. Activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h2>' . __('Connecting the plugin to your Confirmo account:', 'confirmo-payment-gateway') . '</h2>';
            echo '<p>' . __('Create an account at <a href="https://confirmo.net">Confirmo.net</a> and then go to Settings – API Keys – Create API key. You will be required to complete an e-mail verification, after which you will receive the API key. Once you have it, go to WooCommerce – Settings – Payments, and enable Confirmo as a payment method. Paste the API key into the respective field.', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . __('To generate a callback password, return to the Confirmo dashboard and go to Settings – Callback password. You will be prompted to complete a second e-mail verification and then provided with the callback password. Again, paste it into the respective field in WooCommerce – Settings – Payments. Callback passwords help increase the security of the API integration. Never share your API key or callback password with anyone!', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . __('Finally, choose your desired Settlement currency. Make sure to save your changes by clicking the button at the bottom. When the plugin is activated, Confirmo will appear as a payment option in your website\'s WooCommerce checkout. <b>Congratulations, you can now start receiving cryptocurrency payments!</b>', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . __('Read more at <a href="https://confirmo.net">Confirmo.net</a>. Should you encounter any difficulties, <a href="mailto:support@confirmo.net">contact us</a> at support@confirmo.net', 'confirmo-payment-gateway') . '</p>';
            echo '</div>';
        }

        function get_confirmo_currency_options() {
            $gateway = new WC_Confirmo_Gateway();
            return $gateway->get_option('settlement_currency');
        }

        function confirmo_payment_generator_page_content() {
            $currency_options = array(
                'BTC' => 'BTC',
                'CZK' => 'CZK',
                'EUR' => 'EUR',
                'GBP' => 'GBP',
                'HUF' => 'HUF',
                'PLN' => 'PLN',
                'USD' => 'USD',
                '' => __('Keep it in kind (no conversion)', 'confirmo-payment-gateway'),
            );

            $current_currency = get_confirmo_currency_options();
            ?>
            <div class="wrap">
                <h1><?php _e('Payment Button Generator', 'confirmo-payment-gateway'); ?></h1>
                <form method="post" action="">
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php _e('Currency', 'confirmo-payment-gateway'); ?></th>
                            <td>
                                <select name="confirmo_currency" required>
                                    <?php foreach ($currency_options as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($current_currency, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Amount', 'confirmo-payment-gateway'); ?></th>
                            <td>
                                <input type="text" name="confirmo_amount" value="0" required />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Button Color', 'confirmo-payment-gateway'); ?></th>
                            <td>
                                <input type="color" name="confirmo_button_color" value="#000000" required />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Text Color', 'confirmo-payment-gateway'); ?></th>
                            <td>
                                <input type="color" name="confirmo_text_color" value="#FFFFFF" required />
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php _e('Border Radius (px)', 'confirmo-payment-gateway'); ?></th>
                            <td>
                                <input type="number" name="confirmo_border_radius" value="0" required />
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button-primary"><?php _e('Generate Shortcode', 'confirmo-payment-gateway'); ?></button>
                    </p>
                </form>
                <?php
                if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                    $currency = sanitize_text_field($_POST['confirmo_currency']);
                    $amount = sanitize_text_field($_POST['confirmo_amount']);
                    $button_color = sanitize_hex_color($_POST['confirmo_button_color']);
                    $text_color = sanitize_hex_color($_POST['confirmo_text_color']);
                    $border_radius = intval($_POST['confirmo_border_radius']);

                    echo "<h2>" . __('Generated Shortcode:', 'confirmo-payment-gateway') . "</h2>";
                    echo "<code>[confirmo currency=\"$currency\" amount=\"$amount\" button_color=\"$button_color\" text_color=\"$text_color\" border_radius=\"$border_radius\"]</code>";
                }
                ?>
            </div>
            <?php
        }

        function confirmo_debug_page_content() {
            $debug_logs = get_option('confirmo_debug_logs', array());
            $recent_logs = array_filter($debug_logs, function($log) {
                return strtotime($log['time']) >= strtotime('-1 day');
            });

            error_log("Number of recent logs: " . count($recent_logs)); // Debug log to verify log count

            echo '<div class="wrap">';
            echo '<h1>' . __('Confirmo Debug Information', 'confirmo-payment-gateway') . '</h1>';
            echo '<p>' . __('If you encounter any issues, please download these debug logs and send them to plugin support.', 'confirmo-payment-gateway') . '</p>';

            if (!empty($recent_logs)) {
                echo '<table class="widefat fixed" cellspacing="0">';
                echo '<thead><tr><th>' . __('Time', 'confirmo-payment-gateway') . '</th><th>' . __('Order ID', 'confirmo-payment-gateway') . '</th><th>' . __('API Response', 'confirmo-payment-gateway') . '</th></thead>';
                echo '<tbody>';
                foreach ($recent_logs as $log) {
                    echo '<tr>';
                    echo '<td>' . esc_html($log['time']) . '</td>';
                    echo '<td>' . (isset($log['order_id']) ? esc_html($log['order_id']) : 'N/A') . '</td>';
                    echo '<td>' . (isset($log['api_response']) ? esc_html($log['api_response']) : 'N/A') . '</td>';
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
                echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
                echo '<input type="hidden" name="confirmo_download_logs" value="1">';
                echo '<p><button type="submit" class="button button-primary">' . __('Download Debug Logs', 'confirmo-payment-gateway') . '</button></p>';
                echo '</form>';
                echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
                echo '<input type="hidden" name="action" value="confirmo_delete_logs">';
                echo '<p><button type="submit" class="button button-secondary">' . __('Delete all logs', 'confirmo-payment-gateway') . '</button></p>';
                echo '</form>';
            } else {
                echo '<p>' . __('No debug logs available for the last day.', 'confirmo-payment-gateway') . '</p>';
            }
        }

        function confirmo_delete_logs() {
            if (isset($_POST['confirmo_delete_logs'])) {
                delete_option('confirmo_debug_logs');
                wp_redirect(admin_url('admin.php?page=confirmo-logs'));
                exit;
            }
        }
        add_action('admin_post_confirmo_delete_logs', 'confirmo_delete_logs');

        function confirmo_download_logs() {
            if (isset($_POST['confirmo_download_logs'])) {
                $debug_logs = get_option('confirmo_debug_logs', array());
                $recent_logs = array_filter($debug_logs, function($log) {
                    return strtotime($log['time']) >= strtotime('-1 day');
                });

                header('Content-Type: text/csv');
                header('Content-Disposition: attachment;filename=confirmo_debug_logs.csv');

                $output = fopen('php://output', 'w');
                fputcsv($output, array(__('Time', 'confirmo-payment-gateway'), __('Order ID', 'confirmo-payment-gateway'), __('API Response', 'confirmo-payment-gateway')));

                foreach ($recent_logs as $log) {
                    fputcsv($output, array($log['time'], $log['order_id'], $log['api_response']));
                }
                fclose($output);
                exit;
            }
        }

        add_action('admin_init', 'confirmo_download_logs');

        function add_confirmo_debug_log($order_id, $api_response) {
            $debug_logs = get_option('confirmo_debug_logs', array());

            error_log("Debug Log - Order ID: " . $order_id);
            error_log("Debug Log - API Response: " . $api_response);

            if (!empty($order_id) && !empty($api_response)) {
                $debug_logs[] = array(
                    'time' => current_time('mysql'),
                    'order_id' => $order_id,
                    'api_response' => $api_response
                );
                update_option('confirmo_debug_logs', $debug_logs);
            } else {
                error_log("Missing order_id or api_response in add_confirmo_debug_log");
            }
        }

        function confirmo_help_page_content() {
            echo '<div class="wrap">';
            echo '<h1>' . __('Confirmo Cryptocurrency Payment Gateway Help', 'confirmo-payment-gateway') . '</h1>';
            echo '<p>' . __('Crypto payments made easy with industry leaders. Confirmo.net', 'confirmo-payment-gateway') . '</p>';

            echo '<h2>' . __('Enable the future of payments today', 'confirmo-payment-gateway') . '</h2>';
            echo '<p>' . __('Start accepting cryptocurrency payments with Confirmo, one of the fastest growing companies in crypto payments! We provide a payment gateway used by Forex brokers, prop trading companies, e-commerce merchants, and luxury businesses worldwide. Our clients include FTMO, My Forex Funds, Alza and many more. All rely on our easily integrated solutions, low fees, and top-class customer support.', 'confirmo-payment-gateway') . '</p>';

            echo '<h2>' . __('Installing the plugin', 'confirmo-payment-gateway') . '</h2>';
            echo '<h3>' . __('WordPress plugins:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Add New, and search for \'Confirmo Cryptocurrency Payment Gateway\'.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('Click Download, and then activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h3>' . __('Upload:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('Download and extract the .zip file.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Add New – Upload Plugin, and upload the extracted folder. Activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h3>' . __('FTP or File Manager:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('Download and extract the .zip file.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('Copy the extracted contents into your WordPress installation under wp-content/plugins.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Installed plugins – Confirmo Cryptocurrency Payment Gateway. Activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h2>' . __('Connecting the plugin to your Confirmo account:', 'confirmo-payment-gateway') . '</h2>';
            echo '<p>' . __('Create an account at <a href="https://confirmo.net">Confirmo.net</a> and then go to Settings – API Keys – Create API key. You will be required to complete an e-mail verification, after which you will receive the API key. Once you have it, go to WooCommerce – Settings – Payments, and enable Confirmo as a payment method. Paste the API key into the respective field.', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . __('To generate a callback password, return to the Confirmo dashboard and go to Settings – Callback password. You will be prompted to complete a second e-mail verification and then provided with the callback password. Again, paste it into the respective field in WooCommerce – Settings – Payments. Callback passwords help increase the security of the API integration. Never share your API key or callback password with anyone!', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . __('Finally, choose your desired Settlement currency. Make sure to save your changes by clicking the button at the bottom. When the plugin is activated, Confirmo will appear as a payment option in your website\'s WooCommerce checkout. <b>Congratulations, you can now start receiving cryptocurrency payments!</b>', 'confirmo-payment-gateway') . '</p>';

            echo '<h2>' . __('Generating a payment button using shortcode', 'confirmo-payment-gateway') . '</h2>';
            echo '<p>' . __('To generate a payment button, use the following shortcode:', 'confirmo-payment-gateway') . '</p>';
            echo '<code>[confirmo currency="BTC" amount="100" button_color="#000000" text_color="#FFFFFF" border_radius="5"]</code>';
            echo '<p>' . __('In this example:', 'confirmo-payment-gateway') . '</p>';
            echo '<ul>';
            echo '<li><b>' . __('currency', 'confirmo-payment-gateway') . '</b>: ' . __('The currency you want to accept (e.g., BTC)', 'confirmo-payment-gateway') . '</li>';
            echo '<li><b>' . __('amount', 'confirmo-payment-gateway') . '</b>: ' . __('The amount to be paid', 'confirmo-payment-gateway') . '</li>';
            echo '<li><b>' . __('button_color', 'confirmo-payment-gateway') . '</b>: ' . __('The button color (in hex code format)', 'confirmo-payment-gateway') . '</li>';
            echo '<li><b>' . __('text_color', 'confirmo-payment-gateway') . '</b>: ' . __('The button text color (in hex code format)', 'confirmo-payment-gateway') . '</li>';
            echo '<li><b>' . __('border_radius', 'confirmo-payment-gateway') . '</b>: ' . __('The button border radius (in pixels)', 'confirmo-payment-gateway') . '</li>';
            echo '</ul>';

            echo '<p>' . __('Read more at <a href="https://confirmo.net">Confirmo.net</a>. Should you encounter any difficulties, <a href="mailto:support@confirmo.net">contact us</a> at support@confirmo.net', 'confirmo-payment-gateway') . '</p>';
            echo '</div>';
        }

        $gateway = new WC_Confirmo_Gateway();
        $gateway->add_confirmo_actions();
    }
    add_action('plugins_loaded', 'woocommerce_confirmo_init', 0);
}

function confirmo_payment_shortcode($atts) {
    $atts = shortcode_atts(array(
        'currency' => 'BTC',
        'amount' => '0',
        'button_color' => '#000000',
        'text_color' => '#FFFFFF',
        'border_radius' => '0'
    ), $atts, 'confirmo');

    $currency = sanitize_text_field($atts['currency']);
    $amount = sanitize_text_field($atts['amount']);
    $button_color = sanitize_hex_color($atts['button_color']);
    $text_color = sanitize_hex_color($atts['text_color']);
    $border_radius = intval($atts['border_radius']);

    $style = "background-color: $button_color; color: $text_color; border-radius: {$border_radius}px; padding: 8px 16px ";

    return "<button class='confirmoButton' style='$style' data-currency='$currency' data-amount='$amount'>" . sprintf(__('Pay %s %s', 'confirmo-payment-gateway'), $amount, $currency) . "</button>";
}
add_shortcode('confirmo', 'confirmo_payment_shortcode');

function confirmo_custom_payment_endpoint() {
    add_rewrite_rule('^confirmo-custom-payment/?', 'index.php?confirmo-custom-payment=1', 'top');
}
add_action('init', 'confirmo_custom_payment_endpoint');

function confirmo_custom_payment_query_vars($query_vars) {
    $query_vars[] = 'confirmo-custom-payment';
    return $query_vars;
}
add_filter('query_vars', 'confirmo_custom_payment_query_vars');

function confirmo_custom_payment_template_redirect() {
    global $wp_query;

    if (isset($wp_query->query_vars['confirmo-custom-payment'])) {
        $currency = sanitize_text_field($_POST['currency']);
        $amount = sanitize_text_field($_POST['amount']);
        $api_key = get_option('woocommerce_confirmo_api_key');

        $notification_url = home_url('confirmo-notification');
        $return_url = wc_get_cart_url();

        $url = 'https://confirmo.net/api/v3/invoices';

        $data = array(
            'settlement' => array('currency' => $currency),
            'product' => array('name' => __('Custom Payment', 'confirmo-payment-gateway'), 'description' => __('Payment via Confirmo Button', 'confirmo-payment-gateway')),
            'invoice' => array('currencyFrom' => $currency, 'amount' => $amount),
            'notificationUrl' => $notification_url,
            'returnUrl' => $return_url,
            'reference' => 'custom-button-payment'
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-Payment-Module' => 'WooCommerce'
            ),
            'body' => json_encode($data)
        ));

        if (is_wp_error($response)) {
            wp_die(__('Error:', 'confirmo-payment-gateway') . ' ' . $response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (isset($response_data['url'])) {
            wp_redirect($response_data['url']);
            exit;
        } else {
            wp_die(__('Error: Payment URL not received.', 'confirmo-payment-gateway'));
        }
    }
}

add_action('template_redirect', 'confirmo_custom_payment_template_redirect');

?>
