<?php

/**
 * With the toggle off, nothing functional is registered and the plugin behaves
 * exactly like the Checkout-only gateway. That guarantee is what protects the
 * merchants already running this plugin, so keep the enabled branch below the
 * early return.
 */
class WC_Confirmo_Subscribe_Module
{
    public static function isEnabled(): bool
    {
        $options = get_option(WC_Confirmo_Subscribe_Settings::OPTION, []);
        return is_array($options) && ($options['enabled'] ?? 'no') === 'yes';
    }

    public static function boot(string $pluginBaseFile): void
    {
        require_once plugin_dir_path(__FILE__) . 'WC_Confirmo_Subscribe_Settings.php';

        add_action('admin_init', [WC_Confirmo_Subscribe_Settings::class, 'register']);
        add_action('confirmo_subscribe_settings_page', [WC_Confirmo_Subscribe_Settings::class, 'renderForm']);

        // Registered outside the enabled branch so a merchant can validate the
        // API key before switching the module on.
        add_action(
            'wp_ajax_' . WC_Confirmo_Subscribe_Settings::TEST_ACTION,
            [WC_Confirmo_Subscribe_Settings::class, 'handleTestConnection']
        );

        if (!self::isEnabled()) {
            return;
        }

        require_once plugin_dir_path(__FILE__) . 'WC_Confirmo_Subscribe_Log.php';
        require_once plugin_dir_path(__FILE__) . 'WC_Confirmo_Subscribe_Api.php';
        require_once plugin_dir_path(__FILE__) . 'WC_Confirmo_Subscribe_Wcs.php';

        // This build projects onto WooCommerce Subscriptions objects, so it has
        // nothing to drive without it.
        if (!WC_Confirmo_Subscribe_Wcs::isActive()) {
            add_action('admin_notices', static function () {
                echo '<div class="notice notice-error"><p>'
                    . esc_html__('Confirmo Subscribe needs the WooCommerce Subscriptions extension active.', 'confirmo-for-woocommerce')
                    . '</p></div>';
            });
            return;
        }

        foreach ([
            'WC_Confirmo_Subscribe_Link',
            'WC_Confirmo_Subscribe_Plan',
            'WC_Confirmo_Subscribe_Amount',
            'WC_Confirmo_Subscribe_Capabilities',
            'WC_Confirmo_Subscribe_Projection',
            'WC_Confirmo_Subscribe_Cancellation',
            'WC_Confirmo_Subscribe_Signature',
            'WC_Confirmo_Subscribe_Product',
            'WC_Confirmo_Subscribe_Gateway',
            'WC_Confirmo_Subscribe_Webhook',
            'WC_Confirmo_Subscribe_Checkout',
        ] as $class) {
            require_once plugin_dir_path(__FILE__) . $class . '.php';
        }

        WC_Confirmo_Subscribe_Wcs::register();
        WC_Confirmo_Subscribe_Product::register();
        WC_Confirmo_Subscribe_Webhook::register();
        WC_Confirmo_Subscribe_Checkout::register();

        add_filter('woocommerce_payment_gateways', static function (array $gateways) {
            $gateways[] = 'WC_Confirmo_Subscribe_Gateway';
            return $gateways;
        });

        // The block checkout only renders methods registered through its own
        // API; a classic WC_Payment_Gateway is invisible there without this.
        add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) use ($pluginBaseFile) {
            if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
                return;
            }
            require_once plugin_dir_path(__FILE__) . 'class-confirmo-subscribe-blocks.php';
            $registry->register(new Confirmo_Subscribe_Blocks($pluginBaseFile));
        });
    }
}
