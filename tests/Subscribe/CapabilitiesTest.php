<?php

/**
 * The one flag the whole design rests on.
 *
 * `gateway_scheduled_payments` is what stops WooCommerce Subscriptions driving
 * the billing cycle itself. Lose it and WCS starts creating its own renewal
 * orders and putting subscriptions on hold on its own clock, alongside the ones
 * Confirmo drives — double billing records, and customers on hold while Confirmo
 * is charging them happily.
 */
class CapabilitiesTest extends SubscribeTestCase
{
    public function testTheGatewayOwnsTheBillingClock(): void
    {
        $subscription = $this->ourSubscription();

        self::assertTrue(
            $subscription->payment_method_supports('gateway_scheduled_payments'),
            'WooCommerce Subscriptions must see that Confirmo schedules the payments'
        );
    }

    /**
     * WCS treats a manual-renewal subscription as supporting every gateway
     * feature, and a merchant can switch manual renewals on at any time — which
     * would hand renewals back to WCS without anyone touching code.
     */
    public function testOurSubscriptionsAreNeverManual(): void
    {
        $subscription = $this->ourSubscription();

        self::assertFalse($subscription->is_manual());
    }

    /**
     * Suspension has no Confirmo equivalent, so nobody may request it — but the
     * projection has to be able to perform it when Confirmo reports past due.
     */
    public function testSuspensionIsOnlyAvailableWhileProjecting(): void
    {
        $subscription = $this->ourSubscription();

        self::assertFalse(
            $subscription->payment_method_supports('subscription_suspension'),
            'an admin or subscriber must not be able to suspend'
        );

        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription) {
            self::assertTrue(
                $subscription->payment_method_supports('subscription_suspension'),
                'the projection must be able to put it on hold'
            );
        });

        self::assertFalse(
            $subscription->payment_method_supports('subscription_suspension'),
            'the window must close again'
        );
    }

    /**
     * Confirmo runs its own retry ladder, so WooCommerce must not run a second
     * one on top of it. The store-wide switch would have disabled retries for
     * every other gateway too, so this is decided per renewal order.
     */
    public function testWooCommerceDoesNotRetryOurRenewals(): void
    {
        $subscription = $this->ourSubscription();

        $renewal = wcs_create_renewal_order($subscription);
        self::assertNotWPError($renewal);

        self::assertNull(
            apply_filters('wcs_get_retry_rule', 'some-rule', 1, $renewal->get_id()),
            'no retry rule may apply to a renewal Confirmo is retrying itself'
        );
    }

    /**
     * Another gateway's subscriptions must be left exactly as WooCommerce found
     * them. Asserted on the filter, because WCS reports an unregistered payment
     * method as manual renewal, which claims every capability by itself.
     */
    public function testAnotherGatewaysSubscriptionIsUnaffected(): void
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);
        $subscription->set_payment_method('some_other_gateway');
        $subscription->save();

        foreach (['gateway_scheduled_payments', 'subscription_cancellation', 'subscription_suspension'] as $feature) {
            self::assertFalse(
                apply_filters('woocommerce_subscription_payment_gateway_supports', false, $feature, $subscription),
                $feature . ' must not be claimed for another gateway'
            );
            self::assertTrue(
                apply_filters('woocommerce_subscription_payment_gateway_supports', true, $feature, $subscription),
                $feature . ' must not be taken away from another gateway'
            );
        }

        self::assertTrue(
            apply_filters('woocommerce_subscription_is_manual', true, $subscription),
            'manual renewal must be left as WooCommerce set it'
        );
    }

    private static function assertNotWPError($value): void
    {
        self::assertFalse(is_wp_error($value), is_wp_error($value) ? $value->get_error_message() : '');
    }

    private function ourSubscription(): WC_Subscription
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);

        WC_Confirmo_Subscribe_Link::link($subscription, 'sub-caps');
        $order->payment_complete();

        return wcs_get_subscription($subscription->get_id());
    }
}
