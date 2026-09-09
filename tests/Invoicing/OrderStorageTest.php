<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * WooCommerce can keep orders either as posts or in its own order tables, and the
 * second is the default for new installs. Reading or writing order data the old
 * way puts the Confirmo payment URL somewhere nothing reads it back, and a plugin
 * that has not declared support is listed to the merchant as incompatible.
 */
class OrderStorageTest extends ConfirmoTestCase
{
    /** @var string */
    private const PLUGIN = 'confirmo-woocommerce/confirmo-payment-gateway.php';

    public function testTheGatewayDeclaresSupportForBothOrderStorageAndBlocks(): void
    {
        if (!class_exists(FeaturesUtil::class)) {
            self::markTestSkipped('this WooCommerce has no feature registry');
        }

        foreach (['custom_order_tables', 'cart_checkout_blocks'] as $feature) {
            $declared = FeaturesUtil::get_compatible_plugins_for_feature($feature, true);

            self::assertContains(
                self::PLUGIN,
                $declared['compatible'],
                $feature . ' must be declared, or WooCommerce warns the merchant the plugin is incompatible'
            );
        }
    }

    /** Written where the store actually keeps orders, so admin and emails find it. */
    public function testCheckoutStoresThePaymentUrlWhereOrderCrudFindsIt(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->stubApi('/api/v3/invoices', ['id' => 'inv-1', 'url' => 'https://confirmo.test/invoice/inv-1']);

        (new WC_Confirmo_Gateway())->process_payment($order->get_id());

        self::assertSame(
            'https://confirmo.test/invoice/inv-1',
            wc_get_order($order->get_id())->get_meta(WC_Confirmo_Gateway::REDIRECT_URL_META)
        );
    }

    /** The same URL is what admin, the emails and the thank-you page read back. */
    public function testThePaymentUrlIsReadableAfterCheckout(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        $this->stubApi('/api/v3/invoices', ['id' => 'inv-2', 'url' => 'https://confirmo.test/invoice/inv-2']);

        (new WC_Confirmo_Gateway())->process_payment($order->get_id());

        self::assertSame('https://confirmo.test/invoice/inv-2', $this->readUrl(wc_get_order($order->get_id())));
    }

    /**
     * An order placed by an earlier version on a store already using the order
     * tables has its URL in post meta, where order CRUD cannot see it. Those
     * orders can still be awaiting payment when the merchant upgrades.
     */
    public function testAUrlLeftInPostMetaByAnEarlierVersionIsStillFound(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));
        update_post_meta($order->get_id(), WC_Confirmo_Gateway::REDIRECT_URL_META, 'https://confirmo.test/legacy');

        self::assertSame('https://confirmo.test/legacy', $this->readUrl(wc_get_order($order->get_id())));
    }

    public function testAnOrderWithNoPaymentUrlReadsBackEmpty(): void
    {
        $order = $this->makeOrder($this->makeSimpleProduct('25.00'));

        self::assertSame('', $this->readUrl(wc_get_order($order->get_id())));
    }

    /** The one place the gateway resolves the URL, whichever storage holds it. */
    private function readUrl(WC_Order $order): string
    {
        $method = new ReflectionMethod(WC_Confirmo_Gateway::class, 'redirectUrlFor');
        $method->setAccessible(true);

        return $method->invoke(new WC_Confirmo_Gateway(), $order);
    }
}
