<?php

// Define the Confirmo_Blocks class conditionally
if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
    class Confirmo_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        private $gateway;
        protected $name = 'confirmo';

        private string $pluginBaseDir;

        public function __construct(string $pluginBaseDir) {
            $this->pluginBaseDir = $pluginBaseDir;
        }

        public function initialize() {
            $this->settings = get_option('confirmo_gate_config_options', []);
            $this->gateway = new WC_Confirmo_Gateway();
        }

        public function is_active() {
            return $this->gateway->is_available();
        }

        public function get_payment_method_script_handles() {
            global $confirmo_version;

            wp_register_script(
                'confirmo-blocks-integration',
                plugins_url('public/js/confirmo-blocks-integration.js', $this->pluginBaseDir),
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
                wp_set_script_translations('confirmo-blocks-integration', 'confirmo-payment-gateway');
            }
            return ['confirmo-blocks-integration'];
        }



        public function get_payment_method_data() {
            return [
                'title' => $this->gateway->title,
                'description' => $this->gateway->description,
            ];
        }
    }
}
