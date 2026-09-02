<?php

/**
 * What this integration can do, and who is allowed to ask for it.
 *
 * WooCommerce Subscriptions gates both its UI *and* its own state machine on the
 * gateway's `supports` flags, so a capability we need for a projection is also a
 * capability an admin or subscriber can invoke. Two of them — suspension and
 * reactivation — have no Confirmo equivalent, so they are granted only while a
 * Confirmo event is being applied: the projection writes them freely and nobody
 * can request them.
 *
 * That window is `whileProjecting()`, and it is the only way to open it.
 */
class WC_Confirmo_Subscribe_Capabilities
{
    /**
     * The single source of truth: the gateway declares this list and the supports
     * filter answers with it, so no store setting can widen it.
     */
    const SUPPORTS = [
        'products',
        'subscriptions',
        'gateway_scheduled_payments',
        'subscription_cancellation',
        'subscription_suspension',
        'subscription_reactivation',
    ];

    /** Performed by the projection, never offered to anyone. */
    const PROJECTION_ONLY = [
        'subscription_suspension',
        'subscription_reactivation',
    ];

    private static $projecting = false;

    public static function register(): void
    {
        // WCS treats a manual-renewal subscription as supporting every gateway
        // feature, and a merchant can switch manual renewals on at any time —
        // handing renewals back to WCS without anyone touching code. So state our
        // capabilities rather than letting the store's settings imply them.
        add_filter('woocommerce_subscription_is_manual', [self::class, 'neverManual'], 10, 2);
        add_filter('woocommerce_subscription_payment_gateway_supports', [self::class, 'supports'], 10, 3);

        // Confirmo runs its own retry ladder. `wcs_is_retry_enabled` would be the
        // obvious filter but it is store-wide and carries no subscription to test,
        // so it disabled retries for every *other* gateway on the store.
        // `wcs_get_retry_rule` is asked per order.
        add_filter('wcs_get_retry_rule', [self::class, 'noRetryRule'], 10, 3);

        add_filter('wcs_view_subscription_actions', [self::class, 'customerActions'], 10, 2);

        // Gated on something other than a gateway feature, so it needs its own
        // filter. Offering it would stop billing in the store's eyes only.
        add_filter('woocommerce_can_subscription_be_updated_to_expired', [self::class, 'projectionOnly'], 10, 2);
        add_filter('woocommerce_can_subscription_be_updated_to_pending-cancel', [self::class, 'hidePendingCancel'], 10, 2);

        // WCS reaches `active` only from on-hold, pending or pending-cancel, and
        // `on-hold` only from active — never from cancelled. That made both the
        // cancel rollback and a resume after cancellation impossible: Confirmo
        // refusing a cancel left the store showing cancelled while Confirmo kept
        // billing. Widened only while an event is being applied, which is the
        // one caller that knows what Confirmo actually has.
        add_filter('woocommerce_can_subscription_be_updated_to_active', [self::class, 'allowWhileProjecting'], 10, 2);
        add_filter('woocommerce_can_subscription_be_updated_to_on-hold', [self::class, 'allowWhileProjecting'], 10, 2);
    }

    /**
     * @param bool $canBeUpdated
     * @param mixed $subscription
     */
    public static function allowWhileProjecting($canBeUpdated, $subscription): bool
    {
        if (!WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
            return (bool) $canBeUpdated;
        }

        return self::$projecting ? true : (bool) $canBeUpdated;
    }

    /**
     * Run $work with the projection-only capabilities granted. The only way in,
     * so an exception cannot leave the window stuck open.
     *
     * @return mixed whatever $work returns
     */
    public static function whileProjecting(callable $work)
    {
        $was = self::$projecting;
        self::$projecting = true;
        try {
            return $work();
        } finally {
            self::$projecting = $was;
        }
    }

    /**
     * True while a Confirmo event is being applied. Lets the outbound paths tell
     * a status change we made from one a person asked for.
     */
    public static function isProjecting(): bool
    {
        return self::$projecting;
    }

    /**
     * Bypasses the manual-renewal shortcut in
     * WC_Subscription::payment_method_supports().
     */
    public static function supports($supports, $feature, $subscription): bool
    {
        if (!WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
            return (bool) $supports;
        }
        if (in_array($feature, self::PROJECTION_ONLY, true)) {
            return self::$projecting;
        }

        return in_array($feature, self::SUPPORTS, true);
    }

    /**
     * Never manual, whatever the store's settings say and whatever WCS's staging
     * detection concludes. Staging protection is not lost: WooCommerce never
     * charges for us, and a clone receives no webhooks because the notifyUrl
     * still points at the original.
     */
    public static function neverManual($isManual, $subscription): bool
    {
        return WC_Confirmo_Subscribe_Link::isOurs($subscription) ? false : (bool) $isManual;
    }

    /**
     * `expired` would end a subscription WooCommerce-side while Confirmo kept
     * billing. Available to the projection, which sets it because Confirmo said
     * so, and to nobody else.
     */
    public static function projectionOnly($canBeUpdated, $subscription): bool
    {
        if (!WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
            return (bool) $canBeUpdated;
        }

        return self::$projecting && (bool) $canBeUpdated;
    }

    /**
     * Off the admin dropdown, but still reachable on the front end or
     * `cancel_order()` throws when a subscriber presses Cancel. Denied in admin,
     * `cancel_order()` falls through to a straight cancellation anyway.
     */
    public static function hidePendingCancel($canBeUpdated, $subscription): bool
    {
        if (!WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
            return (bool) $canBeUpdated;
        }

        return !is_admin() && (bool) $canBeUpdated;
    }

    /**
     * Confirmo has no pause, so offering these buttons would promise something
     * the mandate cannot do.
     *
     * @param array<string, mixed> $actions
     * @return array<string, mixed>
     */
    public static function customerActions($actions, $subscription): array
    {
        if (!is_array($actions)) {
            return [];
        }
        if (!WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
            return $actions;
        }

        unset($actions['suspend'], $actions['reactivate']);

        return $actions;
    }

    /**
     * @param int $orderId the renewal order being retried
     * @return mixed null disables the retry; anything else is left untouched
     */
    public static function noRetryRule($rule, $retryNumber, $orderId)
    {
        if (!function_exists('wcs_get_subscriptions_for_renewal_order')) {
            return $rule;
        }

        foreach ((array) wcs_get_subscriptions_for_renewal_order($orderId) as $subscription) {
            if (WC_Confirmo_Subscribe_Link::isOurs($subscription)) {
                return null;
            }
        }

        return $rule;
    }
}
