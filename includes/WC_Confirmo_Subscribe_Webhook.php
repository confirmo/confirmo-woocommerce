<?php

/**
 * Standard Webhooks v1a / Ed25519. Deliberately a separate endpoint from the
 * Checkout gateway's callback, which uses the legacy SHA-256 callback-password
 * scheme — mixing the two in one handler is the obvious footgun.
 */
class WC_Confirmo_Subscribe_Webhook
{
    const QUERY_VAR = 'confirmo-subscribe-webhook';
    const META_SEEN_EVENTS = '_confirmo_seen_event_ids';

    /**
     * Redeliveries arrive close behind the original, so this only has to outlast
     * a retry ladder — it is not an audit trail.
     */
    const SEEN_EVENTS_KEPT = 50;

    /**
     * How far a signed timestamp may sit from this server's clock.
     *
     * Deliberately generous. The event id and the sequence number are what
     * actually stop a replayed event being applied — an old one carries an old
     * sequence and is dropped whatever its timestamp says. This is the backstop
     * that bounds how ancient a captured message may be, and a tight window here
     * bought no security while turning an ordinary problem into a silent one: a
     * WordPress host whose clock drifts by minutes rejected every genuine event,
     * and the merchant's only symptom was payments never being recorded.
     *
     * Set past the dispatcher's retry ladder so a retry is never refused for
     * being late either.
     */
    const TIMESTAMP_TOLERANCE = 86400;

    /**
     * How long an event for a subscription this store does not hold is worth
     * redelivering. Long enough to outlast a checkout still being linked, short
     * enough that a subscription genuinely gone from here stops being retried.
     */
    const UNKNOWN_SUBSCRIPTION_RETRY_WINDOW = 1800;

    public static function register(): void
    {
        add_filter('query_vars', [self::class, 'addQueryVar']);
        add_action('template_redirect', [self::class, 'handle']);
    }

    public static function addQueryVar(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public static function handle(): void
    {
        if (!get_query_var(self::QUERY_VAR)) {
            return;
        }

        $headers = self::headers();
        $body = file_get_contents('php://input');

        $id = $headers['webhook-id'] ?? '';
        $timestamp = $headers['webhook-timestamp'] ?? '';
        $signature = $headers['webhook-signature'] ?? '';

        if ($id === '' || $timestamp === '' || $signature === '') {
            self::respond(400, 'missing signature headers');
        }

        $skew = abs(time() - (int) $timestamp);
        if ($skew > self::TIMESTAMP_TOLERANCE) {
            // Said plainly, because the cause is almost always this server's own
            // clock and the symptom a merchant sees is payments never arriving.
            WC_Confirmo_Subscribe_Log::error(sprintf(
                'rejected event %s: its timestamp is %d minutes from this server\'s clock, outside the %d hour tolerance. '
                . 'If Confirmo payments are not being recorded, check this server\'s clock (NTP) first.',
                $id,
                (int) round($skew / 60),
                (int) (self::TIMESTAMP_TOLERANCE / 3600)
            ));
            // Retryable, not final. A 4xx has the dispatcher drop the event for
            // good, so a clock briefly out of step used to cost the store real
            // events — a lost payment event is a cycle of revenue.
            self::respond(503, 'timestamp outside tolerance; retry');
        }

        // Failing to read the signing keys is our problem, not a bad signature,
        // and the two must not share an answer: a 400 has the dispatcher drop the
        // event for good, so a blip fetching the JWKS would cost the store a real
        // event — and a lost payment event is a cycle of revenue.
        $keys = WC_Confirmo_Subscribe_Signature::publicKeys();
        if (empty($keys)) {
            self::respond(503, 'could not load signing keys; retry');
        }

        if (!WC_Confirmo_Subscribe_Signature::verify($id, $timestamp, $body, $signature, $keys)) {
            WC_Confirmo_Subscribe_Log::error('rejected event ' . $id . ': no signature matched the published keys');
            self::respond(400, 'signature verification failed');
        }

        $event = json_decode($body, true);
        if (!is_array($event)) {
            self::respond(400, 'invalid body');
        }

        self::process($event, (string) $id);

        self::respond(200, 'ok');
    }

    private static function process(array $event, string $eventId): void
    {
        $type = (string) ($event['type'] ?? '');
        $resourceId = (string) ($event['resourceId'] ?? '');
        $hasSequence = isset($event['sequence']) && is_numeric($event['sequence']);
        $sequence = $hasSequence ? (int) $event['sequence'] : 0;

        if ($resourceId === '') {
            WC_Confirmo_Subscribe_Log::error('event ' . $eventId . ' carries no resourceId; nothing to apply it to');
            return;
        }

        $subscription = WC_Confirmo_Subscribe_Link::forConfirmoId($resourceId);
        if (!$subscription) {
            self::refuseUnknownSubscription($event, $eventId, $type, $resourceId);
            return;
        }

        // Standard Webhooks reuses `webhook-id` when it resends, and that header
        // is part of the signed message, so a duplicate cannot be forged.
        if (self::alreadyApplied($subscription, $eventId)) {
            return;
        }

        // Discards stale replays and events overtaken in flight. Kept on the
        // subscription, not an order, because a subscription owns many orders.
        //
        // Only when there *is* a sequence: both sides defaulted to 0, so
        // `0 <= 0` swallowed every event of every subscription if the envelope
        // ever stopped carrying the field. The identity gate above still covers
        // redelivery, so fall back to it and log rather than dropping mail.
        if ($hasSequence) {
            // Absent meta is "nothing applied yet", which is not the same as
            // sequence 0 — reading it as 0 discarded a genuine first event
            // numbered zero, and recorded it applied so the redelivery went too.
            $lastSequence = $subscription->meta_exists(WC_Confirmo_Subscribe_Link::META_LAST_SEQUENCE)
                ? (int) $subscription->get_meta(WC_Confirmo_Subscribe_Link::META_LAST_SEQUENCE)
                : null;

            if ($lastSequence !== null && $sequence <= $lastSequence) {
                self::recordApplied($subscription, $eventId);
                return;
            }
        } else {
            WC_Confirmo_Subscribe_Log::error('event ' . $eventId . ' (' . $type . ') carries no sequence; applying on the identity gate alone');
        }

        // Re-fetch rather than trusting the payload. Nothing is recorded on this
        // path, so the retry is processed rather than deduped.
        $api = new WC_Confirmo_Subscribe_Api();
        $confirmo = $api->getSubscription($resourceId);
        if (is_wp_error($confirmo)) {
            WC_Confirmo_Subscribe_Log::error('could not re-read subscription ' . $resourceId . ': ' . $confirmo->get_error_message());
            self::respond(503, 'could not load subscription; retry');
        }

        // Cycle 1 is the parent order and later cycles need a renewal order
        // minted, so a payment with no cycle anywhere cannot be placed. The rest
        // of the event still applies: a 503 here asked for a redelivery that
        // would never carry the field, and threw away the status and dates the
        // same event was carrying.
        if ($type === 'subscription.payment.succeeded' && !self::hasCycle($event, $confirmo)) {
            WC_Confirmo_Subscribe_Log::error('payment event ' . $eventId . ' for ' . $resourceId . ' has no cycleNumber; recording the rest of the event and leaving the payment for reconciliation');
            $subscription->add_order_note(__('Confirmo reported a successful payment without a cycle number, so no renewal order was created for it. Check the payment in Confirmo.', 'confirmo-for-woocommerce'));
            $type = '';
        }

        WC_Confirmo_Subscribe_Projection::apply($subscription, $type, $confirmo, $event);
        self::fireEntitlementHook($subscription, $type, $confirmo, $event);

        if ($hasSequence) {
            $subscription->update_meta_data(WC_Confirmo_Subscribe_Link::META_LAST_SEQUENCE, $sequence);
        }
        self::recordApplied($subscription, $eventId);
    }

    /**
     * An event whose subscription this store does not hold.
     *
     * Usually a matter of timing rather than a fault: `notifyUrl` is sent to
     * Confirmo before `process_payment()` writes down which WooCommerce
     * subscription the returned id belongs to, so an event minted in that gap
     * arrives before there is anything to apply it to. Asking for a retry is
     * exactly right there, and answering 200 threw the event away instead.
     *
     * The other reasons are permanent — the subscription was deleted, or a copy
     * of the store is pointing at the same URL — and retrying those achieves
     * nothing but a dead-letter entry per attempt. So the event's own age
     * separates them: young enough to be the race, retry; older than that, log
     * and accept, because it is not going to resolve.
     */
    private static function refuseUnknownSubscription(array $event, string $eventId, string $type, string $resourceId): void
    {
        $age = self::eventAge($event);

        if ($age !== null && $age > self::UNKNOWN_SUBSCRIPTION_RETRY_WINDOW) {
            WC_Confirmo_Subscribe_Log::error(sprintf(
                'no WooCommerce subscription holds Confirmo id %s (event %s, %s), and the event is %d minutes old, '
                . 'so it is not a checkout still in progress. Accepted to stop it being redelivered — the subscription '
                . 'was probably deleted here, or another store is using this notification URL.',
                $resourceId,
                $eventId,
                $type,
                (int) round($age / 60)
            ));
            return;
        }

        WC_Confirmo_Subscribe_Log::error(sprintf(
            'no WooCommerce subscription holds Confirmo id %s yet (event %s, %s); asking Confirmo to redeliver',
            $resourceId,
            $eventId,
            $type
        ));
        self::respond(503, 'subscription not linked yet; retry');
    }

    /**
     * How long ago Confirmo minted the event, from its own `timestamp`. Null
     * when absent or unreadable, which is treated as "cannot tell" and so as
     * worth retrying.
     */
    private static function eventAge(array $event): ?int
    {
        $timestamp = $event['timestamp'] ?? null;
        if (!is_string($timestamp) || $timestamp === '') {
            return null;
        }

        $minted = strtotime($timestamp);

        return $minted === false ? null : max(0, time() - $minted);
    }

    /** Either the event or the subscription it belongs to has to say which cycle was charged. */
    private static function hasCycle(array $event, array $confirmo): bool
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        return (isset($data['cycleNumber']) && is_numeric($data['cycleNumber']))
            || (isset($confirmo['cycleNumber']) && is_numeric($confirmo['cycleNumber']));
    }

    private static function alreadyApplied(WC_Subscription $subscription, string $eventId): bool
    {
        if ($eventId === '') {
            return false;
        }
        $seen = $subscription->get_meta(self::META_SEEN_EVENTS);
        return is_array($seen) && in_array($eventId, $seen, true);
    }

    /**
     * Called once per event, after it has been applied or deliberately dropped —
     * never after a failure we want retried.
     */
    private static function recordApplied(WC_Subscription $subscription, string $eventId): void
    {
        if ($eventId !== '') {
            $seen = $subscription->get_meta(self::META_SEEN_EVENTS);
            $seen = is_array($seen) ? $seen : [];
            array_unshift($seen, $eventId);
            $subscription->update_meta_data(
                self::META_SEEN_EVENTS,
                array_slice(array_values(array_unique($seen)), 0, self::SEEN_EVENTS_KEPT)
            );
        }

        $subscription->save();
    }

    /**
     * Merchants grant and revoke access from these; the plugin ships no default
     * behaviour. The subscription is passed rather than an order, since that is
     * what access belongs to.
     */
    private static function fireEntitlementHook(WC_Subscription $subscription, string $type, array $confirmo, array $event): void
    {
        $hooks = [
            'subscription.activated' => 'confirmo_subscription_activated',
            'subscription.resumed' => 'confirmo_subscription_resumed',
            'subscription.past_due' => 'confirmo_subscription_past_due',
            'subscription.canceled' => 'confirmo_subscription_canceled',
            'subscription.expired' => 'confirmo_subscription_expired',
        ];

        if (isset($hooks[$type])) {
            do_action($hooks[$type], $subscription, $confirmo);
            return;
        }

        if ($type === 'subscription.payment.succeeded') {
            do_action('confirmo_subscription_payment_succeeded', $subscription, $event['data'] ?? []);
        } elseif ($type === 'subscription.payment.failed') {
            do_action('confirmo_subscription_payment_failed', $subscription, $event['data'] ?? []);
        }
    }

    /**
     * @return array<string, string> request headers, keys lower-cased
     */
    private static function headers(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $key => $value) {
                $headers[strtolower($key)] = $value;
            }
            return $headers;
        }
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Ends the request through `wp_die()` rather than `exit`, as the Checkout
     * callback does. Same status codes, and the dispatcher reads nothing else —
     * but `wp_die()` runs through a filter, so the handler can be driven by a
     * test. With a bare `exit` none of these paths could be covered: a rejected
     * signature, a timestamp out of tolerance, an unreadable JWKS, or the
     * endpoint answering at all.
     */
    private static function respond(int $status, string $message): void
    {
        wp_die(
            esc_html($message),
            '',
            ['response' => $status, 'exit' => true]
        );
    }
}
