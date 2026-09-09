<?php

/**
 * The block checkout only renders payment methods registered through its own
 * API, so a classic WC_Payment_Gateway is invisible there without this
 * descriptor. Mirrors Confirmo_Blocks, the Checkout gateway's equivalent.
 */
if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
    class Confirmo_Subscribe_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
        protected $name = 'confirmo_subscribe';

        private string $pluginBaseFile;

        private $gateway;

        public function __construct(string $pluginBaseFile)
        {
            $this->pluginBaseFile = $pluginBaseFile;
        }

        public function initialize()
        {
            require_once plugin_dir_path(__FILE__) . 'WC_Confirmo_Subscribe_Gateway.php';
            $this->settings = get_option(WC_Confirmo_Subscribe_Settings::OPTION, []);
            $this->gateway = new WC_Confirmo_Subscribe_Gateway();
        }

        public function is_active()
        {
            return WC_Confirmo_Subscribe_Module::isEnabled() && $this->gateway->is_available();
        }

        public function get_payment_method_script_handles()
        {
            global $confirmo_version;

            wp_register_script(
                'confirmo-subscribe-blocks-integration',
                plugins_url('public/js/confirmo-subscribe-blocks-integration.js', $this->pluginBaseFile),
                [
                    'wc-blocks-registry',
                    'wc-settings',
                    'wp-element',
                    'wp-html-entities',
                    'wp-i18n',
                ],
                $confirmo_version,
                true
            );
            if (function_exists('wp_set_script_translations')) {
                wp_set_script_translations('confirmo-subscribe-blocks-integration', 'confirmo-for-woocommerce');
            }
            return ['confirmo-subscribe-blocks-integration'];
        }

        public function get_payment_method_data()
        {
            return [
                'title' => $this->gateway->title,
                'description' => __('Recurring subscription paid in crypto via Confirmo.', 'confirmo-for-woocommerce'),
                'supports' => array_values($this->gateway->supports ?? ['products']),
            ];
        }
    }
}
