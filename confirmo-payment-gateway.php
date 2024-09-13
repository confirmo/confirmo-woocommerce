<?php
/*
Plugin Name: Confirmo Cryptocurrency Payment Gateway
Description: Accept most used cryptocurrency in your WooCommerce store with the Confirmo Cryptocurrency Payment Gateway as easily as with a bank card.
Version: 2.4.2
Author: Confirmo.net
Author URI: https://confirmo.net
Text Domain: confirmo-payment-gateway
Domain Path: /languages
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// Compatibility with WooCommerce Blocks
function declare_cart_checkout_blocks_compatibility()
{
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}

// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action('woocommerce_blocks_loaded', 'confirmo_register_block_payment_method_type');

function confirmo_register_block_payment_method_type()
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


// Translations loading
if (!defined('ABSPATH')) exit;
function confirmo_sanitize_array($array)
{
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            $array[$key] = confirmo_sanitize_array($value);
        } else {
            $array[$key] = sanitize_text_field($value);
        }
    }
    return $array;
}


function confirmo_add_settings_link($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=confirmo-payment-gate-config') . '">' . __('Settings', 'confirmo-payment-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'confirmo_add_settings_link');


function confirmo_load_textdomain()
{
    load_plugin_textdomain('confirmo-payment-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('plugins_loaded', 'confirmo_load_textdomain');
function confirmo_initialize_settings()
{
    $option_key = 'woocommerce_confirmo_settings';
    $default_settings = array(
        'enabled' => 'no',  // Default setting to disabled
        'api_key' => '',    // Default empty API key
        'callback_password' => '',  // Default empty callback password
        'settlement_currency' => '' // Default to empty or a specific currency code
    );

    $current_settings = get_option($option_key, array());
    $is_updated = false;

    // Check each setting and apply default if not already set
    foreach ($default_settings as $key => $value) {
        if (!isset($current_settings[$key])) {
            $current_settings[$key] = $value;
            $is_updated = true;
        }
    }

    // Update the option only if necessary
    if ($is_updated) {
        update_option($option_key, $current_settings);
    }
}

// Hook the initialization function to plugin activation
register_activation_hook(__FILE__, 'confirmo_initialize_settings');

// Make sure WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    function confirmo_custom_payment()
    {
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
            'body' => wp_json_encode($data)
        ));

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        $order_id = 'custom-button-payment'; // Here you need to use the actual order ID, if available

        error_log("Order ID before logging: " . $order_id);
        error_log("API Response before logging: " . wp_json_encode($response_data));

        if ($order_id && $response_data) {
            confirmo_add_debug_log($order_id, wp_json_encode($response_data));
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

    function confirmo_enqueue_scripts()
    {
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

    function confirmo_woocommerce_init()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        class WC_Confirmo_Gateway extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = "confirmo";
                $this->method_title = __("Confirmo", 'confirmo-payment-gateway');
                $this->method_description = __("Settings have been moved. Please configure the gateway ", 'confirmo-payment-gateway') . "<a href='" . admin_url('admin.php?page=confirmo-payment-gate-config') . "'>" . __("here", 'confirmo-payment-gateway') . "</a>.";
                $this->api_key = $this->get_option('api_key');
                $this->settlement_currency = $this->get_option('settlement_currency');
                $this->callback_password = $this->get_option('callback_password');
                $this->title = __("Confirmo", 'confirmo-payment-gateway');
                // If needed, other initializations can be done here.
                // Adding custom admin notices
                // add_action('admin_notices', array($this, 'show_custom_admin_notice'));
            }

            public function confirmo_show_custom_admin_notice()
            {
                // Only show this notice on specific WooCommerce or payment gateway settings pages
                $screen = get_current_screen();
                if ($screen->id === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === $this->id) {
                    echo '<div class="notice notice-info is-dismissible">';
                    echo '<p>' . sprintf(
                            esc_html__("Settings have been moved. Please configure the gateway ", 'confirmo-payment-gateway') .
                            " <a href='" . esc_url(admin_url('admin.php?page=confirmo-payment-gate-config')) . "'>" .
                            esc_html__("here", 'confirmo-payment-gateway') .
                            "</a>."
                        ) . '</p>';
                    echo '</div>';

                }
            }

            public function init_form_fields()
            {
                $settlement_currency_options = array(
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

            private function confirmo_validate_and_sanitize_settlement_currency($settlement_currency)
            {
                $allowed_currencies = array(
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
                );

                if (!in_array($settlement_currency, $allowed_currencies, true)) {
                    throw new Exception(esc_html__("Invalid settlement currency selected.", 'confirmo-payment-gateway'));
                }


                return $settlement_currency;
            }

            private function confirmo_validate_and_sanitize_api_key($api_key)
            {

                if (strlen($api_key) != 64) {
                    throw new Exception(esc_html__("API Key must be 64 characters long.", 'confirmo-payment-gateway'));
                }

                if (!ctype_alnum($api_key)) {
                    throw new Exception(esc_html__("API Key must only contain alphanumeric characters.", 'confirmo-payment-gateway'));
                }

                return sanitize_text_field($api_key);
            }

            private function confirmo_validate_and_sanitize_callback_password($callback_password)
            {
                if (strlen($callback_password) != 16) {
                    throw new Exception(esc_html__("Callback Password must be 16 characters long.", 'confirmo-payment-gateway'));
                }

                if (!ctype_alnum($callback_password)) {
                    throw new Exception(esc_html__("Callback Password must only contain alphanumeric characters.", 'confirmo-payment-gateway'));
                }

                return sanitize_text_field($callback_password);
            }

            public function process_admin_options()
            {
                if (!wp_verify_nonce($_POST["confirmo-payment-gate-config"], "confirmo-config-nonce")) {
                    wp_die("Bad nonce.");
                }
                try {
                    $api_key = $this->confirmo_validate_and_sanitize_api_key($_POST['woocommerce_confirmo_api_key']);
                    $_POST['woocommerce_confirmo_api_key'] = $api_key;

                    $callback_password = $this->confirmo_validate_and_sanitize_callback_password($_POST['woocommerce_confirmo_callback_password']);
                    $_POST['woocommerce_confirmo_callback_password'] = $callback_password;

                    $settlement_currency = $this->confirmo_validate_and_sanitize_settlement_currency($_POST['woocommerce_confirmo_settlement_currency']);
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

   public function process_payment($order_id)
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

        $notify_url = home_url("index.php?confirmo-notification=1");
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
            'body' => wp_json_encode($body),
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

        if (!isset($response_data['url'])) {
            wc_add_notice(__('Payment error: The Confirmo API response did not contain a url.', 'confirmo-payment-gateway'), 'error');
            return;
        }

        $confirmo_redirect_url = $response_data['url'];
        update_post_meta($order_id, '_confirmo_redirect_url', $confirmo_redirect_url);
        
        // Change: Set initial order status to 'pending'
        $order->update_status('pending', __('Awaiting Confirmo payment.', 'confirmo-payment-gateway'));
        
        wc_reduce_stock_levels($order_id);
        $woocommerce->cart->empty_cart();

        if ($order_id && $response_data) {
            confirmo_add_debug_log($order_id, wp_json_encode($response_data), $confirmo_redirect_url);
        } else {
            error_log("Missing order_id or response_data");
        }
        return array(
            'result' => 'success',
            'redirect' => $confirmo_redirect_url
        );
    }

            public function confirmo_add_actions()
            {
                add_action('init', array($this, 'confirmo_add_endpoint'));
                add_filter('query_vars', array($this, 'confirmo_add_query_var'));
                add_action('template_redirect', array($this, 'confirmo_handle_notification'));
            }

            public function confirmo_add_endpoint()
            {
                add_rewrite_rule('^confirmo-notification/?', 'index.php?confirmo-notification=1', 'top');
            }

            public function confirmo_add_query_var($query_vars)
            {
                $query_vars[] = 'confirmo-notification';
                return $query_vars;
            }

  public function confirmo_handle_notification()
{
    global $wp_query;

    if (isset($wp_query->query_vars['confirmo-notification'])) {
        $json = file_get_contents('php://input');
        if (empty($json)) {
            wp_die('No data', '', array('response' => 400));
        }

        // Validace callback password
        if (!empty($this->callback_password)) {
            $signature = hash('sha256', $json . $this->callback_password);
            if ($_SERVER['HTTP_BP_SIGNATURE'] !== $signature) {
                error_log("Confirmo: Signature validation failed!");
                wp_die('Invalid signature', '', array('response' => 403));
            }
        } else {
            error_log("Confirmo: No callback password set, proceeding without validation.");
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            error_log("Confirmo: Invalid JSON data received.");
            wp_die('Invalid data', '', array('response' => 400));
        }

        // Sanitizace dat
        $data = confirmo_sanitize_array($data);
        $order_id = $data['reference'];
        $order = wc_get_order($order_id);

        if (!$order) {
            error_log("Confirmo: Failed to retrieve order with reference: " . $order_id);
            wp_die('Order not found', '', array('response' => 404));
        }

        // Ověření stavu faktury přes API Confirmo
        $verified_status = $this->confirmo_verify_invoice_status($data['id']);

        // Kontrola, zda jsou stavy kompatibilní
        if ($verified_status !== false && $this->are_statuses_compatible($data['status'], $verified_status)) {
            $is_lightning = isset($data['crypto']['network']) && $data['crypto']['network'] === 'LIGHTNING';
            $this->confirmo_update_order_status($order, $data['status'], $is_lightning);
            wp_die('OK', '', array('response' => 200));
        } else {
            error_log("Confirmo: Webhook status mismatch with API status for order: " . $order_id . ". Webhook: " . $data['status'] . ", API: " . $verified_status);
            wp_die('Status mismatch', '', array('response' => 409));
        }
    }
}

			
private function are_statuses_compatible($webhook_status, $api_status)
{
    $compatible_statuses = [
        ['active', 'confirming'],
        ['confirming', 'paid'],
        ['paid', 'completed'],
        ['active', 'paid'],          // Přidáno
        ['active', 'completed'],     // Přidáno
        ['confirming', 'completed']  // Přidáno
    ];

    // Pokud jsou stavy totožné, jsou kompatibilní
    if ($webhook_status === $api_status) {
        return true;
    }

    // Kontrola, zda je stav v seznamu kompatibilních párů
    foreach ($compatible_statuses as $pair) {
        if (
            ($webhook_status === $pair[0] && $api_status === $pair[1]) ||
            ($webhook_status === $pair[1] && $api_status === $pair[0])
        ) {
            return true;
        }
    }

    return false;
}


private function confirmo_verify_invoice_status($invoice_id)
{
    $api_key = $this->get_option('api_key');
    $url = 'https://confirmo.net/api/v3/invoices/' . $invoice_id;

    $response = wp_remote_get($url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        )
    ));

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




    // New function: Update order status based on Confirmo status
    private function confirmo_update_order_status($order, $confirmo_status, $is_lightning = false)
    {
        switch ($confirmo_status) {
            case 'active':
                $order->update_status('on-hold', __('Payment instructions created, awaiting payment.', 'confirmo-payment-gateway'));
                break;
            case 'confirming':
                if ($is_lightning) {
                    $order->update_status('processing', __('Lightning payment received, awaiting final confirmation', 'confirmo-payment-gateway'));
                } else {
                    $order->update_status('on-hold', __('Bitcoin payment received, awaiting confirmations', 'confirmo-payment-gateway'));
                }
                break;
            case 'paid':
                if ($is_lightning || $order->get_status() === 'processing') {
                    $order->update_status('completed', __('Payment confirmed and completed', 'confirmo-payment-gateway'));
                } else {
                    $order->update_status('processing', __('Payment confirmed, processing order', 'confirmo-payment-gateway'));
                }
                break;
            case 'expired':
                $order->update_status('failed', __('Payment expired or insufficient amount', 'confirmo-payment-gateway'));
                break;
            case 'error':
                $order->update_status('failed', __('Payment confirmation failed', 'confirmo-payment-gateway'));
                break;
        }
        
confirmo_add_debug_log($order->get_id(), "Order status updated to: " . $order->get_status() . " based on Confirmo status: " . $confirmo_status, 'order_status_update');

    }
        }

        function confirmo_add_gateway_class($gateways)
        {
            $gateways[] = 'WC_Confirmo_Gateway';
            return $gateways;
        }

        add_filter('woocommerce_payment_gateways', 'confirmo_add_gateway_class');

        function confirmo_add_url_to_emails($order, $sent_to_admin, $plain_text, $email)
        {
            if ($email->id == 'new_order' || $email->id == 'customer_on_hold_order') {
                $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);
                if ($confirmo_redirect_url) {
                    echo $plain_text ? esc_html(__('Confirmo Payment URL:', 'confirmo-payment-gateway')) . esc_url($confirmo_redirect_url) . "\n" : "<p><strong>" . esc_html(__('Confirmo Payment URL:', 'confirmo-payment-gateway')) . "</strong> <a href='" . esc_url($confirmo_redirect_url) . "'>" . esc_url($confirmo_redirect_url) . "</a></p>";

                }
            }
        }

        add_action('woocommerce_email_after_order_table', 'confirmo_add_url_to_emails', 10, 4);

        function confirmo_add_url_to_edit_order($order)
        {
            $confirmo_redirect_url = get_post_meta($order->get_id(), '_confirmo_redirect_url', true);

            if ($confirmo_redirect_url) {
                echo '<p><strong>' . esc_html(__('Confirmo Payment URL:', 'confirmo-payment-gateway')) . '</strong> <a href="' . esc_url($confirmo_redirect_url) . '" target="_blank">' . esc_url($confirmo_redirect_url) . '</a></p>';
            }
        }

        add_action('woocommerce_admin_order_data_after_billing_address', 'confirmo_add_url_to_edit_order', 10, 1);

        function confirmo_custom_order_status_thank_you_text($original_text, $order)
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

        add_filter('woocommerce_thankyou_order_received_text', 'confirmo_custom_order_status_thank_you_text', 10, 2);

        function confirmo_payment_menu()
        {
            add_menu_page(
                __('Confirmo Payment', 'confirmo-payment-gateway'),
                __('Confirmo Payment', 'confirmo-payment-gateway'),
                'manage_options',
                'confirmo-payment',
                'confirmo_main_page_content',
                'dashicons-money-alt',
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
//
//            add_submenu_page(
//                'confirmo-payment',
//                __('Confirmo Help', 'confirmo-payment-gateway'),
//                __('Confirmo Help', 'confirmo-payment-gateway'),
//                'manage_options',
//                'confirmo-help',
//                'confirmo_help_page_content'
//            );
        }

        add_action('admin_menu', 'confirmo_payment_menu');
        // Add submenu page for Payment Gate Config
        // Hook to add the submenu page
        function confirmo_add_payment_gate_config_submenu()
        {
            add_submenu_page(
                'confirmo-payment',
                __('Payment Gate Config', 'confirmo-payment-gateway'),
                __('Payment Gate Config', 'confirmo-payment-gateway'),
                'manage_options',
                'confirmo-payment-gate-config',
                'confirmo_payment_gate_config_page_content'
            );
        }

        function confirmo_payment_gate_config_page_content()
        {
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <form method="post" action="options.php">
                    <?php
                    wp_nonce_field('confirmo-payment-gate-config', 'confirmo_config_nonce');
                    settings_fields('confirmo-payment-gate-config');
                    do_settings_sections('confirmo-payment-gate-config');
                    submit_button(__('Save Settings', 'confirmo-payment-gateway'));
                    ?>
                </form>
            </div>
            <?php
        }

        function confirmo_register_payment_gate_config_settings()
        {
            register_setting('confirmo-payment-gate-config', 'confirmo_gate_config_options', 'confirmo_gate_config_options_validate');

            add_settings_section(
                'confirmo_gate_config_main',
                __('Main Settings', 'confirmo-payment-gateway'),
                'confirmo_gate_config_section_callback',
                'confirmo-payment-gate-config'
            );

            add_settings_field(
                'enabled',
                __('Enable/Disable', 'confirmo-payment-gateway'),
                'confirmo_gate_config_enabled_callback',
                'confirmo-payment-gate-config',
                'confirmo_gate_config_main'
            );

            add_settings_field(
                'api_key',
                __('API Key', 'confirmo-payment-gateway'),
                'confirmo_gate_config_api_key_callback',
                'confirmo-payment-gate-config',
                'confirmo_gate_config_main'
            );

            add_settings_field(
                'callback_password',
                __('Callback Password', 'confirmo-payment-gateway'),
                'confirmo_gate_config_callback_password_callback',
                'confirmo-payment-gate-config',
                'confirmo_gate_config_main'
            );

            add_settings_field(
                'settlement_currency',
                __('Settlement Currency', 'confirmo-payment-gateway'),
                'confirmo_gate_config_settlement_currency_callback',
                'confirmo-payment-gate-config',
                'confirmo_gate_config_main'
            );
        }

        function confirmo_set_wc_option($gateway_id, $option_key, $new_value)
        {
            $options = get_option('woocommerce_' . $gateway_id . '_settings');
            if (is_array($options) && isset($options[$option_key])) {
                $options[$option_key] = $new_value;
                update_option('woocommerce_' . $gateway_id . '_settings', $options);
            }
        }
        function confirmo_get_wc_option($gateway_id, $option_key)
        {
            $options = get_option('woocommerce_' . $gateway_id . '_settings');
            if (is_array($options) && isset($options[$option_key])) {
                return $options[$option_key];
            }
        }

        function confirmo_gate_config_options_validate($input)
        {
            $new_input = array();
            $option_key = 'woocommerce_confirmo_settings';
            $settings = get_option($option_key, array());

            if (isset($input['enabled'])) {
                $new_input['enabled'] = $input['enabled'] === 'on' ? 'yes' : 'no';
                confirmo_set_wc_option("confirmo", "enabled", $new_input['enabled']);
            }

            if (isset($input['api_key'])) {
                $api_key = sanitize_text_field($input['api_key']);
                if (strlen($api_key) == 64 && ctype_alnum($api_key)) {
                    $new_input['api_key'] = $api_key;
                    confirmo_set_wc_option("confirmo", "api_key", $new_input['api_key']);
                } else {
                    $new_input['api_key'] = isset($settings['api_key']) ? $settings['api_key'] : '';
                    add_settings_error('api_key', 'api_key_error', __('API Key must be exactly 64 alphanumeric characters', 'confirmo-payment-gateway'), 'error');
                }
            }

            if (isset($input['callback_password'])) {
                $callback_password = sanitize_text_field($input['callback_password']);
                if (strlen($callback_password) == 16 && ctype_alnum($callback_password)) {
                    $new_input['callback_password'] = $callback_password;
                    confirmo_set_wc_option("confirmo", "callback_password", $new_input['callback_password']);
                } else {
                    $new_input['callback_password'] = isset($settings['callback_password']) ? $settings['callback_password'] : '';
                    add_settings_error('callback_password', 'callback_password_error', __('Callback Password must be 16 alphanumeric characters', 'confirmo-payment-gateway'), 'error');
                }
            }

            if (isset($input['settlement_currency'])) {
                $settlement_currency = $input['settlement_currency']; //This is a number, 0-8..
                $allowed_currencies = ['BTC', 'CZK', 'EUR', 'GBP', 'HUF', 'PLN', 'USD', 'USDC', 'USDT',''];
                if ($allowed_currencies[$settlement_currency]) {
                    confirmo_set_wc_option("confirmo", "settlement_currency", $allowed_currencies[$settlement_currency]);
                    $new_input['settlement_currency'] = $allowed_currencies[$settlement_currency];
                } else {
                    $new_input['settlement_currency'] = $settings['settlement_currency'] ?? '';
                    add_settings_error('settlement_currency', 'settlement_currency_error', __('Invalid settlement currency selected.', 'confirmo-payment-gateway'), 'error');
                }
            }

            return $new_input;
        }

        function confirmo_gate_config_section_callback()
        {
            echo '<p>' . esc_html__('Adjust the settings for Confirmo payment gateway.', 'confirmo-payment-gateway') . '</p>';
        }

        function confirmo_gate_config_enabled_callback()
        {
            $options = get_option('confirmo_gate_config_options');
            $checked = isset($options['enabled']) && $options['enabled'] ? 'checked' : '';
            echo '<input type="checkbox" id="enabled" name="confirmo_gate_config_options[enabled]" ' . esc_attr($checked) . '>';
        }

        function confirmo_gate_config_api_key_callback()
        {
            $options = get_option('confirmo_gate_config_options');
            $value = isset($options['api_key']) ? esc_attr($options['api_key']) : '';
            echo('<input type="text" id="api_key" name="confirmo_gate_config_options[api_key]" value="' . esc_attr($value) . '" size="70" maxlength="64" required>');
        }

        function confirmo_gate_config_callback_password_callback()
        {
            $options = get_option('confirmo_gate_config_options');
            $value = isset($options['callback_password']) ? esc_attr($options['callback_password']) : '';
            echo('<input type="text" id="callback_password" name="confirmo_gate_config_options[callback_password]" value="' . esc_attr($value) . '" required>');
        }

        function confirmo_gate_config_settlement_currency_callback()
        {
            $options = get_option('confirmo_gate_config_options');
            $current_value = $options['settlement_currency'] ?? 'test';
            $settlement_currency_options = ['BTC', 'CZK', 'EUR', 'GBP', 'HUF', 'PLN', 'USD', 'USDC', 'USDT', ''];
            echo '<select id="settlement_currency" name="confirmo_gate_config_options[settlement_currency]">';
            foreach ($settlement_currency_options as $key => $label) {
                $selected = ($settlement_currency_options[$key] == $current_value) ? 'selected' : '';
                echo('<option value="' . esc_attr($key) . '" ' . esc_attr($selected) . '>' . esc_html($label) . '</option>');
            }
            echo '</select>';
        }

        add_action('admin_menu', 'confirmo_add_payment_gate_config_submenu');
        add_action('admin_init', 'confirmo_register_payment_gate_config_settings');

// Hook to register the settings
        function confirmo_main_page_content()
        {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html(__('Confirmo Cryptocurrency Payment Gateway', 'confirmo-payment-gateway')) . '</h1>';
            echo '<p>' . esc_html(__('Crypto payments made easy with industry leaders. Confirmo.net', 'confirmo-payment-gateway')) . '</p>';

            echo '<h2>' . esc_html(__('Enable the future of payments today', 'confirmo-payment-gateway')) . '</h2>';
            echo '<p>' . esc_html(__('Start accepting cryptocurrency payments with Confirmo, one of the fastest growing companies in crypto payments! We provide a payment gateway used by Forex brokers, prop trading companies, e-commerce merchants, and luxury businesses worldwide. Our clients include FTMO, My Forex Funds, Alza and many more. All rely on our easily integrated solutions, low fees, and top-class customer support.', 'confirmo-payment-gateway')) . '</p>';

            echo '<h2>' . __('Installing the plugin', 'confirmo-payment-gateway') . '</h2>';
            echo '<h3>' . __('WordPress plugins:', 'confirmo-payment-gateway') . '</h3>';
            echo '<ol>';
            echo '<li>' . __('In your WordPress dashboard, go to Plugins – Add New, and search for \'Confirmo Cryptocurrency Payment Gateway\'.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('Click Download, and then activate the plugin.', 'confirmo-payment-gateway') . '</li>';
            echo '<li>' . __('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway') . '</li>';
            echo '</ol>';

            echo '<h3>' . esc_html(__('Upload:', 'confirmo-payment-gateway')) . '</h3>';
            echo '<ol>';
            echo '<li>' . esc_html(__('Download and extract the .zip file.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to Plugins – Add New – Upload Plugin, and upload the extracted folder. Activate the plugin.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway')) . '</li>';
            echo '</ol>';

            echo '<h3>' . esc_html(__('FTP or File Manager:', 'confirmo-payment-gateway')) . '</h3>';
            echo '<ol>';
            echo '<li>' . esc_html(__('Download and extract the .zip file.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('Copy the extracted contents into your WordPress installation under wp-content/plugins.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to Plugins – Installed plugins – Confirmo Cryptocurrency Payment Gateway. Activate the plugin.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway')) . '</li>';
            echo '</ol>';

            echo '<h2>' . esc_html(__('Connecting the plugin to your Confirmo account:', 'confirmo-payment-gateway')) . '</h2>';
            echo '<p>' . __('Create an account at <a href="https://confirmo.net">Confirmo.net</a> and then go to Settings – API Keys – Create API key. You will be required to complete an e-mail verification, after which you will receive the API key. Once you have it, go to WooCommerce – Settings – Payments, and enable Confirmo as a payment method. Paste the API key into the respective field.', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . esc_html(__('To generate a callback password, return to the Confirmo dashboard and go to Settings – Callback password. You will be prompted to complete a second e-mail verification and then provided with the callback password. Again, paste it into the respective field in WooCommerce – Settings – Payments. Callback passwords help increase the security of the API integration. Never share your API key or callback password with anyone!', 'confirmo-payment-gateway')) . '</p>';

            echo '<p>' . __('Finally, choose your desired Settlement currency. Make sure to save your changes by clicking the button at the bottom. When the plugin is activated, Confirmo will appear as a payment option in your website\'s WooCommerce checkout. <b>Congratulations, you can now start receiving cryptocurrency payments!</b>', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . __('Read more at <a href="https://confirmo.net">Confirmo.net</a>. Should you encounter any difficulties, <a href="mailto:support@confirmo.net">contact us</a> at support@confirmo.net', 'confirmo-payment-gateway') . '</p>';
            echo '</div>';
        }

        function confirmo_get_currency_options()
        {
            return confirmo_get_wc_option("confirmo","settlement_currency");
        }

        function confirmo_payment_generator_page_content()
        {
            $currency_options = array(
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
            );

            $current_currency = confirmo_get_currency_options();
            ?>
            <div class="wrap">
                <h1><?php echo esc_html(__('Payment Button Generator', 'confirmo-payment-gateway')); ?></h1>
                <form method="post" action="">
                    <?php wp_nonce_field('confirmo_set_style', 'confirmo_set_style_nonce'); ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Currency', 'confirmo-payment-gateway')); ?></th>
                            <td>
                                <select name="confirmo_currency" required>
                                    <?php foreach ($currency_options as $value => $label): ?>
                                        <option value="<?php echo esc_attr($value); ?>" <?php selected($current_currency, $value); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Amount', 'confirmo-payment-gateway')); ?></th>
                            <td>
                                <input type="text" name="confirmo_amount" value="0" required/>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Button Color', 'confirmo-payment-gateway')); ?></th>
                            <td>
                                <input type="color" name="confirmo_button_color" value="#000000" required/>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Text Color', 'confirmo-payment-gateway')); ?></th>
                            <td>
                                <input type="color" name="confirmo_text_color" value="#FFFFFF" required/>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row"><?php echo esc_html(__('Border Radius (px)', 'confirmo-payment-gateway')); ?></th>
                            <td>
                                <input type="number" name="confirmo_border_radius" value="0" required/>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit"
                                class="button-primary"><?php echo esc_html(__('Generate Shortcode', 'confirmo-payment-gateway')); ?></button>
                    </p>
                </form>
                <?php
                if ($_SERVER['REQUEST_METHOD'] == 'POST' && wp_verify_nonce($_POST["confirmo_set_style_nonce"], "confirmo_set_style")) {
                    $currency = sanitize_text_field($_POST['confirmo_currency']);
                    $amount = sanitize_text_field($_POST['confirmo_amount']);
                    $button_color = sanitize_hex_color($_POST['confirmo_button_color']);
                    $text_color = sanitize_hex_color($_POST['confirmo_text_color']);
                    $border_radius = intval($_POST['confirmo_border_radius']);

                    echo "<h2>" . esc_html(__('Generated Shortcode:', 'confirmo-payment-gateway')) . "</h2>";
                    echo "<code>[confirmo currency=\"" . esc_attr($currency) . "\" amount=\"" . esc_attr($amount) . "\" button_color=\"" . esc_attr($button_color) . "\" text_color=\"" . esc_attr($text_color) . "\" border_radius=\"" . esc_attr($border_radius) . "\"]</code>";
                }
                ?>
            </div>
            <?php
        }

        function confirmo_debug_page_content()
        {
            $debug_logs = get_option('confirmo_debug_logs', array());
            $recent_logs = array_filter($debug_logs, function ($log) {
                return strtotime($log['time']) >= strtotime('-1 day');
            });

            error_log("Number of recent logs: " . count($recent_logs)); // Debug log to verify log count

            echo '<div class="wrap">';
            echo '<h1>' . esc_html(__('Confirmo Debug Information', 'confirmo-payment-gateway')) . '</h1>';
            echo '<p>' . esc_html(__('If you encounter any issues, please download these debug logs and send them to plugin support.', 'confirmo-payment-gateway')) . '</p>';

            if (!empty($recent_logs)) {
                echo '<table class="widefat fixed" cellspacing="0">';
                echo '<thead><tr><th>' . esc_html(__('Time', 'confirmo-payment-gateway')) . '</th><th>' . esc_html(__('Order ID', 'confirmo-payment-gateway')) . '</th><th>' . esc_html(__('API Response', 'confirmo-payment-gateway')) . '</th><th>' . esc_html(__('Redirect URL', 'confirmo-payment-gateway')) . '</th></thead>';
                echo '<tbody>';
                foreach ($recent_logs as $log) {
                    echo '<tr>';
                    echo '<td>' . esc_html($log['time']) . '</td>';
                    echo '<td>' . (isset($log['order_id']) ? esc_html($log['order_id']) : 'N/A') . '</td>';
                    echo '<td>' . (isset($log['api_response']) ? esc_html($log['api_response']) : 'N/A') . '</td>';
                    echo '<td>' . (isset($log["hook"]) ? esc_html($log["hook"]) : "N/A") . '</td>';
                    echo '</tr>';
                }
                echo '</tbody>';
                echo '</table>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="confirmo_download_logs" value="1">';
                echo '<p><button type="submit" class="button button-primary">' . __('Download Debug Logs', 'confirmo-payment-gateway') . '</button></p>';
                echo '</form>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="confirmo_delete_logs">';
                echo '<p><button type="submit" class="button button-secondary">' . esc_html(__('Delete all logs', 'confirmo-payment-gateway')) . '</button></p>';
                echo '</form>';
            } else {
                echo '<p>' . esc_html(__('No debug logs available for the last day.', 'confirmo-payment-gateway')) . '</p>';
            }
        }

        function confirmo_delete_logs()
        {
            if (isset($_POST['confirmo_delete_logs'])) {
                delete_option('confirmo_debug_logs');
                wp_redirect(admin_url('admin.php?page=confirmo-logs'));
                exit;
            }
        }

        add_action('admin_post_confirmo_delete_logs', 'confirmo_delete_logs');

        function confirmo_download_logs()
        {
            if (isset($_POST['confirmo_download_logs'])) {
                $debug_logs = get_option('confirmo_debug_logs', array());
                $recent_logs = array_filter($debug_logs, function ($log) {
                    return strtotime($log['time']) >= strtotime('-1 day');
                });

                // Initialize the WP_Filesystem
                if (!function_exists('WP_Filesystem')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                WP_Filesystem();
                global $wp_filesystem;

                // Create a temporary file
                $temp_file = wp_tempnam('confirmo_debug_logs.csv');

                if (!$temp_file) {
                    wp_die(esc_html(__('Could not create temporary file', 'confirmo-payment-gateway')));
                }

                // Write the CSV headers
                $csv_content = '';
                $csv_content .= implode(',', array(__('Time', 'confirmo-payment-gateway'), __('Order ID', 'confirmo-payment-gateway'), __('API Response', 'confirmo-payment-gateway'))) . "\n";

                // Write the CSV data
                foreach ($recent_logs as $log) {
                    $csv_content .= implode(',', array($log['time'], $log['order_id'], $log['api_response'])) . "\n";
                }

                // Write the content to the temporary file
                if (!$wp_filesystem->put_contents($temp_file, $csv_content, FS_CHMOD_FILE)) {
                    wp_die(esc_html(__('Could not write to temporary file', 'confirmo-payment-gateway')));
                }

                // Read the file content
                $file_content = $wp_filesystem->get_contents($temp_file);

                if (!$file_content) {
                    wp_die(esc_html(__('Could not read temporary file', 'confirmo-payment-gateway')));
                }

                // Send the file as a download
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment;filename=confirmo_debug_logs.csv');
                //Cant escape, its a file..
                echo $file_content;

                // Clean up
                $wp_filesystem->delete($temp_file);

                exit;
            }
        }


        add_action('admin_init', 'confirmo_download_logs');

        function confirmo_add_debug_log($order_id, $api_response, $hook)
        {
            $debug_logs = get_option('confirmo_debug_logs', array());

            error_log("Debug Log - Order ID: " . $order_id);
            error_log("Debug Log - API Response: " . $api_response);
            error_log("Debug Log - Hook: " . $hook);

            if (!empty($order_id) && !empty($api_response)) {
                $debug_logs[] = array(
                    'time' => current_time('mysql'),
                    'order_id' => $order_id,
                    'api_response' => $api_response,
                    'hook' => $hook
                );
                update_option('confirmo_debug_logs', $debug_logs);
            } else {
                error_log("Missing order_id or api_response or hook in confirmo_add_debug_log");
            }
        }

        function confirmo_purge_old_logs()
        {
            $all_logs = get_option('confirmo_debug_logs', array());
            $current_time = current_time('timestamp');

            $filtered_logs = array_filter($all_logs, function ($log) use ($current_time) {
                return (strtotime($log['time']) > strtotime('-30 days', $current_time));
            });

            update_option('confirmo_debug_logs', $filtered_logs);
        }

// Schedule the log cleanup to run daily
        if (!wp_next_scheduled('confirmo_purge_old_logs_hook')) {
            wp_schedule_event(time(), 'daily', 'confirmo_purge_old_logs_hook');
        }

        add_action('confirmo_purge_old_logs_hook', 'confirmo_purge_old_logs');
        function confirmo_help_page_content()
        {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html(__('Confirmo Cryptocurrency Payment Gateway Help', 'confirmo-payment-gateway')) . '</h1>';
            echo '<p>' . esc_html(__('Crypto payments made easy with industry leaders. Confirmo.net', 'confirmo-payment-gateway')) . '</p>';

            echo '<h2>' . esc_html(__('Enable the future of payments today', 'confirmo-payment-gateway')) . '</h2>';
            echo '<p>' . esc_html(__('Start accepting cryptocurrency payments with Confirmo, one of the fastest growing companies in crypto payments! We provide a payment gateway used by Forex brokers, prop trading companies, e-commerce merchants, and luxury businesses worldwide. Our clients include FTMO, My Forex Funds, Alza and many more. All rely on our easily integrated solutions, low fees, and top-class customer support.', 'confirmo-payment-gateway')) . '</p>';

            echo '<h2>' . esc_html(__('Installing the plugin', 'confirmo-payment-gateway')) . '</h2>';
            echo '<h3>' . esc_html(__('WordPress plugins:', 'confirmo-payment-gateway')) . '</h3>';
            echo '<ol>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to Plugins – Add New, and search for \'Confirmo Cryptocurrency Payment Gateway\'.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('Click Download, and then activate the plugin.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway')) . '</li>';
            echo '</ol>';

            echo '<h3>' . esc_html(__('Upload:', 'confirmo-payment-gateway')) . '</h3>';
            echo '<ol>';
            echo '<li>' . esc_html(__('Download and extract the .zip file.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to Plugins – Add New – Upload Plugin, and upload the extracted folder. Activate the plugin.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway')) . '</li>';
            echo '</ol>';

            echo '<h3>' . esc_html(__('FTP or File Manager:', 'confirmo-payment-gateway')) . '</h3>';
            echo '<ol>';
            echo '<li>' . esc_html(__('Download and extract the .zip file.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('Copy the extracted contents into your WordPress installation under wp-content/plugins.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to Plugins – Installed plugins – Confirmo Cryptocurrency Payment Gateway. Activate the plugin.', 'confirmo-payment-gateway')) . '</li>';
            echo '<li>' . esc_html(__('In your WordPress dashboard, go to WooCommerce – Settings – Payments. Click Confirmo. You will be asked to configure the plugin with information generated in your Confirmo account to connect them.', 'confirmo-payment-gateway')) . '</li>';
            echo '</ol>';

            echo '<h2>' . esc_html(__('Connecting the plugin to your Confirmo account:', 'confirmo-payment-gateway')) . '</h2>';
            echo '<p>' . __('Create an account at <a href="https://confirmo.net">Confirmo.net</a> and then go to Settings – API Keys – Create API key. You will be required to complete an e-mail verification, after which you will receive the API key. Once you have it, go to WooCommerce – Settings – Payments, and enable Confirmo as a payment method. Paste the API key into the respective field.', 'confirmo-payment-gateway') . '</p>';

            echo '<p>' . esc_html(__('To generate a callback password, return to the Confirmo dashboard and go to Settings – Callback password. You will be prompted to complete a second e-mail verification and then provided with the callback password. Again, paste it into the respective field in WooCommerce – Settings – Payments. Callback passwords help increase the security of the API integration. Never share your API key or callback password with anyone!', 'confirmo-payment-gateway')) . '</p>';

            echo '<p>' . esc_html(__('Finally, choose your desired Settlement currency. Make sure to save your changes by clicking the button at the bottom. When the plugin is activated, Confirmo will appear as a payment option in your website\'s WooCommerce checkout. <b>Congratulations, you can now start receiving cryptocurrency payments!</b>', 'confirmo-payment-gateway')) . '</p>';

            echo '<h2>' . esc_html(__('Generating a payment button using shortcode', 'confirmo-payment-gateway')) . '</h2>';
            echo '<p>' . esc_html(__('To generate a payment button, use the following shortcode:', 'confirmo-payment-gateway')) . '</p>';
            echo '<code>[confirmo currency="BTC" amount="100" button_color="#000000" text_color="#FFFFFF" border_radius="5"]</code>';
            echo '<p>' . esc_html(__('In this example:', 'confirmo-payment-gateway')) . '</p>';
            echo '<ul>';
            echo '<li><b>' . esc_html(__('currency', 'confirmo-payment-gateway')) . '</b>: ' . esc_html(__('The currency you want to accept (e.g., BTC)', 'confirmo-payment-gateway')) . '</li>';
            echo '<li><b>' . esc_html(__('amount', 'confirmo-payment-gateway')) . '</b>: ' . esc_html(__('The amount to be paid', 'confirmo-payment-gateway')) . '</li>';
            echo '<li><b>' . esc_html(__('button_color', 'confirmo-payment-gateway')) . '</b>: ' . esc_html(__('The button color (in hex code format)', 'confirmo-payment-gateway')) . '</li>';
            echo '<li><b>' . esc_html(__('text_color', 'confirmo-payment-gateway')) . '</b>: ' . esc_html(__('The button text color (in hex code format)', 'confirmo-payment-gateway')) . '</li>';
            echo '<li><b>' . esc_html(__('border_radius', 'confirmo-payment-gateway')) . '</b>: ' . esc_html(__('The button border radius (in pixels)', 'confirmo-payment-gateway')) . '</li>';
            echo '</ul>';

            echo '<p>' . __('Read more at <a href="https://confirmo.net">Confirmo.net</a>. Should you encounter any difficulties, <a href="mailto:support@confirmo.net">contact us</a> at support@confirmo.net', 'confirmo-payment-gateway') . '</p>';
            echo '</div>';
        }

        $gateway = new WC_Confirmo_Gateway();
        $gateway->confirmo_add_actions();
    }

    add_action('plugins_loaded', 'confirmo_woocommerce_init', 0);
}


function confirmo_payment_shortcode($atts)
{
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

    $style = sprintf(
        'background-color: %s; color: %s; border-radius: %dpx; padding: 8px 16px;',
        esc_attr($button_color),
        esc_attr($text_color),
        $border_radius
    );


    $button_text = sprintf(
    // translators: %1$s is for amount, %2$s is for currency
        __('Pay %1$s %2$s', 'confirmo-payment-gateway'),
        esc_html($amount),
        esc_html($currency)
    );

    return sprintf(
        '<button class="confirmoButton" style="%s" data-currency="%s" data-amount="%s">%s</button>',
        esc_attr($style),
        esc_attr($currency),
        esc_attr($amount),
        $button_text
    );
}

add_shortcode('confirmo', 'confirmo_payment_shortcode');

function confirmo_custom_payment_endpoint()
{
    add_rewrite_rule('^confirmo-custom-payment/?', 'index.php?confirmo-custom-payment=1', 'top');
}

add_action('init', 'confirmo_custom_payment_endpoint');

function confirmo_custom_payment_query_vars($query_vars)
{
    $query_vars[] = 'confirmo-custom-payment';
    return $query_vars;
}

add_filter('query_vars', 'confirmo_custom_payment_query_vars');

function confirmo_custom_payment_template_redirect()
{
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
            'body' => wp_json_encode($data)
        ));

        if (is_wp_error($response)) {
            wp_die(esc_html(__('Error:', 'confirmo-payment-gateway') . ' ' . $response->get_error_message()));
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if (isset($response_data['url'])) {
            wp_redirect($response_data['url']);
            exit;
        } else {
            wp_die(esc_html(__('Error: Payment URL not received.', 'confirmo-payment-gateway')));
        }
    }
}

add_action('template_redirect', 'confirmo_custom_payment_template_redirect');


