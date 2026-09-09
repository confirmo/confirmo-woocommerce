<?php

/**
 * Outbound: a cancellation that started in WooCommerce has to reach Confirmo, or
 * Confirmo keeps billing a subscription the store believes is over.
 *
 * Two shapes of cancel arrive here. `cancel_order()` routes to `pending-cancel`
 * whenever prepaid term remains — which is always, since the next payment is a
 * cycle away — and Confirmo has no cancel-at-period-end, so that intent is
 * carried through to a real cancellation. The cancellation itself then reaches
 * Confirmo through the `cancelled` hook.
 */
class WC_Confirmo_Subscribe_Cancellation
{
    /** @var array<int, string> subscription id => status before a local cancel */
    private static $statusBefore = [];

    public static function register(): void
    {
        add_action('woocommerce_subscription_pre_update_status', [self::class, 'rememberStatus'], 10, 3);
        add_action('woocommerce_subscription_status_pending-cancel', [self::class, 'onPendingCancel'], 10, 1);
        add_action('woocommerce_subscription_status_cancelled', [self::class, 'onCancelled'], 10, 1);
    }

    /**
     * `update_status()` fires `woocommerce_subscription_status_{to}` — where
     * onCancelled runs — and only then `..._{from}_to_{to}`, so the previous
     * status is not yet recorded when the rollback needs it. `pre_update_status`
     * runs ahead of both.
     *
     * `pending-cancel` is deliberately not a remembered origin: restoring to it
     * would re-enter onPendingCancel() and cancel all over again. Because of that
     * exclusion the subscriber's route — active → pending-cancel → cancelled —
     * keeps `active`, which is where it actually came from.
     */
    public static function rememberStatus($oldStatus, $newStatus, $subscription): void
    {
        if (!$subscription instanceof WC_Subscription) {
            return;
        }
        if (!in_array($newStatus, ['cancelled', 'pending-cancel'], true)) {
            return;
        }
        if (!in_array($oldStatus, ['active', 'on-hold'], true)) {
            return;
        }

        self::$statusBefore[$subscription->get_id()] = $oldStatus;
    }

    /**
     * Every route into `pending-cancel` lands here, so none of them can leave a
     * subscription in a state Confirmo does not have. The subscriber gives up the
     * remainder of the period they paid for.
     */
    public static function onPendingCancel($subscription): void
    {
        if (WC_Confirmo_Subscribe_Capabilities::isProjecting()) {
            return;
        }
        if (!WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
            return;
        }
        if ($subscription->has_status('cancelled') || !$subscription->can_be_updated_to('cancelled')) {
            return;
        }

        $subscription->update_status('cancelled', __('Confirmo cancels immediately — there is no cancel-at-period-end.', 'confirmo-for-woocommerce'));
    }

    /**
     * If Confirmo refuses, the local cancellation is rolled back rather than left
     * to disagree with what is still being billed.
     */
    public static function onCancelled($subscription): void
    {
        if (WC_Confirmo_Subscribe_Capabilities::isProjecting()) {
            return;
        }

        $confirmoId = WC_Confirmo_Subscribe_Link::confirmoId($subscription);
        if ($confirmoId === '') {
            return;
        }

        $result = (new WC_Confirmo_Subscribe_Api())->cancelSubscription($confirmoId);

        $previous = self::$statusBefore[$subscription->get_id()] ?? '';
        unset(self::$statusBefore[$subscription->get_id()]);

        if (!is_wp_error($result)) {
            $subscription->add_order_note(__('Confirmo: cancellation accepted.', 'confirmo-for-woocommerce'));
            return;
        }

        WC_Confirmo_Subscribe_Log::error('cancel failed at Confirmo: ' . $result->get_error_message());
        self::reverse($subscription, $previous);
    }

    /**
     * Back to where it came from, not `active`: a subscription Confirmo has as
     * PAST_DUE was on hold, and reviving it active reported it healthy while
     * Confirmo was still chasing the payment.
     *
     * An unrecognised origin is left cancelled. Defaulting to `active` revived
     * subscriptions that had never been paid for — an abandoned checkout leaves
     * the subscription `pending`, WooCommerce's unpaid-order cron cancels it, and
     * a Confirmo cancel that fails on a subscription it never activated would
     * then have granted the entitlement for free.
     */
    private static function reverse(WC_Subscription $subscription, string $previous): void
    {
        WC_Confirmo_Subscribe_Capabilities::whileProjecting(static function () use ($subscription, $previous) {
            if ($previous !== '' && $subscription->can_be_updated_to($previous)) {
                $subscription->update_status($previous, __('Confirmo could not cancel this subscription — cancellation reversed.', 'confirmo-for-woocommerce'));
                return;
            }

            $subscription->add_order_note(__('Confirmo could not cancel this subscription. It is left cancelled here — check in Confirmo whether it is still billing.', 'confirmo-for-woocommerce'));
        });
    }
}
