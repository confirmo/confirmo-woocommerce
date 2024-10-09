<?php
/*
* Plugin Name: Confirmo Cryptocurrency Payment Gateway
* Description: Accept crypto & stablecoin payments in WooCommerce with Confirmo. BTC (+ Lightning), USDT & USDC, ETH and more.
* Version: 2.5.0
* Requires PHP: 7.4
* Author: Confirmo.net
* Author URI: https://confirmo.net
* Text Domain: confirmo-payment-gateway
* Domain Path: /languages
* License: GPLv2 or later
* License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

global $confirmo_version;
$confirmo_version = '2.5.0';

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/WC_Confirmo_Activator.php';

// Hook the activation and deactivation function
register_activation_hook(__FILE__, [WC_Confirmo_Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [WC_Confirmo_Activator::class, 'deactivate']);
register_uninstall_hook(__FILE__, [WC_Confirmo_Activator::class, 'uninstall']);

// Test to see if WooCommerce is active (including network activated).
$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

if (
    in_array($plugin_path, wp_get_active_and_valid_plugins()) || in_array($plugin_path, wp_get_active_network_plugins())
) {
    function confirmo_woocommerce_init()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        require_once plugin_dir_path( __FILE__ ) . 'includes/WC_Confirmo_Gateway.php';

        $gateway = new WC_Confirmo_Gateway();
        $gateway->pluginName = plugin_basename(__FILE__);
        $gateway->run();

        // Schedule the log cleanup to run daily
        if (!wp_next_scheduled('confirmo_purge_old_logs_hook')) {
            wp_schedule_event(time(), 'daily', 'confirmo_purge_old_logs_hook');
        }
    }

    add_action('plugins_loaded', 'confirmo_woocommerce_init', 0);

    function confirmo_payment_shortcode($atts)
    {
        $atts = shortcode_atts([
            'currency' => 'BTC',
            'amount' => '0',
            'button_color' => '#000000',
            'text_color' => '#FFFFFF',
            'border_radius' => '0'
        ], $atts, 'confirmo');

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
}
