<?php

/**
 * Inbound: one Confirmo event onto the WooCommerce subscription.
 *
 * With `gateway_scheduled_payments` declared, WCS neither creates a renewal order
 * nor puts the subscription on hold when its own clock says a payment is due —
 * see `WC_Subscriptions_Manager::process_renewal()`, which gates both on that
 * flag. So everything WCS would have done on a cycle happens here instead, from a
 * webhook. WCS keeps the objects, the customer UI, the emails and the reports.
 */
class WC_Confirmo_Subscribe_Projection
{
    /**
     * `$confirmo` is the subscription as re-fetched from the API, never the
     * webhook payload.
     */
    public static function apply(WC_Subscription $subscription, string $type, array $confirmo, array $event): void
    {
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription, $type, $confirmo, $event) {
            switch ($type) {
                case 'subscription.activated':
                    self::activate($subscription, __('Confirmo: subscription activated.', 'confirmo-for-woocommerce'));
                    break;
                case 'subscription.resumed':
                    self::activate($subscription, __('Confirmo: subscription resumed.', 'confirmo-for-woocommerce'));
                    break;
                case 'subscription.past_due':
                    self::status($subscription, 'on-hold', __('Confirmo: subscription past due.', 'confirmo-for-woocommerce'));
                    break;
                case 'subscription.canceled':
                    self::status($subscription, 'cancelled', __('Confirmo: subscription canceled.', 'confirmo-for-woocommerce'));
                    break;
                case 'subscription.expired':
                    self::status($subscription, 'expired', __('Confirmo: subscription expired.', 'confirmo-for-woocommerce'));
                    break;
                case 'subscription.payment.succeeded':
                    self::recordPayment($subscription, is_array($event['data'] ?? null) ? $event['data'] : [], $confirmo);
                    break;
                case 'subscription.payment.failed':
                    $subscription->add_order_note(__('Confirmo: recurring payment failed.', 'confirmo-for-woocommerce'));
                    break;
                case 'subscription.created':
                default:
                    break;
            }

            self::syncDates($subscription, $confirmo);
        });
    }

    /**
     * Cycle 1 is already represented by the parent order — activation usually
     * completes it moments before this event lands — so minting a renewal order
     * for it would count one charge twice. Only later cycles create one.
     *
     * Totals come from the subscription's own line items; nothing recomputes.
     */
    private static function recordPayment(WC_Subscription $subscription, array $payment, array $confirmo): void
    {
        $reference = (string) ($payment['id'] ?? '');

        // The event's own cycle number if it carries one, else the subscription's
        // — `cycleNumber` is a required field on the resource, and re-reading it
        // beats both guessing the cycle and discarding a real payment.
        $cycle = isset($payment['cycleNumber']) && is_numeric($payment['cycleNumber'])
            ? (int) $payment['cycleNumber']
            : (int) ($confirmo['cycleNumber'] ?? 0);

        if ($cycle <= 1) {
            $parent = $subscription->get_parent();
            if ($parent instanceof WC_Order && $parent->needs_payment()) {
                $parent->payment_complete($reference);
            } else {
                $subscription->add_order_note(__('Confirmo: first cycle paid; recorded on the original order.', 'confirmo-for-woocommerce'));
            }
            return;
        }

        if ($reference !== '' && self::alreadyRecorded($subscription, $reference)) {
            return;
        }

        $renewal = wcs_create_renewal_order($subscription);
        if (is_wp_error($renewal)) {
            WC_Confirmo_Subscribe_Log::error('could not create renewal order: ' . $renewal->get_error_message());
            $subscription->add_order_note(__('Confirmo: payment succeeded but the renewal order could not be created.', 'confirmo-for-woocommerce'));
            return;
        }

        $renewal->set_payment_method($subscription->get_payment_method());
        $renewal->payment_complete($reference);

        self::flagAmountDrift($renewal, $confirmo);
    }

    /**
     * The renewal order is rebuilt from the subscription's line items, while
     * Confirmo charges the amount frozen at checkout. A product price or tax
     * change after signup makes the two disagree, and the order would then record
     * a total nobody was ever billed.
     *
     * Noted rather than corrected: rewriting an order total would be inventing a
     * different set of line items to fit the number.
     */
    private static function flagAmountDrift(WC_Order $renewal, array $confirmo): void
    {
        if (!isset($confirmo['amount']) || !is_numeric($confirmo['amount'])) {
            return;
        }

        $charged = (float) $confirmo['amount'];
        if (abs($charged - (float) $renewal->get_total()) < 0.01) {
            return;
        }

        $renewal->add_order_note(sprintf(
            /* translators: 1: amount Confirmo charged, 2: order total */
            __('Confirmo charged %1$s, which does not match this order total of %2$s. The subscription price changed after the mandate was created; Confirmo bills the original amount.', 'confirmo-for-woocommerce'),
            $charged,
            $renewal->get_total()
        ));
    }

    /** A redelivery must not mint a second order for one charge. */
    private static function alreadyRecorded(WC_Subscription $subscription, string $paymentId): bool
    {
        foreach ($subscription->get_related_orders('all') as $order) {
            if ($order instanceof WC_Order && $order->get_transaction_id() === $paymentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Completing an unpaid parent order is what activates a subscription in WCS,
     * so prefer that path and fall back to a direct status change.
     */
    private static function activate(WC_Subscription $subscription, string $note): void
    {
        $parent = $subscription->get_parent();
        if ($parent instanceof WC_Order && $parent->needs_payment()) {
            $parent->payment_complete();
            $subscription->add_order_note($note);
            return;
        }

        self::status($subscription, 'active', $note);
    }

    private static function status(WC_Subscription $subscription, string $status, string $note): void
    {
        if ($subscription->has_status($status)) {
            $subscription->add_order_note($note);
            return;
        }

        if (!$subscription->can_be_updated_to($status)) {
            WC_Confirmo_Subscribe_Log::error(sprintf('refused transition to %s from %s', $status, $subscription->get_status()));
            $subscription->add_order_note(sprintf(
                /* translators: %s: subscription status Confirmo reported */
                __('Confirmo reported %s, which WooCommerce would not accept from the current status.', 'confirmo-for-woocommerce'),
                $status
            ));
            return;
        }

        $subscription->update_status($status, $note);
    }

    /**
     * Safe to write `next_payment` because with `gateway_scheduled_payments` WCS
     * does not act on it — its handlers fire and return.
     */
    private static function syncDates(WC_Subscription $subscription, array $confirmo): void
    {
        foreach (['next_payment' => 'nextPaymentDate', 'end' => 'endsAt'] as $wcsKey => $confirmoKey) {
            // Only a field Confirmo actually reported is ours to touch. Treating
            // an absent one as "cleared" wiped the `end` date WCS writes itself on
            // cancellation, on every event.
            if (!array_key_exists($confirmoKey, $confirmo)) {
                continue;
            }

            $date = self::asDate($confirmo[$confirmoKey]);
            if ($date === null) {
                WC_Confirmo_Subscribe_Log::error(sprintf(
                    'ignoring %s: expected epoch seconds or null, got %s',
                    $confirmoKey,
                    gettype($confirmo[$confirmoKey])
                ));
                continue;
            }

            // One key per call. `update_dates()` validates the whole set and
            // throws before writing any of it, so a next_payment that Confirmo
            // reports at or after the end date used to drop both — and this is
            // the only thing keeping next_payment in step with Confirmo.
            try {
                $subscription->update_dates([$wcsKey => $date]);
            } catch (Exception $e) {
                WC_Confirmo_Subscribe_Log::error('could not sync ' . $wcsKey . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Only an explicit null clears a date — Confirmo's own words for "no further
     * charge is scheduled". Anything unreadable is a contract change rather than
     * a clearance, and silently clearing it emptied the customer's next payment
     * date on every webhook with nothing to show why.
     *
     * @param mixed $epochSeconds
     * @return string|int|null the date WCS wants, 0 to clear it, null to leave alone
     */
    public static function asDate($epochSeconds)
    {
        if ($epochSeconds === null) {
            return 0;
        }

        return is_numeric($epochSeconds) ? gmdate('Y-m-d H:i:s', (int) $epochSeconds) : null;
    }
}
