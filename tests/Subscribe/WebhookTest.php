<?php

/**
 * The webhook endpoint is the only way Confirmo can move a subscription, and the
 * signature check is the only thing between a stranger's POST and a customer's
 * billing state. Both halves are covered here: that a real signature verifies,
 * and that the guards around applying an event hold.
 *
 * `handle()` itself ends every path in `exit`, which a test cannot survive, so
 * verification is exercised through `Signature` and event handling through
 * `process()`. Between them every line of the request path is covered except the
 * `exit` itself.
 */
class WebhookTest extends SubscribeTestCase
{
    public function testARealSignatureVerifiesAgainstThePublishedKey(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $headers = $this->signWebhook('evt-1', $body);

        self::assertTrue($this->verify($headers, $body), 'a correctly signed event must verify');
    }

    public function testATamperedBodyDoesNotVerify(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $headers = $this->signWebhook('evt-1', $body);

        $tampered = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'someone-elses-sub']);

        self::assertFalse($this->verify($headers, $tampered));
    }

    public function testAnEventSignedWithTheWrongKeyDoesNotVerify(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $this->signWebhook('evt-1', $body);

        $stranger = sodium_crypto_sign_keypair();
        $signature = sodium_crypto_sign_detached('evt-1.' . time() . '.' . $body, sodium_crypto_sign_secretkey($stranger));

        self::assertFalse($this->verify([
            'webhook-id' => 'evt-1',
            'webhook-timestamp' => (string) time(),
            'webhook-signature' => 'v1a,' . base64_encode($signature),
        ], $body));
    }

    /**
     * A redelivery must not be applied twice. For a payment event that would mean
     * a second renewal order for one charge.
     */
    public function testTheSameEventIdIsAppliedOnlyOnce(): void
    {
        list($subscription) = $this->linkedSubscription('sub-dedupe');

        $this->stubApi('/api/v3/subscriptions/sub-dedupe', $this->confirmoSubscription());

        $event = ['type' => 'subscription.past_due', 'resourceId' => 'sub-dedupe', 'sequence' => 5];

        $this->process($event, 'evt-repeat');
        self::assertSame('on-hold', wcs_get_subscription($subscription->get_id())->get_status());

        // Put it back, then replay: a second application would move it again.
        // Re-read first — saving the stale object would write back its copy of the
        // meta and wipe the record of the event just applied.
        $fresh = wcs_get_subscription($subscription->get_id());
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($fresh) {
            $fresh->update_status('active');
        });

        $this->process($event, 'evt-repeat');

        self::assertSame(
            'active',
            wcs_get_subscription($subscription->get_id())->get_status(),
            'the replay should have been dropped on the event id'
        );
    }

    /** Events overtaken in flight must not undo a newer one. */
    public function testAnOlderSequenceIsIgnored(): void
    {
        list($subscription) = $this->linkedSubscription('sub-seq');
        $subscription->update_meta_data(WC_Confirmo_Subscribe_Link::META_LAST_SEQUENCE, 10);
        $subscription->save();

        $this->stubApi('/api/v3/subscriptions/sub-seq', $this->confirmoSubscription());

        $this->process(['type' => 'subscription.past_due', 'resourceId' => 'sub-seq', 'sequence' => 4], 'evt-old');

        self::assertNotSame(
            'on-hold',
            wcs_get_subscription($subscription->get_id())->get_status(),
            'a stale event must not be applied'
        );
    }

    /** Nothing has been applied yet is not the same as "sequence 0 already seen". */
    public function testAFirstEventNumberedZeroIsApplied(): void
    {
        list($subscription) = $this->linkedSubscription('sub-zero');

        $this->stubApi('/api/v3/subscriptions/sub-zero', $this->confirmoSubscription());

        $this->process(['type' => 'subscription.past_due', 'resourceId' => 'sub-zero', 'sequence' => 0], 'evt-zero');

        self::assertSame('on-hold', wcs_get_subscription($subscription->get_id())->get_status());
    }

    /**
     * Whatever it does about redelivery, an event for a subscription this store
     * does not hold must not go asking Confirmo about it.
     */
    public function testAnEventForAnUnknownSubscriptionIsNotLookedUp(): void
    {
        try {
            $this->process(
                ['type' => 'subscription.canceled', 'resourceId' => 'never-heard-of-it', 'sequence' => 1],
                'evt-unknown'
            );
        } catch (ConfirmoWpDie $e) {
            // Asking for a redelivery is the expected answer; see
            // WebhookEndpointTest for the status codes.
        }

        self::assertNull($this->requestTo('/api/v3/subscriptions/never-heard-of-it'));
    }

    /**
     * A signature stays valid however long the timestamp sits from our clock, so
     * a host whose clock has drifted must still verify its events. The tolerance
     * only bounds how ancient a captured message may be; the sequence number is
     * what stops a replay being applied.
     */
    public function testAnEventSignedOnAClockAnHourOutStillVerifies(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $headers = $this->signWebhook('evt-skew', $body, time() - 3600);

        self::assertTrue($this->verify($headers, $body));
        self::assertLessThan(
            WC_Confirmo_Subscribe_Webhook::TIMESTAMP_TOLERANCE,
            3600,
            'an hour of clock drift must be inside the tolerance, or genuine events are refused'
        );
    }

    /** Past the tolerance it is refused — but retryably, never for good. */
    public function testTheToleranceOutlastsTheDispatchersRetryLadder(): void
    {
        // The ladder runs to roughly a day; a retry must never be refused for
        // arriving late, because a refusal the dispatcher reads as permanent
        // costs the store the event and the merchant a cycle of revenue.
        self::assertGreaterThanOrEqual(
            24 * 3600,
            WC_Confirmo_Subscribe_Webhook::TIMESTAMP_TOLERANCE,
            'the tolerance must cover the whole retry ladder'
        );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function verify(array $headers, string $body): bool
    {
        $keys = WC_Confirmo_Subscribe_Signature::publicKeys();
        self::assertNotEmpty($keys, 'the JWKS stub should have produced a key');

        return WC_Confirmo_Subscribe_Signature::verify(
            $headers['webhook-id'],
            $headers['webhook-timestamp'],
            $body,
            $headers['webhook-signature'],
            $keys
        );
    }

    /** @return array{0: WC_Subscription, 1: WC_Order} */
    private function linkedSubscription(string $confirmoId): array
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);

        WC_Confirmo_Subscribe_Link::link($subscription, $confirmoId);
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription) {
            $subscription->update_status('active');
        });

        return [$subscription, $order];
    }

    private function confirmoSubscription(array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub-1',
            'status' => 'ACTIVE',
            'nextPaymentDate' => time() + 2592000,
        ], $overrides);
    }

    /** `process()` is private; it is the whole of the request path bar the exit. */
    private function process(array $event, string $eventId): void
    {
        $method = new ReflectionMethod(WC_Confirmo_Subscribe_Webhook::class, 'process');
        $method->setAccessible(true);
        $method->invoke(null, $event, $eventId);
    }
}
