<?php

/**
 * The outbound direction: a cancellation made in the store has to reach Confirmo.
 *
 * If it does not, the customer sees a cancelled subscription and keeps being
 * charged — the worst failure this integration has, because nothing in
 * WooCommerce looks wrong.
 */
class CancellationTest extends SubscribeTestCase
{
    public function testCancellingInWooCommerceCancelsAtConfirmo(): void
    {
        $subscription = $this->activeSubscription('sub-cancel');

        $this->stubApi('/api/v3/subscriptions/sub-cancel/cancel', ['id' => 'sub-cancel', 'status' => 'CANCELED']);

        $subscription->update_status('cancelled');

        self::assertNotNull(
            $this->requestTo('/api/v3/subscriptions/sub-cancel/cancel'),
            'the cancel must have been sent to Confirmo'
        );
        self::assertSame('cancelled', wcs_get_subscription($subscription->get_id())->get_status());
    }

    /**
     * WooCommerce Subscriptions offers "cancel at period end" for gateways that
     * support it. Confirmo cancels immediately, so that route has to end in a
     * real cancellation too rather than leaving a subscription nobody cancels.
     */
    public function testPendingCancellationAlsoReachesConfirmo(): void
    {
        $subscription = $this->activeSubscription('sub-pending-cancel');

        $this->stubApi('/api/v3/subscriptions/sub-pending-cancel/cancel', ['status' => 'CANCELED']);

        $subscription->update_status('pending-cancel');

        self::assertNotNull($this->requestTo('/api/v3/subscriptions/sub-pending-cancel/cancel'));
        self::assertSame('cancelled', wcs_get_subscription($subscription->get_id())->get_status());
    }

    /**
     * If Confirmo will not cancel, the store must not sit there showing cancelled
     * while Confirmo keeps billing. The local cancellation is put back so the two
     * still agree.
     */
    public function testAFailedCancelAtConfirmoIsRolledBackLocally(): void
    {
        $subscription = $this->activeSubscription('sub-refused');

        $this->stubApi('/api/v3/subscriptions/sub-refused/cancel', ['error' => 'upstream'], 500);

        $subscription->update_status('cancelled');

        self::assertSame(
            'active',
            wcs_get_subscription($subscription->get_id())->get_status(),
            'the cancellation should have been reversed'
        );
    }

    /** A subscription Confirmo had on hold must come back on hold, not active. */
    public function testAFailedCancelRestoresTheStatusItCameFrom(): void
    {
        $subscription = $this->activeSubscription('sub-refused-hold');
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription) {
            $subscription->update_status('on-hold');
        });

        $this->stubApi('/api/v3/subscriptions/sub-refused-hold/cancel', ['error' => 'upstream'], 500);

        wcs_get_subscription($subscription->get_id())->update_status('cancelled');

        self::assertSame('on-hold', wcs_get_subscription($subscription->get_id())->get_status());
    }

    /** Another gateway's subscriptions must never be sent to Confirmo. */
    public function testASubscriptionThatIsNotOursIsLeftAlone(): void
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);
        $subscription->set_payment_method('some_other_gateway');
        $subscription->save();
        $order->payment_complete();

        $subscription->update_status('cancelled');

        self::assertSame([], $this->httpCalls, 'nothing should have been sent anywhere');
    }

    /**
     * An event being applied must not bounce straight back out as an outbound
     * cancel, or a cancellation made at Confirmo would be echoed to Confirmo.
     */
    public function testACancellationProjectedFromConfirmoIsNotSentBack(): void
    {
        $subscription = $this->activeSubscription('sub-inbound');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.canceled',
            ['id' => 'sub-inbound', 'status' => 'CANCELED'],
            ['type' => 'subscription.canceled']
        );

        self::assertNull($this->requestTo('/api/v3/subscriptions/sub-inbound/cancel'));
        self::assertSame('cancelled', wcs_get_subscription($subscription->get_id())->get_status());
    }

    private function activeSubscription(string $confirmoId): WC_Subscription
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);

        WC_Confirmo_Subscribe_Link::link($subscription, $confirmoId);
        $order->payment_complete();

        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription) {
            $subscription->update_status('active');
        });

        return wcs_get_subscription($subscription->get_id());
    }
}
