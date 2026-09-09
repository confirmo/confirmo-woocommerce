<?php

/**
 * Where the WooCommerce Subscriptions integration is wired up.
 *
 * Confirmo owns the billing clock; WCS owns the objects. Three pieces make that
 * work, and they are deliberately separate: Capabilities decides what the
 * integration can do and who may ask for it, Projection applies inbound Confirmo
 * events, and Cancellation carries an outbound cancel to Confirmo.
 */
class WC_Confirmo_Subscribe_Wcs
{
    public static function isActive(): bool
    {
        return class_exists('WC_Subscriptions') && function_exists('wcs_create_renewal_order');
    }

    public static function register(): void
    {
        WC_Confirmo_Subscribe_Capabilities::register();
        WC_Confirmo_Subscribe_Cancellation::register();
    }
}
