<?php
/*
* Plugin Name: Confirmo Cryptocurrency Payment Gateway for WooCommerce
* Description: Accept payments in Bitcoin, Ethereum, USDT, USDC, and more directly in WooCommerce. Fast, secure, and easy-to-use crypto payment gateway.
* Version: 2.7.0
* Requires PHP: 7.4
* Author: Confirmo.net
* Author URI: https://confirmo.net
* Text Domain: confirmo-for-woocommerce
* Domain Path: /languages
* Requires Plugins: woocommerce
* License: GPLv2 or later
* License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

global $confirmo_version;
$confirmo_version = '2.7.0';

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/WC_Confirmo_Activator.php';

// Hook the activation and deactivation function
register_activation_hook(__FILE__, [WC_Confirmo_Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [WC_Confirmo_Activator::class, 'deactivate']);
register_uninstall_hook(__FILE__, [WC_Confirmo_Activator::class, 'uninstall']);

// Test to see if WooCommerce is active (including network activated).
$plugin_path = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce/woocommerce.php';

if (
    in_array($plugin_path, wp_get_active_and_valid_plugins())
) {
    function confirmo_woocommerce_init()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }

        require_once plugin_dir_path( __FILE__ ) . 'includes/WC_Confirmo_Gateway.php';

        $gateway = new WC_Confirmo_Gateway();
        $gateway->pluginName = plugin_basename(__FILE__);
        $gateway->pluginBaseDir = __FILE__;
        $gateway->run();

        // Schedule the log cleanup to run daily
        if (!wp_next_scheduled('confirmo_purge_old_logs_hook')) {
            wp_schedule_event(time(), 'daily', 'confirmo_purge_old_logs_hook');
        }
    }

    add_action('plugins_loaded', 'confirmo_woocommerce_init', 0);
}
