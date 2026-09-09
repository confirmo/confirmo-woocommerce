<?php

/**
 * Account creation is forced for subscription carts so the subscription can be
 * keyed to a WordPress user rather than matched by email at runtime.
 */
class WC_Confirmo_Subscribe_Checkout
{
    public static function register(): void
    {
        add_filter('woocommerce_checkout_registration_required', [self::class, 'forceRegistration']);
        add_filter('woocommerce_checkout_registration_enabled', [self::class, 'enableRegistration']);
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validateAddToCart'], 10, 3);
        add_action('woocommerce_check_cart_items', [self::class, 'validateCartItems']);
    }

    /**
     * A Confirmo subscription bills one plan and a WooCommerce order settles
     * through one gateway, so a mixed cart would leave the other items
     * uncharged. Quantity is free — the line total is what gets billed.
     */
    public static function validateAddToCart($passed, $productId, $quantity)
    {
        if (!$passed) {
            return $passed;
        }

        $addingSubscription = WC_Confirmo_Subscribe_Plan::idFor((int) $productId) !== '';

        if ($addingSubscription) {
            if (self::cartHasOtherThan((int) $productId)) {
                wc_add_notice(__('A subscription must be purchased on its own. Please empty your cart first.', 'confirmo-for-woocommerce'), 'error');
                return false;
            }
        } elseif (self::cartHasSubscription()) {
            wc_add_notice(__('Your cart contains a subscription, which must be purchased on its own. Remove it to add other products.', 'confirmo-for-woocommerce'), 'error');
            return false;
        }

        return $passed;
    }

    /**
     * Backstop for carts that became mixed outside add-to-cart: session
     * restore, quantity edits, another plugin adding a line.
     */
    public static function validateCartItems(): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }

        $subscriptionLines = 0;
        $lines = 0;
        foreach (WC()->cart->get_cart() as $item) {
            $lines++;
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            if ($productId > 0 && WC_Confirmo_Subscribe_Plan::idFor($productId) !== '') {
                $subscriptionLines++;
            }
        }

        if ($subscriptionLines === 0) {
            return;
        }

        if ($subscriptionLines > 1 || $lines > 1) {
            wc_add_notice(__('A subscription must be purchased on its own. Please remove the other items before checking out.', 'confirmo-for-woocommerce'), 'error');
        }
    }

    /**
     * Adding more of the subscription already in the cart is a quantity change,
     * not a mixed cart. Testing "cart is not empty" instead turned "add one
     * more" into "empty your cart first", while adding three at once was fine.
     */
    private static function cartHasOtherThan(int $productId): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            if ((int) ($item['product_id'] ?? 0) !== $productId) {
                return true;
            }
        }

        return false;
    }

    public static function forceRegistration($required)
    {
        return self::cartHasSubscription() ? true : $required;
    }

    public static function enableRegistration($enabled)
    {
        return self::cartHasSubscription() ? true : $enabled;
    }

    public static function cartHasSubscription(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }
        foreach (WC()->cart->get_cart() as $item) {
            $productId = isset($item['product_id']) ? (int) $item['product_id'] : 0;
            if ($productId > 0 && WC_Confirmo_Subscribe_Plan::idFor($productId) !== '') {
                return true;
            }
        }
        return false;
    }
}
