<?php

/**
 * Buying a subscription: the order becomes a Confirmo subscription billed at the
 * product's price, and the two are linked so later webhooks can find each other.
 *
 * The amount is the whole point. Confirmo bills it every cycle for the life of
 * the mandate, so sending the wrong number is not a bug that shows up once.
 */
class SubscriptionCheckoutTest extends ConfirmoTestCase
{
    public function testCheckoutOpensAConfirmoSubscriptionForTheRecurringTotal(): void
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $wcsSubscription) = $this->makeSubscriptionOrder($product);

        $this->stubApi('/api/v3/subscriptions', [
            'id' => 'sub-1',
            'checkoutUrl' => 'https://confirmo.test/subscribe/sub-1',
        ]);

        $result = (new WC_Confirmo_Subscribe_Gateway())->process_payment($order->get_id());

        self::assertSame('success', $result['result']);
        self::assertStringContainsString('sub-1', $result['redirect']);

        $sent = $this->requestTo('/api/v3/subscriptions');
        self::assertSame('plan-monthly', $sent['planId']);
        self::assertEquals(29.00, (float) $sent['amount'], 'Confirmo must bill the recurring total');
        self::assertSame('buyer@example.com', $sent['subscriber']['email']);
        self::assertNotEmpty($sent['notifyUrl'], 'without a notifyUrl no webhook ever arrives');

        self::assertSame(
            'sub-1',
            WC_Confirmo_Subscribe_Link::confirmoId(wcs_get_subscription($wcsSubscription->get_id())),
            'the Confirmo id must be stored on the subscription, or no webhook can be applied'
        );
    }

    /**
     * WooCommerce > Payments shows this gateway with the usual on/off switch.
     * Hard-coding `enabled` left that switch looking functional while changing
     * nothing, so a merchant who turned it off was still selling subscriptions.
     */
    public function testTurningTheGatewayOffInWooCommercePaymentsTakesEffect(): void
    {
        update_option('woocommerce_confirmo_subscribe_settings', ['enabled' => 'no']);

        $gateway = new WC_Confirmo_Subscribe_Gateway();

        self::assertSame('no', $gateway->enabled);
        self::assertFalse($gateway->is_available(), 'a gateway turned off must not be offered');
    }

    /** Nothing is offered to a subscriber until the merchant asks for it. */
    public function testTheGatewayIsOffUntilTheMerchantEnablesIt(): void
    {
        delete_option('woocommerce_confirmo_subscribe_settings');

        $gateway = new WC_Confirmo_Subscribe_Gateway();

        self::assertSame('no', $gateway->enabled);
        self::assertFalse($gateway->is_available());
    }

    /** Quantity multiplies one subscription rather than opening several. */
    public function testQuantityIsBilledAsOneSubscriptionAtTheMultipliedAmount(): void
    {
        $product = $this->makeSubscriptionProduct('10.00', 'plan-monthly');
        list($order) = $this->makeSubscriptionOrder($product, 3);

        $this->stubApi('/api/v3/subscriptions', ['id' => 'sub-2', 'checkoutUrl' => 'https://confirmo.test/s/2']);

        (new WC_Confirmo_Subscribe_Gateway())->process_payment($order->get_id());

        self::assertEquals(30.00, (float) $this->requestTo('/api/v3/subscriptions')['amount']);
    }

    /**
     * A FIXED plan carries its own price, so Confirmo rejects an amount for it.
     * Refusing at checkout is what keeps that from reaching the customer as an
     * error, and legacy products mapped before VARIABLE-only still carry one.
     */
    public function testAProductMappedToAFixedPlanIsRefusedBeforeAnyApiCall(): void
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-fixed');
        update_post_meta($product->get_id(), WC_Confirmo_Subscribe_Plan::META_TYPE, 'FIXED');

        list($order) = $this->makeSubscriptionOrder($product);

        $result = (new WC_Confirmo_Subscribe_Gateway())->process_payment($order->get_id());

        self::assertSame('failure', $result['result']);
        self::assertNull($this->requestTo('/api/v3/subscriptions'), 'nothing should have been sent to Confirmo');
    }

    /**
     * Under one currency unit `absint($total)` is zero, which is one of the four
     * conditions that hands renewals back to WooCommerce's own clock — so the
     * store would bill on a schedule Confirmo knows nothing about.
     */
    public function testARecurringTotalBelowTheFloorIsRefused(): void
    {
        $product = $this->makeSubscriptionProduct('0.50', 'plan-monthly');
        list($order) = $this->makeSubscriptionOrder($product);

        $result = (new WC_Confirmo_Subscribe_Gateway())->process_payment($order->get_id());

        self::assertSame('failure', $result['result']);
        self::assertNull($this->requestTo('/api/v3/subscriptions'));
    }

    /**
     * Returning to pay a pending order reuses the same order. Creating again
     * would leave the first mandate live, unlinked, and impossible to cancel from
     * the store — two funded subscriptions, one of them invisible.
     */
    public function testPayingTheSameOrderTwiceDoesNotOpenASecondSubscription(): void
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order) = $this->makeSubscriptionOrder($product);

        $this->stubApi('/api/v3/subscriptions', ['id' => 'sub-3', 'checkoutUrl' => 'https://confirmo.test/s/3']);

        $gateway = new WC_Confirmo_Subscribe_Gateway();
        $gateway->process_payment($order->get_id());

        $created = 0;
        foreach ($this->httpCalls as $call) {
            if (substr($call['url'], -strlen('/api/v3/subscriptions')) === '/api/v3/subscriptions') {
                $created++;
            }
        }
        self::assertSame(1, $created);

        $gateway->process_payment($order->get_id());

        $createdAfter = 0;
        foreach ($this->httpCalls as $call) {
            if (substr($call['url'], -strlen('/api/v3/subscriptions')) === '/api/v3/subscriptions') {
                $createdAfter++;
            }
        }
        self::assertSame(1, $createdAfter, 'the second attempt must reuse the existing subscription');
    }
}
