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

    public function testABodyThatIsNotJsonIsRefused(): void
    {
        $body = 'not json at all';

        self::assertSame(400, $this->postWebhook($body, $this->signWebhook('evt-junk', $body)));
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
