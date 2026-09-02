<?php

/**
 * What a Confirmo event does to the WooCommerce subscription.
 *
 * With `gateway_scheduled_payments` declared, WooCommerce Subscriptions stops
 * driving the billing cycle: it neither creates renewal orders nor puts the
 * subscription on hold when its own clock says a payment is due. Everything it
 * would have done happens here instead. If this stops working, renewals simply
 * stop appearing and nobody finds out until a customer complains.
 */
class ProjectionTest extends ConfirmoTestCase
{
    public function testActivationPaysTheParentOrderAndStartsTheSubscription(): void
    {
        list($subscription, $order) = $this->linked();

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.activated',
            ['id' => 'sub-1', 'status' => 'ACTIVE', 'nextPaymentDate' => time() + 2592000],
            ['type' => 'subscription.activated']
        );

        self::assertSame('active', wcs_get_subscription($subscription->get_id())->get_status());
        self::assertTrue(wc_get_order($order->get_id())->is_paid(), 'the first cycle is the parent order');
    }

    /**
     * Cycle one is already represented by the parent order, so minting a renewal
     * order for it would count the same charge twice.
     */
    public function testTheFirstPaymentDoesNotCreateARenewalOrder(): void
    {
        list($subscription) = $this->linked();
        $before = count($subscription->get_related_orders());

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.payment.succeeded',
            ['id' => 'sub-1', 'status' => 'ACTIVE'],
            ['type' => 'subscription.payment.succeeded', 'data' => ['id' => 'pay-1', 'cycleNumber' => 1]]
        );

        self::assertCount($before, wcs_get_subscription($subscription->get_id())->get_related_orders());
    }

    /** Every later cycle needs its own paid renewal order, or the store has no record of the money. */
    public function testALaterPaymentCreatesAPaidRenewalOrder(): void
    {
        list($subscription) = $this->linked('active');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.payment.succeeded',
            ['id' => 'sub-1', 'status' => 'ACTIVE'],
            ['type' => 'subscription.payment.succeeded', 'data' => ['id' => 'pay-2', 'cycleNumber' => 2]]
        );

        $renewal = $this->renewalWithTransaction($subscription, 'pay-2');

        self::assertNotNull($renewal, 'cycle two must produce a renewal order');
        self::assertTrue($renewal->is_paid());
        self::assertEquals(
            (float) $subscription->get_total(),
            (float) $renewal->get_total(),
            'the renewal must bill what the subscription bills'
        );
    }

    /** A redelivery must not mint a second order for one charge. */
    public function testTheSamePaymentIsNotRecordedTwice(): void
    {
        list($subscription) = $this->linked('active');

        $event = ['type' => 'subscription.payment.succeeded', 'data' => ['id' => 'pay-3', 'cycleNumber' => 3]];
        $confirmo = ['id' => 'sub-1', 'status' => 'ACTIVE'];

        WC_Confirmo_Subscribe_Projection::apply($subscription, 'subscription.payment.succeeded', $confirmo, $event);
        WC_Confirmo_Subscribe_Projection::apply(
            wcs_get_subscription($subscription->get_id()),
            'subscription.payment.succeeded',
            $confirmo,
            $event
        );

        $matching = 0;
        foreach (wcs_get_subscription($subscription->get_id())->get_related_orders('all') as $order) {
            if ($order instanceof WC_Order && $order->get_transaction_id() === 'pay-3') {
                $matching++;
            }
        }

        self::assertSame(1, $matching);
    }

    public function testPastDuePutsTheSubscriptionOnHold(): void
    {
        list($subscription) = $this->linked('active');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.past_due',
            ['id' => 'sub-1', 'status' => 'PAST_DUE'],
            ['type' => 'subscription.past_due']
        );

        self::assertSame('on-hold', wcs_get_subscription($subscription->get_id())->get_status());
    }

    public function testResumingBringsItBackFromOnHold(): void
    {
        list($subscription) = $this->linked('on-hold');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.resumed',
            ['id' => 'sub-1', 'status' => 'ACTIVE'],
            ['type' => 'subscription.resumed']
        );

        self::assertSame('active', wcs_get_subscription($subscription->get_id())->get_status());
    }

    public function testCancellationAtConfirmoCancelsInWooCommerce(): void
    {
        list($subscription) = $this->linked('active');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.canceled',
            ['id' => 'sub-1', 'status' => 'CANCELED'],
            ['type' => 'subscription.canceled']
        );

        self::assertSame('cancelled', wcs_get_subscription($subscription->get_id())->get_status());
    }

    /**
     * The next payment date is what the customer sees in My Account and what the
     * store reports on. Confirmo owns it, so every event carries it across.
     */
    public function testTheNextPaymentDateFollowsConfirmo(): void
    {
        list($subscription) = $this->linked('active');
        $due = time() + 604800;

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.payment.succeeded',
            ['id' => 'sub-1', 'status' => 'ACTIVE', 'nextPaymentDate' => $due],
            ['type' => 'subscription.payment.succeeded', 'data' => ['id' => 'pay-4', 'cycleNumber' => 4]]
        );

        self::assertSame(
            gmdate('Y-m-d H:i:s', $due),
            wcs_get_subscription($subscription->get_id())->get_date('next_payment')
        );
    }

    /**
     * An event that does not report a date must not be read as clearing it —
     * that wiped the end date WooCommerce Subscriptions writes itself.
     */
    public function testAnEventWithoutDatesLeavesTheExistingOnesAlone(): void
    {
        list($subscription) = $this->linked('active');
        $due = time() + 604800;
        $subscription->update_dates(['next_payment' => gmdate('Y-m-d H:i:s', $due)]);
        $subscription->save();

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.payment.failed',
            ['id' => 'sub-1', 'status' => 'PAST_DUE'],
            ['type' => 'subscription.payment.failed', 'data' => []]
        );

        self::assertSame(
            gmdate('Y-m-d H:i:s', $due),
            wcs_get_subscription($subscription->get_id())->get_date('next_payment')
        );
    }

    /**
     * A date this build cannot read is a contract change, not an instruction to
     * clear it. Clearing emptied the customer's next payment date on every
     * webhook and looked exactly like Confirmo having cleared it.
     */
    public function testADateInAnUnreadableFormatIsIgnoredRatherThanCleared(): void
    {
        list($subscription) = $this->linked('active');
        $due = time() + 604800;
        $subscription->update_dates(['next_payment' => gmdate('Y-m-d H:i:s', $due)]);
        $subscription->save();

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.past_due',
            ['id' => 'sub-1', 'status' => 'PAST_DUE', 'nextPaymentDate' => '2026-09-20T08:19:00Z'],
            ['type' => 'subscription.past_due']
        );

        self::assertSame(
            gmdate('Y-m-d H:i:s', $due),
            wcs_get_subscription($subscription->get_id())->get_date('next_payment')
        );
    }

    /** An explicit null is Confirmo saying there is no further charge. */
    public function testAnExplicitNullClearsTheDate(): void
    {
        list($subscription) = $this->linked('active');
        $subscription->update_dates(['next_payment' => gmdate('Y-m-d H:i:s', time() + 604800)]);
        $subscription->save();

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.past_due',
            ['id' => 'sub-1', 'status' => 'PAST_DUE', 'nextPaymentDate' => null],
            ['type' => 'subscription.past_due']
        );

        self::assertEmpty(
            wcs_get_subscription($subscription->get_id())->get_date('next_payment'),
            'a cleared date reads back as 0 in WCS'
        );
    }

    /**
     * WCS validates the whole set and throws before writing any of it, so an end
     * date that collides with the next payment used to drop both — and stop the
     * subscription tracking Confirmo at all.
     */
    public function testOneUnacceptableDateDoesNotDropTheOther(): void
    {
        list($subscription) = $this->linked('active');
        $due = time() + 604800;

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.past_due',
            ['id' => 'sub-1', 'status' => 'PAST_DUE', 'nextPaymentDate' => $due, 'endsAt' => $due - 86400],
            ['type' => 'subscription.past_due']
        );

        self::assertSame(
            gmdate('Y-m-d H:i:s', $due),
            wcs_get_subscription($subscription->get_id())->get_date('next_payment'),
            'the next payment date must still have been written'
        );
    }

    /**
     * The cycle is required on the subscription itself, so a payment event that
     * omits it can still be placed rather than discarded.
     */
    public function testAPaymentEventWithoutACycleFallsBackToTheSubscription(): void
    {
        list($subscription) = $this->linked('active');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.payment.succeeded',
            ['id' => 'sub-1', 'status' => 'ACTIVE', 'cycleNumber' => 5],
            ['type' => 'subscription.payment.succeeded', 'data' => ['id' => 'pay-5']]
        );

        $renewal = $this->renewalWithTransaction($subscription, 'pay-5');

        self::assertNotNull($renewal, 'the subscription said cycle five, so a renewal order belongs to it');
        self::assertTrue($renewal->is_paid());
    }

    /** A charge that no longer matches the order has to be visible, not silent. */
    public function testARenewalNotesWhenConfirmoChargedADifferentAmount(): void
    {
        list($subscription) = $this->linked('active');

        WC_Confirmo_Subscribe_Projection::apply(
            $subscription,
            'subscription.payment.succeeded',
            ['id' => 'sub-1', 'status' => 'ACTIVE', 'amount' => '99.00'],
            ['type' => 'subscription.payment.succeeded', 'data' => ['id' => 'pay-6', 'cycleNumber' => 6]]
        );

        $renewal = $this->renewalWithTransaction($subscription, 'pay-6');
        self::assertNotNull($renewal);

        $notes = array_map(
            static function ($note) {
                return $note->content;
            },
            wc_get_order_notes(['order_id' => $renewal->get_id()])
        );

        self::assertNotEmpty(
            preg_grep('/does not match this order total/', $notes),
            'the drift between what Confirmo charged and what the order records must be noted'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * A subscription linked to Confirmo, in the given status.
     *
     * @return array{0: WC_Subscription, 1: WC_Order}
     */
    private function linked(?string $status = null): array
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);

        WC_Confirmo_Subscribe_Link::link($subscription, 'sub-1');

        if ($status !== null) {
            $order->payment_complete();

            // WCS only allows on-hold from active, so walk the same path a real
            // subscription takes rather than jumping to the end state.
            $path = $status === 'on-hold' ? ['active', 'on-hold'] : [$status];

            WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription, $path) {
                foreach ($path as $step) {
                    if (!$subscription->has_status($step)) {
                        $subscription->update_status($step);
                    }
                }
            });
        }

        return [wcs_get_subscription($subscription->get_id()), $order];
    }

    private function renewalWithTransaction(WC_Subscription $subscription, string $paymentId): ?WC_Order
    {
        foreach (wcs_get_subscription($subscription->get_id())->get_related_orders('all', 'renewal') as $order) {
            if ($order instanceof WC_Order && $order->get_transaction_id() === $paymentId) {
                return $order;
            }
        }

        return null;
    }
}
