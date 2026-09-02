<?php

/**
 * Its own option and settings group, so this form saves independently of the
 * Checkout gateway form rendered above it on the same page. No API key field:
 * one key covers both products and Subscribe reads it from the main settings.
 */
class WC_Confirmo_Subscribe_Settings
{
    const OPTION = 'confirmo_subscribe_config_options';
    const GROUP = 'confirmo-subscribe-config';
    const TEST_ACTION = 'confirmo_subscribe_test_connection';
    const TEST_CAPABILITY = 'manage_options';

    public static function register(): void
    {
        register_setting(
            self::GROUP,
            self::OPTION,
            [self::class, 'sanitize']
        );

        add_settings_section(
            'confirmo_subscribe_main',
            __('Confirmo Subscribe (Alpha)', 'confirmo-for-woocommerce'),
            [self::class, 'sectionCallback'],
            self::GROUP
        );

        add_settings_field(
            'enabled',
            __('Enable Subscribe module', 'confirmo-for-woocommerce'),
            [self::class, 'enabledFieldCallback'],
            self::GROUP,
            'confirmo_subscribe_main'
        );

        add_settings_field(
            'connection',
            __('Connection', 'confirmo-for-woocommerce'),
            [self::class, 'connectionFieldCallback'],
            self::GROUP,
            'confirmo_subscribe_main'
        );
    }

    public static function sectionCallback(): void
    {
        echo '<div class="notice notice-error inline" style="margin: 0 0 12px;"><p><strong style="color: #d63638;">'
            . esc_html__('Alpha', 'confirmo-for-woocommerce') . '</strong> — '
            . esc_html__('this module is still in development and the implementation may be adjusted in the future.', 'confirmo-for-woocommerce')
            . '</p></div>';

        // The Checkout gateway's Settlement Currency dropdown does not apply here:
        // the subscription API takes no settlement field, so the setting has no
        // request to travel on and merchants would read the dropdown as covering
        // both products.
        echo '<div class="notice notice-warning inline" style="margin: 0 0 12px;"><p><strong style="color: #996800;">'
            . esc_html__('Settlement currency', 'confirmo-for-woocommerce') . '</strong> — '
            . esc_html__('the Settlement Currency setting above applies to Confirmo Checkout payments only. Settlement for Subscribe is configured in the Confirmo merchant portal, not here.', 'confirmo-for-woocommerce')
            . '</p></div>';

        echo '<p>' . esc_html__('Sell Confirmo Subscribe plans from your WooCommerce store. This module is off by default and does not affect the Confirmo Checkout payment gateway.', 'confirmo-for-woocommerce') . '</p>';
    }

    public static function enabledFieldCallback(): void
    {
        $options = get_option(self::OPTION, []);
        $checked = (is_array($options) && ($options['enabled'] ?? 'no') === 'yes') ? 'checked' : '';
        echo '<label><input type="checkbox" id="confirmo_subscribe_enabled" name="' . esc_attr(self::OPTION) . '[enabled]" value="yes" ' . esc_attr($checked) . '> ' . esc_html__('Enable the Subscribe module', 'confirmo-for-woocommerce') . '</label>';
    }

    /**
     * Tests the unsaved value of the Checkout form's API Key field, which sits
     * above this one on the same page, so a key can be checked before it is
     * saved. `type="button"` keeps this out of the form's submit path.
     */
    public static function connectionFieldCallback(): void
    {
        echo '<button type="button" class="button" id="confirmo_subscribe_test">'
            . esc_html__('Test connection', 'confirmo-for-woocommerce') . '</button>';
        echo ' <span id="confirmo_subscribe_test_result"></span>';
        echo '<p class="description">' . esc_html__('Checks the Confirmo API key above against the Subscribe API.', 'confirmo-for-woocommerce') . '</p>';

        $strings = [
            'testing' => __('Testing…', 'confirmo-for-woocommerce'),
            'failed' => __('Could not reach WordPress to run the test.', 'confirmo-for-woocommerce'),
        ];
        ?>
        <script>
            (function () {
                var button = document.getElementById('confirmo_subscribe_test');
                var result = document.getElementById('confirmo_subscribe_test_result');
                var field = document.getElementById('api_key');
                if (!button || !result) {
                    return;
                }
                button.addEventListener('click', function () {
                    result.textContent = <?php echo wp_json_encode($strings['testing']); ?>;
                    result.style.color = '';
                    button.disabled = true;

                    var body = new FormData();
                    body.append('action', <?php echo wp_json_encode(self::TEST_ACTION); ?>);
                    body.append('nonce', <?php echo wp_json_encode(wp_create_nonce(self::TEST_ACTION)); ?>);
                    body.append('api_key', field ? field.value : '');

                    fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', body: body})
                        .then(function (response) { return response.json(); })
                        .then(function (payload) {
                            var data = payload && payload.data ? payload.data : {};
                            result.textContent = data.message || '';
                            result.style.color = payload && payload.success
                                ? (data.warning ? '#996800' : '#008a20')
                                : '#d63638';
                        })
                        .catch(function () {
                            result.textContent = <?php echo wp_json_encode($strings['failed']); ?>;
                            result.style.color = '#d63638';
                        })
                        .finally(function () { button.disabled = false; });
                });
            })();
        </script>
        <?php
    }

    /**
     * Reads variable-price plans specifically, because that is what the product
     * screen offers: a key that authenticates but sees none would otherwise
     * leave the merchant with an empty picker and no explanation.
     */
    public static function handleTestConnection(): void
    {
        check_ajax_referer(self::TEST_ACTION, 'nonce');

        if (!current_user_can(self::TEST_CAPABILITY)) {
            wp_send_json_error([
                'message' => __('You do not have permission to test the connection.', 'confirmo-for-woocommerce'),
            ], 403);
        }

        require_once plugin_dir_path(__FILE__) . 'WC_Confirmo_Subscribe_Api.php';

        $apiKey = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if ($apiKey === '') {
            $apiKey = WC_Confirmo_Subscribe_Api::configuredApiKey();
        }
        if ($apiKey === '') {
            wp_send_json_error([
                'message' => __('Enter your Confirmo API key first.', 'confirmo-for-woocommerce'),
            ]);
        }

        $plans = (new WC_Confirmo_Subscribe_Api($apiKey))->listVariablePlans();

        if (is_wp_error($plans)) {
            wp_send_json_error([
                'message' => $plans->get_error_message() . ' — '
                    . __('check the Confirmo API key.', 'confirmo-for-woocommerce'),
            ]);
        }

        if (empty($plans)) {
            wp_send_json_success([
                'warning' => true,
                'message' => __('Connected, but no variable-price plans were found. Create a plan with a billing currency and no price in your Confirmo dashboard.', 'confirmo-for-woocommerce'),
            ]);
        }

        wp_send_json_success([
            'message' => __('Connected.', 'confirmo-for-woocommerce'),
        ]);
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $new = [];
        $new['enabled'] = (isset($input['enabled']) && $input['enabled'] === 'yes') ? 'yes' : 'no';
        return $new;
    }

    public static function renderForm(): void
    {
        echo '<hr style="margin-top: 2em;">';
        echo '<form method="post" action="options.php">';
        settings_fields(self::GROUP);
        do_settings_sections(self::GROUP);
        submit_button(__('Save Subscribe Settings', 'confirmo-for-woocommerce'));
        echo '</form>';
    }
}
