<?php
// Define the Confirmo_Blocks class conditionally
if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
    class Confirmo_Blocks extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        private $gateway;
        protected $name = 'confirmo';

        public function initialize() {
            $this->settings = get_option('woocommerce_confirmo_settings', []);
            $this->gateway = new WC_Confirmo_Gateway();
        }

        public function is_active() {
            return $this->gateway->is_available();
        }

        public function get_payment_method_script_handles() {
            wp_register_script(
                'confirmo-blocks-integration',
                plugin_dir_url(__FILE__) . 'public/js/confirmo-blocks-integration.js',
                [
                    'wc-blocks-registry',
                    'wc-settings',
                    'wp-element',
                    'wp-html-entities',
                    'wp-i18n',
                ],
                null,
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
            ];
        }
    }
}
