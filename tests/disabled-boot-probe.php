<?php

/**
 * Boots WordPress with the Subscribe module switched off and reports what the
 * plugin did. Run in its own process by ModuleToggleTest; useless on its own.
 *
 * The point is what a Checkout-only merchant's store looks like. Anything this
 * reports as present is something an alpha module put in front of a merchant who
 * never enabled it.
 */

$wpRoot = getenv('WP_ROOT') ?: '/var/www/html';

define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_NAME'] = 'localhost';

require $wpRoot . '/wp-load.php';

/** Hooks the module registers only when it is enabled. */
$functionalHooks = [
    'woocommerce_payment_gateways',
    'woocommerce_blocks_payment_method_type_registration',
    'woocommerce_subscription_payment_gateway_supports',
    'woocommerce_subscription_is_manual',
    'woocommerce_subscription_status_cancelled',
    'woocommerce_subscription_pre_update_status',
    'woocommerce_product_options_general_product_data',
    'template_redirect',
];

$registered = [];
foreach ($functionalHooks as $hook) {
    foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $callbacks) {
        foreach ($callbacks as $callback) {
            $name = confirmo_probe_callback_name($callback['function'] ?? null);
            if ($name !== null && stripos($name, 'confirmo_subscribe') !== false) {
                $registered[] = $hook . ' => ' . $name;
            }
        }
    }
}

/** Classes that only load past the enabled check. */
$loaded = array_values(array_filter([
    'WC_Confirmo_Subscribe_Gateway',
    'WC_Confirmo_Subscribe_Webhook',
    'WC_Confirmo_Subscribe_Projection',
    'WC_Confirmo_Subscribe_Cancellation',
    'WC_Confirmo_Subscribe_Capabilities',
    'WC_Confirmo_Subscribe_Product',
    'WC_Confirmo_Subscribe_Plan',
], 'class_exists'));

$gateways = [];
if (class_exists('WC_Payment_Gateways')) {
    foreach (WC()->payment_gateways()->payment_gateways() as $id => $gateway) {
        $gateways[] = (string) $id;
    }
}

echo wp_json_encode([
    // Proof the helper worked. Without this a green run could mean the module
    // was simply enabled, and the whole probe would be theatre.
    'forcedOff' => defined('CONFIRMO_SUBSCRIBE_FORCED_OFF'),
    'moduleReportsEnabled' => class_exists('WC_Confirmo_Subscribe_Module')
        && WC_Confirmo_Subscribe_Module::isEnabled(),
    'checkoutGatewayPresent' => in_array('confirmo', $gateways, true),
    'subscribeHooks' => $registered,
    'subscribeClasses' => $loaded,
    'subscribeGateway' => in_array('confirmo_subscribe', $gateways, true),
]);

/**
 * @param mixed $function
 */
function confirmo_probe_callback_name($function): ?string
{
    if (is_string($function)) {
        return $function;
    }
    if (is_array($function) && count($function) === 2) {
        $class = is_object($function[0]) ? get_class($function[0]) : (string) $function[0];
        return $class . '::' . (string) $function[1];
    }
    if ($function instanceof Closure) {
        // A closure carries no name, so attribute it by the file it came from.
        $file = (new ReflectionFunction($function))->getFileName();
        return $file === false ? null : basename((string) $file);
    }

    return null;
}
