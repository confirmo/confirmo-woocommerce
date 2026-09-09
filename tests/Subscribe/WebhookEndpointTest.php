<?php

/**
 * The endpoint as a Confirmo delivery meets it: the URL routing to the handler,
 * and every answer the handler gives.
 *
 * None of this was coverable while the handler ended in a bare `exit` — and the
 * gap that mattered most was the routing. If the webhook URL answered 404 on a
 * real store, no payment would ever be recorded and the whole suite would still
 * be green, because everything else calls the handler directly.
 *
 * Which status code comes back decides whether Confirmo tries again: it treats
 * 4xx as final and 5xx as worth retrying. A wrong choice here either loses an
 * event for good or asks for a redelivery that can never succeed.
 */
class WebhookEndpointTest extends SubscribeTestCase
{
    public function testTheWebhookUrlReachesTheHandlerRatherThanA404(): void
    {
        list($url, $headers) = $this->loopbackRequestTo(WC_Confirmo_Subscribe_Gateway::notifyUrl());

        // Unsigned, so the handler must refuse it — but a refusal is the proof:
        // it means WordPress routed the request to us at all.
        $response = wp_remote_post($url, ['timeout' => 15, 'body' => '{}', 'headers' => $headers]);

        self::assertFalse(is_wp_error($response), is_wp_error($response) ? $response->get_error_message() : '');

        // Asserted as "must be 400", not "must not be 404": with the query
        // variable unregistered WordPress drops the parameter and cheerfully
        // serves the home page with a 200, so only the positive catches it.
        self::assertSame(
            400,
            (int) wp_remote_retrieve_response_code($response),
            'the webhook URL did not reach the handler — an unsigned delivery must be refused, not answered'
        );
    }

    public function testTheQueryVariableIsRegisteredSoWordPressPassesItThrough(): void
    {
        self::assertContains(
            WC_Confirmo_Subscribe_Webhook::QUERY_VAR,
            apply_filters('query_vars', []),
            'without this WordPress drops the parameter and the handler never runs'
        );
    }

    public function testADeliveryWithNoSignatureHeadersIsRefused(): void
    {
        self::assertSame(400, $this->postWebhook('{}', []));
    }

    public function testADeliveryMissingOnlyTheSignatureIsRefused(): void
    {
        self::assertSame(400, $this->postWebhook('{}', [
            'webhook-id' => 'evt-1',
            'webhook-timestamp' => (string) time(),
        ]));
    }

    /**
     * Retryable, not final: the likely cause is this server's own clock, and a
     * final refusal would lose real events over it.
     */
    public function testATimestampOutsideToleranceIsRefusedRetryably(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $stale = time() - (WC_Confirmo_Subscribe_Webhook::TIMESTAMP_TOLERANCE + 3600);

        self::assertSame(503, $this->postWebhook($body, $this->signWebhook('evt-stale', $body, $stale)));
    }

    public function testASignatureThatDoesNotMatchIsRefused(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $headers = $this->signWebhook('evt-bad', $body);
        $headers['webhook-signature'] = 'v1a,' . base64_encode(random_bytes(64));

        self::assertSame(400, $this->postWebhook($body, $headers));
    }

    /**
     * Failing to read the signing keys is our problem, not a bad signature, and
     * the two must not share an answer — a final refusal over a blip fetching
     * the JWKS would cost the store a real event.
     */
    public function testAnUnreadableJwksIsAnswered503RatherThan400(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $headers = $this->signWebhook('evt-nokeys', $body);

        // Replace the stub the signing set up with a JWKS carrying no usable key.
        delete_transient(WC_Confirmo_Subscribe_Signature::JWKS_TRANSIENT);
        $this->stubApi('/.well-known/jwks.json', ['keys' => []]);

        self::assertSame(503, $this->postWebhook($body, $headers));
    }

    /**
     * Confirmo rotates its signing key. While the old one was cached, every
     * delivery signed with the new key failed — and a failed signature is
     * answered 400, which the dispatcher treats as permanent, so a rotation cost
     * the store every event in that window.
     */
    public function testAnEventSignedWithARotatedKeyIsAcceptedRatherThanRefused(): void
    {
        // Warm the cache with the first key, as a live store would have it.
        $stale = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $this->signWebhook('evt-warm', $stale);
        self::assertNotEmpty(WC_Confirmo_Subscribe_Signature::publicKeys(), 'the first key should be cached');

        // Confirmo rotates: a new keypair, published at the same JWKS.
        $rotated = sodium_crypto_sign_keypair();
        $this->stubApi('/.well-known/jwks.json', [
            'keys' => [[
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => rtrim(strtr(base64_encode(sodium_crypto_sign_publickey($rotated)), '+/', '-_'), '='),
            ]],
        ]);

        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);
        WC_Confirmo_Subscribe_Link::link($subscription, 'sub-rotated');
        $order->payment_complete();
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription) {
            $subscription->update_status('active');
        });

        $this->stubApi('/api/v3/subscriptions/sub-rotated', [
            'id' => 'sub-rotated',
            'status' => 'PAST_DUE',
            'cycleNumber' => 1,
        ]);

        $body = wp_json_encode([
            'type' => 'subscription.past_due',
            'resourceId' => 'sub-rotated',
            'sequence' => 2,
        ]);
        $now = time();
        $signature = sodium_crypto_sign_detached(
            'evt-rotated.' . $now . '.' . $body,
            sodium_crypto_sign_secretkey($rotated)
        );

        $status = $this->postWebhook($body, [
            'webhook-id' => 'evt-rotated',
            'webhook-timestamp' => (string) $now,
            'webhook-signature' => 'v1a,' . base64_encode($signature),
        ]);

        self::assertSame(200, $status, 'a rotated key must be picked up rather than the event refused');
        self::assertSame('on-hold', wcs_get_subscription($subscription->get_id())->get_status());
    }

    /** A stranger's signature must still be refused after the re-read. */
    public function testARotationRetryDoesNotAcceptAStrangersSignature(): void
    {
        $body = wp_json_encode(['type' => 'subscription.activated', 'resourceId' => 'sub-1']);
        $this->signWebhook('evt-warm2', $body);

        $stranger = sodium_crypto_sign_keypair();
        $now = time();
        $signature = sodium_crypto_sign_detached(
            'evt-stranger.' . $now . '.' . $body,
            sodium_crypto_sign_secretkey($stranger)
        );

        self::assertSame(400, $this->postWebhook($body, [
            'webhook-id' => 'evt-stranger',
            'webhook-timestamp' => (string) $now,
            'webhook-signature' => 'v1a,' . base64_encode($signature),
        ]));
    }

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $body = 'not json at all';

        self::assertSame(400, $this->postWebhook($body, $this->signWebhook('evt-junk', $body)));
    }

    /**
     * `notifyUrl` goes to Confirmo before the returned id is written against the
     * WooCommerce subscription, so an event minted in that gap arrives before
     * there is anything to apply it to. Asking for a redelivery is what makes
     * that self-correcting; answering 200 threw away the first event of a
     * subscription's life.
     */
    public function testAFreshEventForAnUnlinkedSubscriptionAsksForARedelivery(): void
    {
        $body = wp_json_encode([
            'type' => 'subscription.created',
            'resourceId' => 'sub-not-linked-yet',
            'sequence' => 1,
            'timestamp' => gmdate('c'),
        ]);

        self::assertSame(503, $this->postWebhook($body, $this->signWebhook('evt-early', $body)));
    }

    /**
     * Past that window it is not a checkout in progress: the subscription was
     * deleted here, or another store is using this notification URL. Retrying
     * only earns a dead-letter entry per attempt, so it is accepted and logged.
     */
    public function testAnOldEventForAnUnknownSubscriptionIsAcceptedRatherThanRetriedForever(): void
    {
        $body = wp_json_encode([
            'type' => 'subscription.canceled',
            'resourceId' => 'sub-long-gone',
            'sequence' => 1,
            'timestamp' => gmdate('c', time() - (WC_Confirmo_Subscribe_Webhook::UNKNOWN_SUBSCRIPTION_RETRY_WINDOW + 3600)),
        ]);

        self::assertSame(200, $this->postWebhook($body, $this->signWebhook('evt-gone', $body)));
    }

    /** No usable timestamp means we cannot tell the two apart, so assume the race. */
    public function testAnEventWithNoTimestampAsksForARedelivery(): void
    {
        $body = wp_json_encode([
            'type' => 'subscription.created',
            'resourceId' => 'sub-no-timestamp',
            'sequence' => 1,
        ]);

        self::assertSame(503, $this->postWebhook($body, $this->signWebhook('evt-nots', $body)));
    }

    /** The whole path, end to end: signed delivery in, subscription moved. */
    public function testASignedDeliveryIsAcceptedAndApplied(): void
    {
        $product = $this->makeSubscriptionProduct('29.00', 'plan-monthly');
        list($order, $subscription) = $this->makeSubscriptionOrder($product);
        WC_Confirmo_Subscribe_Link::link($subscription, 'sub-endpoint');
        $order->payment_complete();
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription) {
            $subscription->update_status('active');
        });

        $this->stubApi('/api/v3/subscriptions/sub-endpoint', [
            'id' => 'sub-endpoint',
            'status' => 'PAST_DUE',
            'cycleNumber' => 1,
        ]);

        $body = wp_json_encode([
            'type' => 'subscription.past_due',
            'resourceId' => 'sub-endpoint',
            'sequence' => 3,
        ]);

        self::assertSame(200, $this->postWebhook($body, $this->signWebhook('evt-live', $body)));
        self::assertSame('on-hold', wcs_get_subscription($subscription->get_id())->get_status());
    }
}
