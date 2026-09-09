<?php

/**
 * Boots the WordPress install the tests run against.
 *
 * These are integration tests on purpose. The things worth being sure about —
 * an invoice request carrying the right amount, a webhook moving a subscription,
 * a renewal order appearing for cycle two — only happen when real WooCommerce and
 * WooCommerce Subscriptions objects are involved. Stubbing those out would leave
 * tests that pass while the plugin is broken.
 *
 * So the suite needs a WordPress with WooCommerce active. `tests/run.sh` builds
 * one from nothing but Docker; `WP_ROOT` points at any other.
 *
 * WooCommerce Subscriptions is a paid extension, so it cannot be installed for
 * you. Without it the Subscribe tests skip and the Checkout tests still run —
 * tests/README.md covers supplying a copy.
 *
 * Nothing is written permanently: every test runs inside a transaction that is
 * rolled back afterwards (see ConfirmoTestCase).
 */

$wpRoot = getenv('WP_ROOT') ?: '/var/www/html';

if (!file_exists($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, "No WordPress at {$wpRoot}. Set WP_ROOT, or run the suite with tests/run.sh.\n");
    exit(1);
}

define('WP_USE_THEMES', false);

// wp-load reads these; without them WordPress cannot build the URLs the plugin
// puts into notifyUrl and returnUrl.
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';

require $wpRoot . '/wp-load.php';

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active on the WordPress at {$wpRoot}.\n");
    exit(1);
}

// Not fatal. WooCommerce Subscriptions cannot be installed on a clean checkout,
// and refusing to run at all would have meant nobody could exercise the Checkout
// gateway either.
if (!class_exists('WC_Subscriptions')) {
    fwrite(STDERR, "WooCommerce Subscriptions is not active — the Subscribe tests will skip.\n");
}

if (!class_exists('WC_Confirmo_Gateway')) {
    fwrite(STDERR, "The Confirmo plugin is not active on the WordPress at {$wpRoot}.\n");
    exit(1);
}

// The Checkout gateway empties the cart at the end of process_payment, which is
// fatal when nothing has initialised one.
if (function_exists('wc_load_cart')) {
    wc_load_cart();
}

require __DIR__ . '/ConfirmoInputStream.php';
require __DIR__ . '/ConfirmoTestCase.php';
require __DIR__ . '/SubscribeTestCase.php';
