<?php

/**
 * Helper class to faciliate plugin activation and deactivation hooks
 */
class WC_Confirmo_Activator
{

    /**
     * Plugin activation hook
     *
     * @return void
     */
    public static function activate(): void
    {
        global $wpdb;
        global $confirmo_version;

        $option_key = 'woocommerce_confirmo_settings';
        $default_settings = [
            'enabled' => 'no',  // Default setting to disabled
            'api_key' => '',    // Default empty API key
            'callback_password' => '',  // Default empty callback password
            'settlement_currency' => '', // Default to empty or a specific currency code,
        ];

        $current_settings = get_option($option_key, []);
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

        // Initialize DB table for logs
        $table_name = $wpdb->prefix . "confirmo_logs";
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            `id` INT NOT NULL AUTO_INCREMENT,
            `time` TIMESTAMP NOT NULL,
            `order_id` INT NOT NULL,
            `api_response` TEXT NOT NULL,
            `hook` VARCHAR(255) NOT NULL,
            `version` VARCHAR(10) NOT NULL,
            PRIMARY KEY  (`id`),
            KEY `time` (`time` ASC),
            KEY `order_id` (`order_id` ASC)
        ) {$charset_collate}";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        add_option('confirmo_version', $confirmo_version);
        add_option('confirmo_base_url', 'https://confirmo.net');
    }

    /**
     * Plugin deactivation hook
     *
     * @return void
     */
    public static function deactivate(): void
    {

    }

    /**
     * Plugin uninstall hook
     *
     * @return void
     */
    public static function uninstall(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . "confirmo_logs";
        $wpdb->query(
            $wpdb->prepare(
                "DROP TABLE IF EXISTS %i",
                $table_name
            )
        );
    }

}
