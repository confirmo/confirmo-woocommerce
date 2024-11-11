<?php

class WC_Confirmo_Settings
{

    private static array $orderStatuses = [
        'pending',
        'on-hold',
        'processing',
        'completed',
        'failed',
        'cancelled'
    ];

    private static array $confirmoStatuses = [
        'prepared' => 'Prepared',
        'active' => 'Active',
        'confirming' => 'Confirming',
        'paid' => 'Paid',
        'expired' => 'Expired',
        'error' => 'Error'
    ];

    private static array $allowedCurrencies = [
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
    ];

    public static function register()
    {
        register_setting(
            'confirmo-payment-gate-config',
            'confirmo_gate_config_options',
            [self::class, 'validateConfigOptions']
        );

        add_settings_section(
            'confirmo_gate_config_main',
            __('Main Settings', 'confirmo-payment-gateway'),
            [self::class, 'configSectionCallback'],
            'confirmo-payment-gate-config'
        );

        add_settings_section(
            'confirmo_gate_config_advanced',
            __('Advanced Settings', 'confirmo-payment-gateway'),
            [self::class, 'configAdvancedCallback'],
            'confirmo-payment-gate-config'
        );

        add_settings_field(
            'enabled',
            __('Enable/Disable', 'confirmo-payment-gateway'),
            [self::class, 'configEnabledCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'confirmo-payment-gateway'),
            [self::class, 'configApiKeyCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'callback_password',
            __('Callback Password', 'confirmo-payment-gateway'),
            [self::class, 'configCallbackPasswordCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'settlement_currency',
            __('Settlement Currency', 'confirmo-payment-gateway'),
            [self::class, 'configSettlementCurrencyCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'description',
            __('Description on checkout page', 'confirmo-payment-gateway'),
            [self::class, 'configDescriptionCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_main'
        );

        add_settings_field(
            'custom_states',
            '',
            [self::class, 'configCustomStatusesCallback'],
            'confirmo-payment-gate-config',
            'confirmo_gate_config_advanced'
        );
    }

    public static function configSectionCallback(): void
    {
        echo '<p>' . esc_html__('Adjust the settings for Confirmo payment gateway.', 'confirmo-payment-gateway') . '</p>';
    }

    public static function configAdvancedCallback(): void
    {
        echo '<p>' . esc_html__('Here you can adjust order status pairing to Confirmo payment status.', 'confirmo-payment-gateway') . '</p>';
    }

    public static function configEnabledCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $checked = isset($options['enabled']) && $options['enabled'] ? 'checked' : '';
        echo '<input type="checkbox" id="enabled" name="confirmo_gate_config_options[enabled]" ' . esc_attr($checked) . '>';
    }

    public static function configApiKeyCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $value = isset($options['api_key']) ? esc_attr($options['api_key']) : '';
        echo('<input type="text" id="api_key" name="confirmo_gate_config_options[api_key]" value="' . esc_attr($value) . '" size="70" maxlength="64" required>');
    }

    public static function configCallbackPasswordCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $value = isset($options['callback_password']) ? esc_attr($options['callback_password']) : '';
        echo('<input type="text" id="callback_password" name="confirmo_gate_config_options[callback_password]" value="' . esc_attr($value) . '" required>');
    }

    public static function configSettlementCurrencyCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $current_value = $options['settlement_currency'] ?? 'test';
        echo '<select id="settlement_currency" name="confirmo_gate_config_options[settlement_currency]">';
        foreach (self::$allowedCurrencies as $key => $label) {
            $selected = ($label == $current_value) ? 'selected' : '';
            echo('<option value="' . esc_attr($key) . '" ' . esc_attr($selected) . '>' . esc_html($label) . '</option>');
        }
        echo '</select>';
    }

    public static function configDescriptionCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $value = isset($options['description']) ? esc_textarea($options['description']) : '';
        echo '<textarea id="description" name="confirmo_gate_config_options[description]" rows="5" cols="50">' . esc_textarea($value) . '</textarea>';
    }

    public static function configCustomStatusesCallback(): void
    {
        $options = get_option('confirmo_gate_config_options');
        $values = $options['custom_states'] ?? [
            'prepared' => 'on-hold',
            'active' => 'on-hold',
            'confirming' => 'on-hold',
            'paid' => 'complete',
            'expired' => 'failed',
            'error' => 'failed',
        ];

        echo '<table class="" style="width: auto;margin-top: -20px;margin-left: -220px;background: white;padding: 0 20px;border-radius: 4px;border: 1px solid #8c8f94;">';
        echo '<tr><th>Confirmo status</th><th>WooCommerce status</th></tr>';

        foreach (self::$confirmoStatuses as $key => $label) {
            echo '<tr>';
            echo '<td><label>' . esc_html($label) . '</label></td>';
            echo '<td><select id="custom_states_' . $key . '" name="confirmo_gate_config_options[custom_states_' . $key . ']">';

            foreach (self::$orderStatuses as $status) {
                $selected = ($status === $values[$key]) ? 'selected' : '';
                echo '<option value="' . esc_attr($status) . '" ' . esc_attr($selected) . '>';
                echo esc_html($status);
                echo '</option>';
            }

            echo '</select></td>';
            echo '</tr>';
        }

        echo '</table>';
    }

    public static function validateConfigOptions(array $input): array
    {
        $new_input = [];
        $option_key = 'woocommerce_confirmo_settings';
        $settings = get_option($option_key, []);

        if (isset($input['description'])) {
            $new_input['description'] = sanitize_text_field($input['description']);
        }

        if (isset($input['enabled'])) {
            $new_input['enabled'] = $input['enabled'] === 'on' ? 'yes' : 'no';
        }

        if (isset($input['api_key'])) {
            $api_key = sanitize_text_field($input['api_key']);

            if (strlen($api_key) == 64 && ctype_alnum($api_key)) {
                $new_input['api_key'] = $api_key;
            } else {
                $new_input['api_key'] = $settings['api_key'] ?? '';
                add_settings_error('api_key', 'api_key_error', __('API Key must be exactly 64 alphanumeric characters', 'confirmo-payment-gateway'), 'error');
            }
        }

        if (isset($input['callback_password'])) {
            $callback_password = sanitize_text_field($input['callback_password']);

            if (strlen($callback_password) == 16 && ctype_alnum($callback_password)) {
                $new_input['callback_password'] = $callback_password;
            } else {
                $new_input['callback_password'] = $settings['callback_password'] ?? '';
                add_settings_error('callback_password', 'callback_password_error', __('Callback Password must be 16 alphanumeric characters', 'confirmo-payment-gateway'), 'error');
            }
        }

        if (isset($input['settlement_currency'])) {
            $settlement_currency = $input['settlement_currency']; //This is a number, 0-8..
            if (self::$allowedCurrencies[$settlement_currency]) {
                $new_input['settlement_currency'] = self::$allowedCurrencies[$settlement_currency];
            } else {
                $new_input['settlement_currency'] = $settings['settlement_currency'] ?? '';
                add_settings_error('settlement_currency', 'settlement_currency_error', __('Invalid settlement currency selected.', 'confirmo-payment-gateway'), 'error');
            }
        }

        foreach (self::$confirmoStatuses as $key => $label) {
            if (isset($input['custom_states_' . $key])) {
                $new_input['custom_states'][$key] = $input['custom_states_' . $key];
            }
        }

        return $new_input;
    }

}
