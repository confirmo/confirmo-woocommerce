<?php

/**
 * The link between a WooCommerce subscription and the Confirmo subscription that
 * bills it. Everything else keys off `isOurs()`, so no filter or projection can
 * touch another gateway's subscriptions by accident.
 */
class WC_Confirmo_Subscribe_Link
{
    const META_SUBSCRIPTION_ID = '_confirmo_subscription_id';
    const META_LAST_SEQUENCE = '_confirmo_last_sequence';

    public static function isOurs($subscription): bool
    {
        return self::confirmoId($subscription) !== '';
    }

    public static function confirmoId($subscription): string
    {
        return $subscription instanceof WC_Subscription
            ? (string) $subscription->get_meta(self::META_SUBSCRIPTION_ID)
            : '';
    }

    public static function link(WC_Subscription $subscription, string $confirmoId): void
    {
        $subscription->update_meta_data(self::META_SUBSCRIPTION_ID, $confirmoId);
        $subscription->save();
    }

    public static function forOrder(WC_Order $order): ?WC_Subscription
    {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return null;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order, ['order_type' => 'parent']);
        $subscription = is_array($subscriptions) ? reset($subscriptions) : null;

        return $subscription instanceof WC_Subscription ? $subscription : null;
    }

    public static function forConfirmoId(string $confirmoId): ?WC_Subscription
    {
        if ($confirmoId === '' || !function_exists('wcs_get_subscriptions')) {
            return null;
        }

        $found = wcs_get_subscriptions([
            'subscriptions_per_page' => 1,
            'subscription_status' => 'any',
            'meta_query' => [
                [
                    'key' => self::META_SUBSCRIPTION_ID,
                    'value' => $confirmoId,
                ],
            ],
        ]);

        $subscription = is_array($found) ? reset($found) : null;

        return $subscription instanceof WC_Subscription ? $subscription : null;
    }
}
