<?php

/**
 * Statuses Confirmo added after a store saved its mapping. The mapping a store
 * keeps is the one saved by the version it was activated with, so a status the
 * plugin learns later has no saved entry on almost every live store.
 */
class StatusMappingTest extends ConfirmoTestCase
{
    /** The mapping a store activated on 2.8.x or earlier, or saved on 2.9.0, has. */
    private const MAPPING_WITHOUT_NEWER_STATUSES = [
        'prepared' => 'on-hold',
        'active' => 'on-hold',
        'confirming' => 'on-hold',
        'paid' => 'completed',
        'expired' => 'failed',
        'error' => 'failed',
    ];

    public function testABlockedInvoiceFailsTheOrderAndSaysWhy(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->callbackWithConfirmoSaying($order, 'BLOCKED');

        $order = wc_get_order($order->get_id());
        self::assertSame('failed', $order->get_status());
        self::assertFalse($order->is_paid());
        self::assertStringContainsString('compliance', $this->notes($order));
    }

    public function testAStoreWithAnOlderMappingStillMapsBlocked(): void
    {
        $this->saveMapping(self::MAPPING_WITHOUT_NEWER_STATUSES);
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->callbackWithConfirmoSaying($order, 'BLOCKED');

        self::assertSame('failed', wc_get_order($order->get_id())->get_status());
    }

    public function testAStoreWithAnOlderMappingStillMapsPendingVerification(): void
    {
        $this->saveMapping(self::MAPPING_WITHOUT_NEWER_STATUSES);
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->callbackWithConfirmoSaying($order, 'PENDING_VERIFICATION');

        self::assertSame('on-hold', wc_get_order($order->get_id())->get_status());
    }

    public function testAMerchantsOwnMappingWinsOverTheDefault(): void
    {
        $this->saveMapping(['blocked' => 'cancelled'] + self::MAPPING_WITHOUT_NEWER_STATUSES);
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->callbackWithConfirmoSaying($order, 'BLOCKED');

        self::assertSame('cancelled', wc_get_order($order->get_id())->get_status());
    }

    /** Saving the settings page used to drop every status the form did not list. */
    public function testSavingTheSettingsKeepsTheNewerStatuses(): void
    {
        if (!function_exists('add_settings_error')) {
            require_once ABSPATH . 'wp-admin/includes/template.php';
        }

        $saved = WC_Confirmo_Settings::validateConfigOptions([
            'custom_states_pending_verification' => 'on-hold',
            'custom_states_blocked' => 'cancelled',
        ]);

        self::assertSame('on-hold', $saved['custom_states']['pending_verification']);
        self::assertSame('cancelled', $saved['custom_states']['blocked']);
    }

    private function callbackWithConfirmoSaying(WC_Order $order, string $status): void
    {
        $this->stubApi('/api/v3/invoices/inv-1', ['id' => 'inv-1', 'status' => $status]);

        $response = $this->postCallback(wp_json_encode([
            'id' => 'inv-1',
            'reference' => (string) $order->get_id(),
            'status' => $status,
        ]));

        self::assertSame(200, $response);
    }

    private function saveMapping(array $mapping): void
    {
        $options = get_option('confirmo_gate_config_options');
        $options['custom_states'] = $mapping;
        update_option('confirmo_gate_config_options', $options);
    }

    /** WooCommerce adds its own notes (e.g. the failed-order email) after ours. */
    private function notes(WC_Order $order): string
    {
        $notes = wc_get_order_notes(['order_id' => $order->get_id()]);

        return implode("\n", array_map(fn($note) => $note->content, $notes));
    }
}
