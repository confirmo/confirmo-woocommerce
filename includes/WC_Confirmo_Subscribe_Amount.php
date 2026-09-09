<?php

/**
 * What Confirmo will be told to bill every cycle.
 *
 * Its own unit because it is the one decision in the module that turns directly
 * into money, and because it has to be checkable without WooCommerce's gateway
 * machinery around it.
 */
class WC_Confirmo_Subscribe_Amount
{
    /** WCS decides whether a subscription needs paying with absint($total). */
    const MINIMUM = 1;

    /** Confirmo rejects an amount carrying more than this many decimals. */
    const DECIMALS = 2;

    /**
     * Taken from the subscription's own total, which is the same number WCS uses
     * to build every renewal order — so the store and Confirmo cannot disagree.
     * It is already net of discounts and carries tax per store settings.
     *
     * @return string|WP_Error decimal string, or why it cannot be billed
     */
    public static function forSubscription(WC_Subscription $subscription)
    {
        return self::forTotal((float) $subscription->get_total());
    }

    /**
     * @return string|WP_Error
     */
    public static function forTotal(float $total)
    {
        if ($total <= 0) {
            return new WP_Error(
                'confirmo_subscribe_amount_zero',
                __('This subscription has no recurring amount, so it cannot be billed. Please contact the store.', 'confirmo-for-woocommerce')
            );
        }

        // Under the minimum the total absints to zero, WCS reads the subscription
        // as free, and takes renewals back onto its own clock.
        if ($total < self::MINIMUM) {
            return new WP_Error(
                'confirmo_subscribe_amount_below_minimum',
                sprintf(
                    /* translators: %s: formatted minimum recurring amount */
                    __('The recurring total must be at least %s. Please adjust your order or contact the store.', 'confirmo-for-woocommerce'),
                    strip_tags(wc_price(self::MINIMUM))
                )
            );
        }

        $amount = wc_format_decimal($total, self::DECIMALS);

        // Refused rather than rounded: rounding would quietly change what the
        // subscriber is charged, every cycle, for the life of the subscription.
        if ((float) $amount !== $total) {
            return new WP_Error(
                'confirmo_subscribe_amount_precision',
                __('The recurring total cannot be billed at this precision. Please contact the store.', 'confirmo-for-woocommerce')
            );
        }

        return $amount;
    }
}
