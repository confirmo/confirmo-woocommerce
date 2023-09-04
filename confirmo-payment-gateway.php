<?php
/*
Plugin Name: Confirmo Cryptocurrency Payment Gateway
Description: Accept most used cryptocurrency in your WooCommerce store with the Confirmo Cryptocurrency Payment Gateway  as easily as with a bank card.
Version: 2.0
Author: Jakub Slechta
*/

// Make sure WooCommerce is active
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
{

    function confirmo_enqueue_scripts()
    {
        // Enqueue the script
        wp_enqueue_script('confirmo-custom-script', plugins_url('public/js/confirmo-crypto-gateway.js', __FILE__) , array(
            'jquery'
        ) , null, true);

        // Pass the image URL to the script
        $image_url = plugins_url('public/img/confirmo.png', __FILE__);
        wp_localize_script('confirmo-custom-script', 'confirmoParams', array(
            'imageUrl' => $image_url
        ));
    }

    add_action('wp_enqueue_scripts', 'confirmo_enqueue_scripts');

    function woocommerce_confirmo_init()
    {
        // Check if the WooCommerce plugin is active and if the WC_Payment_Gateway class exists
        if (!class_exists('WC_Payment_Gateway'))
        {
            return;
        }

        class WC_Confirmo_Gateway extends WC_Payment_Gateway
        {

            // Setup our Gateway's id, description and other values
            function __construct()
            {

                $this->id = "confirmo";
                $this->method_title = __("Confirmo", 'confirmo-payment-gateway');
                $this->method_description = __("Crypto payments made easy with industry leaders.", 'confirmo-payment-gateway');

                // This gateways shows / does not show on the checkout
                $this->enabled = "yes";

                // This controls the title which the user sees during checkout.
                $this->title = "Crypto Payment"; // title of the payment method (can be overridden by the user)
                // This controls the description which the user sees during checkout.
                $this->description = "Pay with Bitcoin, Lightning, Stablecoins and other Crypto via Confirmo Crypto Gateway.";

                // Supports default credit card form
                $this->supports = array();

                // Method with all the options fields
                $this->init_form_fields();

                // Load the settings.
                $this->init_settings();
                $this->api_key = $this->get_option('api_key');
                $this->settlement_currency = $this->get_option('settlement_currency');
                $this->callback_password = $this->get_option('callback_password');

                // Save settings
                if (is_admin())
                {
                    add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
                        $this,
                        'process_admin_options'
                    ));
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
                    '' => 'Keep it in kind (no conversion)',
                );

                $this->form_fields = array(
                    'enabled' => array(
                        'title' => 'Enable/Disable',
                        'type' => 'checkbox',
                        'label' => 'Enable Confirmo Payment',
                        'default' => 'no'
                    ) ,
                    'api_key' => array(
                        'title' => 'API Key',
                        'type' => 'text',
                        'description' => 'Enter your Confirmo API Key here',
                        'default' => '',
                        'desc_tip' => true,
                        'custom_attributes' => array(
                            'required' => 'required'
                        )
                    ) ,
                    'callback_password' => array(
                        'title' => 'Callback Password',
                        'type' => 'text',
                        'description' => 'Enter your Confirmo Callback Password',
                        'default' => '',
                        'desc_tip' => true,
                        'custom_attributes' => array(
                            'required' => 'required'
                        )
                    ) ,
                    'settlement_currency' => array(
                        'title' => 'Settlement Currency',
                        'type' => 'select',
                        'desc_tip' => true,
                        'description' => 'Settlement currency refers to the currency in which a crypto payment is finalized or settled.',
                        'options' => $settlement_currency_options,
                    ) ,
                );
            }

            private function validate_and_sanitize_settlement_currency($settlement_currency)
            {
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

                if (!in_array($settlement_currency, $allowed_currencies, true))
                {
                    throw new Exception("Invalid settlement currency selected.");
                }

                return $settlement_currency;
            }

            private function validate_and_sanitize_api_key($api_key)
            {
                if (strlen($api_key) != 64)
                {
                    throw new Exception("API Key must be 64 characters long.");
                }

                if (!ctype_alnum($api_key))
                {
                    throw new Exception("API Key must only contain alphanumeric characters.");
                }

                return sanitize_text_field($api_key);
            }

            private function validate_and_sanitize_callback_password($callback_password)
            {
                if (strlen($callback_password) != 16)
                {
                    throw new Exception("Callback Password must be 16 characters long.");
                }

                if (!ctype_alnum($callback_password))
                {
                    throw new Exception("Callback Password must only contain alphanumeric characters.");
                }

                return sanitize_text_field($callback_password);
            }

            // Override the process_admin_options function to add our validation for both API key and callback password
            public function process_admin_options()
            {
                try
                {
                    $api_key = $this->validate_and_sanitize_api_key($_POST['woocommerce_confirmo_api_key']);
                    $_POST['woocommerce_confirmo_api_key'] = $api_key;

                    $callback_password = $this->validate_and_sanitize_callback_password($_POST['woocommerce_confirmo_callback_password']);
                    $_POST['woocommerce_confirmo_callback_password'] = $callback_password;

                    $settlement_currency = $this->validate_and_sanitize_settlement_currency($_POST['woocommerce_confirmo_settlement_currency']);
                    $_POST['woocommerce_confirmo_settlement_currency'] = $settlement_currency;

                    // Call the parent function to save the settings
                    return parent::process_admin_options();

                }
                catch(Exception $e)
                {
                    // Before adding the error, check for a transient to prevent duplicates
                    if (false === get_transient('confirmo_error_message'))
                    {
                        // Add an error message and do not save the settings
                        WC_Admin_Settings::add_error($e->getMessage());

                        // Set the transient to prevent the same error being added again in the same request
                        set_transient('confirmo_error_message', true, 10);
                    }

                    return false;
                }
            }

            public function process_payment($order_id)
            {
                global $woocommerce;

                $order = wc_get_order($order_id);

                $total_amount = $order->get_total();

                $product_name = '';
                $product_description = '';
                foreach ($order->get_items() as $item)
                {
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
                    'settlement' => array(
                        'currency' => $settlement_currency,
                    ) ,
                    'product' => array(
                        'name' => $product_name,
                        'description' => $product_description
                    ) ,
                    'invoice' => array(
                        'currencyFrom' => 'CZK',
                        'amount' => $total_amount
                    ) ,
                    'notificationUrl' => $notify_url,
                    'notifyUrl' => $notify_url,
                    'returnUrl' => $return_url,
                    'reference' => strval($order_id) ,
                );

                $response = wp_remote_post($url, array(
                    'headers' => $headers,
                    'body' => json_encode($body) ,
                    'method' => 'POST',
                    'data_format' => 'body'
                ));

                if (is_wp_error($response))
                {
                    $error_message = $response->get_error_message();
                    wc_add_notice(__('Payment error: ', 'confirmo-payment-gateway') . $error_message, 'error');
                    return;
                }

                $response_body = wp_remote_retrieve_body($response);
                $response_data = json_decode($response_body, true);

                // Check if the payment_url is in the response
                if (!isset($response_data['url']))
                {
                    wc_add_notice(__('Payment error: The Confirmo API response did not contain a url.', 'confirmo-payment-gateway') , 'error');
                    return;
                }

                // Get the payment_url from the response
                $confirmo_redirect_url = $response_data['url'];

                // Save the Confirmo Redirect URL as a custom order meta field
                update_post_meta($order_id, '_confirmo_redirect_url', $confirmo_redirect_url);

                // Update the order status to on-hold and reduce stock levels
                $order->update_status('on-hold', __('Awaiting Confirmo payment.', 'confirmo-payment-gateway'));
                wc_reduce_stock_levels($order_id);

                // Empty the cart
                $woocommerce->cart->empty_cart();

                // Return the payment_url for redirection
                return array(
                    'result' => 'success',
                    'redirect' => $confirmo_redirect_url
                );
            }

            public function add_confirmo_actions()
            {
                add_action('init', array(
                    $this,
                    'add_confirmo_endpoint'
                ));
                add_filter('query_vars', array(
                    $this,
                    'add_confirmo_query_var'
                ));
                add_action('template_redirect', array(
                    $this,
                    'handle_confirmo_notification'
                ));
            }

            public function add_confirmo_endpoint()
            {
                add_rewrite_rule('^confirmo-notification/?', 'index.php?confirmo-notification=1', 'top');
            }

            public function add_confirmo_query_var($query_vars)
            {
                $query_vars[] = 'confirmo-notification';
                return $query_vars;
            }

            public function handle_confirmo_notification()
            {
                global $wp_query;

                if (isset($wp_query->query_vars['confirmo-notification']))
                {
                    // This is a Confirmo notification. Handle it here.
                    // Get the JSON body of the POST request
                    $json = file_get_contents('php://input');

                    // Validate the signature using the callback password if it's set
                    if (!empty($this->callback_password))
                    {
                        $signature = hash('sha256', $json . $this->callback_password);
                        if ($_SERVER['HTTP_BP_SIGNATURE'] !== $signature)
                        {
                            error_log("Signature validation failed!");
                        }
                    }
                    else
                    {
                        error_log("No callback password set, proceeding without validation.");
                    }

                    $data = json_decode($json, true);

                    // Extract the order ID from the notification data
                    $order_id = $data['reference'];

                    // Get the corresponding WooCommerce order
                    $order = wc_get_order($order_id);

                    if (!$order)
                    {
                        error_log("Failed to retrieve order with reference: " . $order_id);
                        exit;
                    }

                    // Update the order status based on the payment status in the notification
                    switch ($data['status'])
                    {

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

        // Add the Confirmo gateway to the list of WooCommerce payment gateways
        function add_confirmo_gateway_class($gateways)
        {
            $gateways[] = 'WC_Confirmo_Gateway';
            return $gateways;
        }
        add_filter('woocommerce_payment_gateways', 'add_confirmo_gateway_class');

        // Hook into WooCommerce emails to add the Confirmo Redirect URL
        function add_confirmo_url_to_emails($order, $sent_to_admin, $plain_text, $email)
        {
            // Check if the email type is 'new_order' or 'customer_on_hold_order'
            if ($email->id == 'new_order' || $email->id == 'customer_on_hold_order')
            {
                // Get the Confirmo Redirect URL from the order meta
                $confirmo_redirect_url = get_post_meta($order->get_id() , '_confirmo_redirect_url', true);
                if ($confirmo_redirect_url)
                {
                    // Display the Confirmo Redirect URL
                    echo $plain_text ? "Confirmo Payment URL: $confirmo_redirect_url\n" : "<p><strong>Confirmo Payment URL:</strong> <a href='$confirmo_redirect_url'>$confirmo_redirect_url</a></p>";
                }
            }
        }
        add_action('woocommerce_email_after_order_table', 'add_confirmo_url_to_emails', 10, 4);

        // Hook into the WooCommerce Edit Order page to add the Confirmo Redirect URL
        function add_confirmo_url_to_edit_order($order)
        {
            // Get the Confirmo Redirect URL from the order meta
            $confirmo_redirect_url = get_post_meta($order->get_id() , '_confirmo_redirect_url', true);

            if ($confirmo_redirect_url)
            {
                // Display the Confirmo Redirect URL
                echo '<p><strong>Confirmo Payment URL:</strong> <a href="' . $confirmo_redirect_url . '" target="_blank">' . $confirmo_redirect_url . '</a></p>';
            }
        }
        add_action('woocommerce_admin_order_data_after_billing_address', 'add_confirmo_url_to_edit_order', 10, 1);

        function custom_order_status_thank_you_text($original_text, $order)
        {
            $confirmo_redirect_url = get_post_meta($order->get_id() , '_confirmo_redirect_url', true);
            if (!$order) return $original_text; // Return the original text if there's no order
            $status = $order->get_status();
            $custom_text = '';

            switch ($status)
            {
                case 'on-hold':
                    if ($confirmo_redirect_url)
                    {
                        $custom_text = 'Your order will be completed once the payment receives sufficient confirmations. <br><br>Your Confirmo Payment URL: <a href="' . $confirmo_redirect_url . '">' . $confirmo_redirect_url . '</a>';
                    }
                    else
                    {
                        $custom_text = 'Your order is currently on hold, awaiting your payment.';
                    }
                break;
                case 'processing':
                    if ($confirmo_redirect_url)
                    {
                        $custom_text = 'Your payment has been completed. <br><br>Your Confirmo Payment URL: <a href="' . $confirmo_redirect_url . '">' . $confirmo_redirect_url . '</a>';
                    }
                    else
                    {
                        $custom_text = 'Your payment has been completed.';
                    }
                break;
            }

            if ($custom_text)
            {
                return $original_text . '<p>' . $custom_text . '</p>';
            }
            else
            {
                return $original_text;
            }
        }
        add_filter('woocommerce_thankyou_order_received_text', 'custom_order_status_thank_you_text', 10, 2);

        // Instantiate the gateway class and add the actions
        $gateway = new WC_Confirmo_Gateway();
        $gateway->add_confirmo_actions();
    }
    add_action('plugins_loaded', 'woocommerce_confirmo_init', 0);

}
