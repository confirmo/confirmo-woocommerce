<?php

/**
 * The Confirmo plan a product is mapped to: where the mapping is stored, what a
 * billing interval means to WooCommerce, and which plan an order is for.
 *
 * Only VARIABLE plans are sellable — the plan carries the billing asset and
 * interval, the product carries the price.
 */
class WC_Confirmo_Subscribe_Plan
{
    const META_ID = '_confirmo_plan_id';
    const META_TYPE = '_confirmo_plan_type';
    const META_INTERVAL = '_confirmo_plan_interval';

    const TYPE_VARIABLE = 'VARIABLE';

    public static function idFor(int $productId): string
    {
        return (string) get_post_meta($productId, self::META_ID, true);
    }

    public static function typeFor(int $productId): string
    {
        return (string) get_post_meta($productId, self::META_TYPE, true);
    }

    /** Confirmo's own interval, kept so the WooCommerce schedule can be rebuilt from it. */
    public static function intervalFor(int $productId): string
    {
        return (string) get_post_meta($productId, self::META_INTERVAL, true);
    }

    /**
     * A product mapped before VARIABLE-only, or mapped outside the product
     * screen, can still carry a FIXED plan — and sending an amount for one is
     * rejected by the API.
     */
    public static function isSellable(int $productId): bool
    {
        return self::idFor($productId) !== '' && self::typeFor($productId) === self::TYPE_VARIABLE;
    }

    public static function store(int $productId, string $planId, string $type, string $billingInterval = ''): void
    {
        update_post_meta($productId, self::META_ID, $planId);
        update_post_meta($productId, self::META_TYPE, $type);
        update_post_meta($productId, self::META_INTERVAL, $billingInterval);
    }

    public static function forget(int $productId): void
    {
        delete_post_meta($productId, self::META_ID);
        delete_post_meta($productId, self::META_TYPE);
        delete_post_meta($productId, self::META_INTERVAL);
    }

    /**
     * Confirmo's BillingInterval is MONTHLY or ANNUAL and nothing else, so those
     * are the only two mapped. Null for anything unrecognised — the caller then
     * refuses the mapping, which is what should happen if Confirmo ever adds an
     * interval this build has never heard of. Guessing monthly instead would
     * advertise, renew and report the wrong cadence while Confirmo billed the
     * real one, with no screen disagreeing.
     *
     * @return array{0: string, 1: int}|null period and interval
     */
    public static function periodFor(string $billingInterval): ?array
    {
        switch (strtoupper($billingInterval)) {
            case 'MONTHLY':
                return ['month', 1];
            case 'ANNUAL':
                return ['year', 1];
            default:
                return null;
        }
    }

    /**
     * Refuses rather than taking the first mapped line. The cart-level rule runs
     * on `woocommerce_check_cart_items`, which fires when the cart or checkout
     * page renders; a cart that turned mixed afterwards would open one
     * subscription for the recurring total, and the first payment would then mark
     * the whole parent order paid — including items never charged for.
     *
     * @return string|WP_Error the plan id, or why this order cannot be billed
     */
    public static function forOrder(WC_Order $order)
    {
        $mapped = [];
        $unmapped = 0;

        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product_id')) {
                continue;
            }

            $productId = (int) $item->get_product_id();
            $planId = self::idFor($productId);

            if ($planId === '') {
                $unmapped++;
                continue;
            }

            $mapped[] = ['planId' => $planId, 'productId' => $productId];
        }

        if ($mapped === []) {
            return new WP_Error(
                'confirmo_subscribe_no_plan',
                __('This order has no Confirmo Subscribe plan.', 'confirmo-for-woocommerce')
            );
        }

        if (count($mapped) > 1 || $unmapped > 0) {
            return new WP_Error(
                'confirmo_subscribe_mixed_order',
                __('A subscription must be purchased on its own. Please remove the other items from your order and try again.', 'confirmo-for-woocommerce')
            );
        }

        if (!self::isSellable($mapped[0]['productId'])) {
            return new WP_Error(
                'confirmo_subscribe_plan_not_sellable',
                __('This product is not set up for subscription payments. Please contact the store.', 'confirmo-for-woocommerce')
            );
        }

        return $mapped[0]['planId'];
    }
}
