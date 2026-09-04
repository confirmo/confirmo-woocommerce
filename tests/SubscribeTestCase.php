<?php

/**
 * Base for the tests that need WooCommerce Subscriptions.
 *
 * It is a paid extension, so a clean checkout cannot install it and these skip
 * rather than fail. The Checkout tests have no such dependency and run either
 * way — see tests/README.md.
 */
abstract class SubscribeTestCase extends ConfirmoTestCase
{
    protected function setUp(): void
    {
        if (!class_exists('WC_Subscriptions') || !function_exists('wcs_create_subscription')) {
            self::markTestSkipped('WooCommerce Subscriptions is not active; see tests/README.md');
        }

        // The module loads its classes at boot, so a store with the toggle off
        // has none of them — too late for a test to switch on.
        if (!class_exists('WC_Confirmo_Subscribe_Plan')) {
            self::markTestSkipped('the Confirmo Subscribe module is not enabled on this store; see tests/README.md');
        }

        parent::setUp();
    }
}
