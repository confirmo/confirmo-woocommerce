<?php

/**
 * Confirmo owns the recurring billing schedule. This gateway makes the one
 * outbound call per purchase; everything after it arrives by webhook.
 */
class WC_Confirmo_Subscribe_Gateway extends WC_Payment_Gateway
{
    const ORDER_META_SUBSCRIPTION_ID = '_confirmo_subscription_id';
    const USER_META_SUBSCRIPTION_ID = '_confirmo_subscription_id';

    /** Kept so a customer returning to an unpaid order is sent back to the
     * same subscription instead of opening another one. */
    const ORDER_META_CHECKOUT_URL = '_confirmo_subscription_checkout_url';

    public function __construct()
    {
        $this->id = 'confirmo_subscribe';
        $this->method_title = __('Confirmo Subscribe', 'confirmo-for-woocommerce');
        $this->method_description = __('Sell recurring Confirmo Subscribe plans. Availability is controlled by the Subscribe module toggle in Confirmo Payment settings.', 'confirmo-for-woocommerce');
        $this->title = __('Subscribe with crypto (Confirmo)', 'confirmo-for-woocommerce');
        $this->has_fields = false;

        // `gateway_scheduled_payments` is WCS's mode for a provider that bills
        // on its own clock: WCS then creates no renewal orders and never asks us
        // to charge. Suspension and reactivation are declared because
        // WC_Subscription::can_be_updated_to() gates our *own* writes on them —
        // without them a past_due event cannot reach on-hold. They are not
        // offered to customers; see WC_Confirmo_Subscribe_Wcs.
        $this->supports = WC_Confirmo_Subscribe_Capabilities::SUPPORTS;

        // The module toggle governs whether this gateway loads at all.
        $this->enabled = 'yes';
    }

    /**
     * The inherited implementation looks at nothing but `enabled`, so without
     * this the gateway appeared on ordinary one-off carts and only refused the
     * customer after they submitted. Confirmo_Subscribe_Blocks::is_active()
     * inherits it for the block checkout.
     */
    public function is_available()
    {
        if (!parent::is_available()) {
            return false;
        }

        // On order-pay the cart is empty, so the order is what to inspect —
        // otherwise a legitimate retry loses the gateway.
        $order = self::orderBeingPaid();
        if ($order instanceof WC_Order) {
            return self::hasMappedItem($order);
        }

        // Nothing to inspect (admin, REST, block-editor preview) or an empty
        // cart. Stay available: an empty cart is also what order-pay looks like,
        // and WooCommerce refuses a gateway it considers unavailable.
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) {
            return true;
        }

        return WC_Confirmo_Subscribe_Checkout::cartHasSubscription();
    }

    private static function orderBeingPaid(): ?WC_Order
    {
        global $wp;

        $orderId = isset($wp->query_vars['order-pay']) ? absint($wp->query_vars['order-pay']) : 0;
        if ($orderId === 0) {
            return null;
        }

        $order = wc_get_order($orderId);
        return $order instanceof WC_Order ? $order : null;
    }

    private static function hasMappedItem(WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            if (!method_exists($item, 'get_product_id')) {
                continue;
            }
            if (WC_Confirmo_Subscribe_Plan::idFor((int) $item->get_product_id()) !== '') {
                return true;
            }
        }
        return false;
    }

    public static function notifyUrl(): string
    {
        return add_query_arg('confirmo-subscribe-webhook', '1', home_url('/'));
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        // A renewal order belongs to a mandate Confirmo already charges. Paying
        // one here would map its line item to a plan and open a second
        // subscription.
        if (function_exists('wcs_order_contains_renewal') && wcs_order_contains_renewal($order)) {
            WC_Confirmo_Subscribe_Log::error('refused payment for renewal order ' . $order->get_id());
            wc_add_notice(__('This subscription is billed automatically by Confirmo and cannot be paid here.', 'confirmo-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        // WooCommerce reuses the same order when a customer returns to pay a
        // pending one, and creating again there left the first subscription
        // live, unlinked and impossible to cancel from the store: two funded
        // mandates, one visible.
        $existingId = (string) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID);
        if ($existingId !== '') {
            return self::resumeExisting($order, $existingId);
        }

        $planId = WC_Confirmo_Subscribe_Plan::forOrder($order);
        if (is_wp_error($planId)) {
            WC_Confirmo_Subscribe_Log::error('refused order ' . $order->get_id() . ': ' . $planId->get_error_code());
            wc_add_notice($planId->get_error_message(), 'error');
            return ['result' => 'failure'];
        }

        // WCS has already created the subscription by now: it hooks
        // woocommerce_checkout_order_processed at priority 100, which WooCommerce
        // fires before process_payment(). Its total is the amount to bill.
        $wcsSubscription = WC_Confirmo_Subscribe_Link::forOrder($order);
        if (!$wcsSubscription) {
            WC_Confirmo_Subscribe_Log::error('no WooCommerce subscription found for order ' . $order->get_id());
            wc_add_notice(__('Could not start the subscription. Please try again.', 'confirmo-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $amount = WC_Confirmo_Subscribe_Amount::forSubscription($wcsSubscription);
        if (is_wp_error($amount)) {
            WC_Confirmo_Subscribe_Log::error('refused amount for order ' . $order->get_id() . ': ' . $amount->get_error_message());
            wc_add_notice($amount->get_error_message(), 'error');
            return ['result' => 'failure'];
        }

        $api = new WC_Confirmo_Subscribe_Api();
        $subscription = $api->createSubscription([
            'planId' => $planId,
            'subscriber' => ['email' => $order->get_billing_email()],
            'amount' => $amount,
            'notifyUrl' => self::notifyUrl(),
            'returnUrl' => $this->get_return_url($order),
        ]);

        if (is_wp_error($subscription)) {
            $data = $subscription->get_error_data();
            $apiCode = is_array($data) && isset($data['code']) ? (string) $data['code'] : '';

            WC_Confirmo_Subscribe_Log::error('createSubscription failed: ' . $subscription->get_error_message()
                . ' code=' . $apiCode . ' data=' . wp_json_encode($data));

            if ($apiCode === 'ACTIVE_SUBSCRIPTION_EXISTS') {
                wc_add_notice(__('You already have an active subscription for this plan.', 'confirmo-for-woocommerce'), 'error');
            } else {
                wc_add_notice(__('Could not start the subscription. Please try again.', 'confirmo-for-woocommerce'), 'error');
            }
            return ['result' => 'failure'];
        }

        $subscriptionId = $subscription['id'] ?? '';
        $checkoutUrl = $subscription['checkoutUrl'] ?? '';

        if ($subscriptionId === '' || $checkoutUrl === '') {
            wc_add_notice(__('Confirmo did not return a checkout link. Please try again.', 'confirmo-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_meta_data(self::ORDER_META_SUBSCRIPTION_ID, $subscriptionId);
        $order->update_meta_data(self::ORDER_META_CHECKOUT_URL, $checkoutUrl);
        WC_Confirmo_Subscribe_Link::link($wcsSubscription, $subscriptionId);
        $order->update_status('pending', __('Awaiting Confirmo Subscribe checkout.', 'confirmo-for-woocommerce'));
        $order->save();

        // Keyed to the user so access resolves by user, never by email.
        $userId = (int) $order->get_user_id();
        if ($userId > 0) {
            add_user_meta($userId, self::USER_META_SUBSCRIPTION_ID, $subscriptionId);
        }

        return [
            'result' => 'success',
            'redirect' => self::redirectUrl($checkoutUrl, $subscriptionId, $order),
        ];
    }

    /** The filter lets a local dev environment redirect via its own
     * session-bootstrap instead of the hosted checkout. */
    private static function redirectUrl(string $checkoutUrl, string $subscriptionId, WC_Order $order): string
    {
        return (string) apply_filters(
            'confirmo_subscribe_checkout_redirect_url',
            $checkoutUrl,
            $subscriptionId,
            $order
        );
    }

    /**
     * Never creates. When the state at Confirmo cannot be read the stored link
     * is reused anyway: an expired link costs a support ticket, a second
     * createSubscription() costs a second mandate nothing can cancel.
     *
     * @return array<string, string>
     */
    private static function resumeExisting(WC_Order $order, string $subscriptionId): array
    {
        $subscription = (new WC_Confirmo_Subscribe_Api())->getSubscription($subscriptionId);
        $status = '';

        if (is_wp_error($subscription)) {
            WC_Confirmo_Subscribe_Log::error('could not re-read subscription ' . $subscriptionId
                . ' for order ' . $order->get_id() . ': ' . $subscription->get_error_message());
        } else {
            $status = (string) ($subscription['status'] ?? '');
        }

        if (in_array($status, ['ACTIVE', 'TRIALING'], true)) {
            wc_add_notice(__('This subscription is already active at Confirmo, so there is nothing to pay here.', 'confirmo-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        if (in_array($status, ['CANCELED', 'EXPIRED'], true)) {
            wc_add_notice(__('This subscription was closed at Confirmo and can no longer be paid. Please place a new order.', 'confirmo-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        $checkoutUrl = is_array($subscription) && !empty($subscription['checkoutUrl'])
            ? (string) $subscription['checkoutUrl']
            : (string) $order->get_meta(self::ORDER_META_CHECKOUT_URL);

        if ($checkoutUrl === '') {
            WC_Confirmo_Subscribe_Log::error('order ' . $order->get_id() . ' holds subscription ' . $subscriptionId . ' with no reusable checkout link');
            wc_add_notice(__('Could not reopen the Confirmo checkout for this order. Please contact the store.', 'confirmo-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        return [
            'result' => 'success',
            'redirect' => self::redirectUrl($checkoutUrl, $subscriptionId, $order),
        ];
    }

}
