<?php

/**
 * Only VARIABLE plans are offered: the plan carries the billing asset and
 * interval, the product carries the price. So nothing about the price is
 * mirrored or locked, and the interval, term and trial fields are.
 */
class WC_Confirmo_Subscribe_Product
{
    /**
     * The picker is not always rendered — an unreachable plans API prints a
     * message instead of the select — so a missing plan id in $_POST has to mean
     * "never asked", not "not a subscription". Without this marker one failed API
     * call plus one unrelated save silently unmapped a live product.
     */
    const FIELD_PRESENT = '_confirmo_plan_field_present';

    const NOTICE_TRANSIENT = 'confirmo_subscribe_product_notice';

    public static function register(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderPlanField']);
        add_action('woocommerce_product_options_general_product_data', [self::class, 'lockSubscriptionFields'], 20);
        add_action('woocommerce_process_product_meta', [self::class, 'savePlanField']);
        add_action('save_post', [self::class, 'reassertSchedule'], 12);
        add_action('admin_notices', [self::class, 'renderNotice']);
    }

    public static function renderPlanField(): void
    {
        global $post;

        // Other extensions render this panel outside the post screen, where there
        // is no global $post to read the mapping from.
        if (!$post) {
            return;
        }

        $api = new WC_Confirmo_Subscribe_Api();
        $plans = $api->listVariablePlans();
        $current = get_post_meta($post->ID, WC_Confirmo_Subscribe_Plan::META_ID, true);

        echo '<div class="options_group">';

        if (is_wp_error($plans)) {
            echo '<p class="form-field"><strong>' . esc_html__('Confirmo Subscribe', 'confirmo-for-woocommerce') . '</strong> — ' . esc_html__('could not load plans. Check the Confirmo API key in Confirmo Payment settings.', 'confirmo-for-woocommerce') . '</p>';
            echo '</div>';
            return;
        }

        if (empty($plans) && $current === '') {
            echo '<p class="form-field"><strong>' . esc_html__('Confirmo Subscribe', 'confirmo-for-woocommerce') . '</strong> — ' . esc_html__('no variable-price plans found. Create a plan in your Confirmo dashboard with a billing currency and no price, then reload this page.', 'confirmo-for-woocommerce') . '</p>';
            echo '</div>';
            return;
        }

        $options = ['' => __('— Not a subscription —', 'confirmo-for-woocommerce')];
        foreach ($plans as $plan) {
            $options[$plan['id']] = self::planLabel($plan);
        }

        // A since-archived plan keeps its mapping — its subscribers are still
        // billing — so show it rather than resetting to "not a subscription".
        $mapped = null;
        if ($current !== '' && !isset($options[$current])) {
            $mapped = $api->getPlan((string) $current);
            $options[$current] = is_wp_error($mapped)
                ? (string) $current
                : sprintf(
                    /* translators: %s: plan name */
                    __('%s (archived)', 'confirmo-for-woocommerce'),
                    self::planLabel($mapped)
                );
        }

        self::warnAboutMapping($plans, $mapped, (string) $current, (int) $post->ID);

        echo '<input type="hidden" name="' . esc_attr(self::FIELD_PRESENT) . '" value="1">';

        woocommerce_wp_select([
            'id' => WC_Confirmo_Subscribe_Plan::META_ID,
            'label' => __('Confirmo Subscribe plan', 'confirmo-for-woocommerce'),
            'description' => __('Sell this product as a Confirmo Subscribe plan. The plan sets the billing currency and interval; this product sets the price, and the total at checkout is what Confirmo charges every cycle.', 'confirmo-for-woocommerce'),
            'desc_tip' => true,
            'options' => $options,
            'value' => $current,
        ]);

        echo '</div>';
    }

    /**
     * @param array<int, mixed> $plans plans offered in the picker
     * @param array|WP_Error|null $mapped the mapped plan when it is not among them
     */
    private static function warnAboutMapping(array $plans, $mapped, string $planId, int $productId): void
    {
        if ($planId === '') {
            return;
        }

        $planType = WC_Confirmo_Subscribe_Plan::typeFor($productId);

        // An unresolved type is the state a failed plan lookup leaves behind, so
        // it needs a message as much as a fixed-price mapping does.
        if ($planType === '') {
            self::warn(__('This product carries a plan id that was never resolved against Confirmo, so checkout will be refused. Re-select the plan below and save.', 'confirmo-for-woocommerce'));
            return;
        }

        if ($planType !== WC_Confirmo_Subscribe_Plan::TYPE_VARIABLE) {
            self::warn(__('This product is mapped to a fixed-price plan, which this plugin no longer sells. Map it to a plan that has a billing currency and no price, or checkout will be refused.', 'confirmo-for-woocommerce'));
            return;
        }

        $plan = is_array($mapped) ? $mapped : null;
        if ($plan === null) {
            foreach ($plans as $candidate) {
                if (is_array($candidate) && ($candidate['id'] ?? '') === $planId) {
                    $plan = $candidate;
                    break;
                }
            }
        }

        if ($plan === null) {
            return;
        }

        $asset = (string) ($plan['asset'] ?? '');
        if ($asset !== '' && $asset !== get_woocommerce_currency()) {
            self::warn(sprintf(
                /* translators: 1: plan billing asset, 2: store currency */
                __('This plan bills in %1$s but the store currency is %2$s. Order totals would be recorded in %2$s for charges made in %1$s. Use a plan whose asset matches the store currency.', 'confirmo-for-woocommerce'),
                $asset,
                get_woocommerce_currency()
            ));
        }

        if (wc_get_price_decimals() > 2) {
            self::warn(sprintf(
                /* translators: %d: number of decimals configured in WooCommerce */
                __('This store shows prices to %d decimal places, but Confirmo accepts at most 2. Checkout will be refused for totals it cannot represent.', 'confirmo-for-woocommerce'),
                wc_get_price_decimals()
            ));
        }
    }

    private static function warn(string $message): void
    {
        echo '<p class="form-field"><strong style="color:#996800">' . esc_html($message) . '</strong></p>';
    }

    public static function savePlanField($postId): void
    {
        // No picker on the page means this save carries no opinion about the
        // mapping. See FIELD_PRESENT.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the product meta-box nonce before firing this hook.
        if (!isset($_POST[self::FIELD_PRESENT])) {
            return;
        }

        $planId = isset($_POST[WC_Confirmo_Subscribe_Plan::META_ID])
            ? sanitize_text_field(wp_unslash($_POST[WC_Confirmo_Subscribe_Plan::META_ID]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $productId = (int) $postId;

        if ($planId === '') {
            WC_Confirmo_Subscribe_Plan::forget($productId);
            return;
        }

        // Unchanged and resolved: an unrelated save costs no API call and cannot
        // be turned into a refusal by a hiccup at Confirmo.
        if ($planId === WC_Confirmo_Subscribe_Plan::idFor($productId) && WC_Confirmo_Subscribe_Plan::typeFor($productId) !== '') {
            return;
        }

        // Resolve before writing any of it. An id with no type refuses checkout
        // silently; an id beside the *previous* plan's type lets a FIXED plan
        // through the sellable gate and on to an amount the API will reject.
        $plan = (new WC_Confirmo_Subscribe_Api())->getPlan($planId);
        if (is_wp_error($plan)) {
            self::notice(sprintf(
                /* translators: %s: error message returned by the Confirmo API */
                __('Confirmo Subscribe: the selected plan could not be read from Confirmo (%s), so this product\'s plan mapping was left unchanged. Check the API key in Confirmo Payment settings and save again.', 'confirmo-for-woocommerce'),
                $plan->get_error_message()
            ));
            return;
        }

        $billingInterval = (string) ($plan['billingInterval'] ?? '');
        $schedule = WC_Confirmo_Subscribe_Plan::periodFor($billingInterval);
        if ($schedule === null) {
            self::notice(sprintf(
                /* translators: %s: billing interval reported by the Confirmo plan */
                __('Confirmo Subscribe: this plan bills every %s, which this plugin cannot represent in WooCommerce, so the mapping was left unchanged.', 'confirmo-for-woocommerce'),
                $billingInterval !== '' ? $billingInterval : __('(unspecified interval)', 'confirmo-for-woocommerce')
            ));
            return;
        }

        WC_Confirmo_Subscribe_Plan::store($productId, $planId, (string) ($plan['type'] ?? ''), $billingInterval);

        self::writeSchedule($productId, $schedule);
    }

    /**
     * WCS writes its own schedule from `$_POST` on `save_post` at priority 11,
     * and everything hooked to `woocommerce_process_product_meta` has already run
     * by then — WooCommerce fires that whole chain from `save_post` at priority 1.
     * So `savePlanField()` alone loses to the form: mapping a product to an ANNUAL
     * plan wrote `year`, WCS restored the monthly the field still showed, and the
     * store then advertised and renewed monthly while Confirmo billed annually.
     *
     * Re-asserting after WCS closes that window. Only the first save is exposed —
     * from then on `lockSubscriptionFields()` has the form resubmitting these
     * values — but that is every newly mapped product.
     *
     * Reads nothing from the request, only the mapping already stored, so it needs
     * no nonce of its own.
     */
    public static function reassertSchedule($postId): void
    {
        $productId = (int) $postId;

        if (wp_is_post_autosave($productId) || wp_is_post_revision($productId)) {
            return;
        }
        if (get_post_type($productId) !== 'product') {
            return;
        }

        $schedule = WC_Confirmo_Subscribe_Plan::periodFor(WC_Confirmo_Subscribe_Plan::intervalFor($productId));
        if ($schedule === null) {
            return;
        }

        self::writeSchedule($productId, $schedule);
    }

    /**
     * @param array{0: string, 1: int} $schedule
     */
    private static function writeSchedule(int $productId, array $schedule): void
    {
        update_post_meta($productId, '_subscription_period', $schedule[0]);
        update_post_meta($productId, '_subscription_period_interval', (string) $schedule[1]);
        // Anything else would be WooCommerce inventing terms the mandate does
        // not carry.
        update_post_meta($productId, '_subscription_sign_up_fee', '0');
        update_post_meta($productId, '_subscription_trial_length', '0');
        update_post_meta($productId, '_subscription_length', '0');
    }

    private static function notice(string $message): void
    {
        set_transient(self::NOTICE_TRANSIENT . '_' . get_current_user_id(), $message, 120);
    }

    public static function renderNotice(): void
    {
        $key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
        $message = get_transient($key);

        if (!is_string($message) || $message === '') {
            return;
        }

        delete_transient($key);
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    /** The price is deliberately not among them: this product owns it. */
    public static function lockSubscriptionFields(): void
    {
        global $post;

        if (!$post || WC_Confirmo_Subscribe_Plan::idFor((int) $post->ID) === '') {
            return;
        }

        $locked = ['_subscription_period', '_subscription_period_interval', '_subscription_length',
                   '_subscription_sign_up_fee', '_subscription_trial_length', '_subscription_trial_period'];

        // A disabled field is not submitted at all, so WooCommerce would read it
        // as empty and wipe the value on the next save. Lock it instead.
        echo '<script>
            document.addEventListener("DOMContentLoaded", function () {
                ' . wp_json_encode($locked) . '.forEach(function (id) {
                    var field = document.getElementById(id);
                    if (!field) { return; }
                    field.style.pointerEvents = "none";
                    field.style.backgroundColor = "#f0f0f1";
                    if (field.tagName === "SELECT") {
                        var locked = field.value;
                        field.addEventListener("change", function () { field.value = locked; });
                    } else {
                        field.readOnly = true;
                    }
                });
            });
        </script>';

        echo '<p class="form-field"><em>' . esc_html__('The billing interval is set by the Confirmo plan. This product owns its price, and the total at checkout is what Confirmo charges every cycle.', 'confirmo-for-woocommerce') . '</em></p>';
    }

    private static function planLabel(array $plan): string
    {
        $name = $plan['name'] ?? $plan['id'];
        $interval = strtolower((string) ($plan['billingInterval'] ?? ''));
        $asset = (string) ($plan['asset'] ?? '');

        if (($plan['type'] ?? '') !== WC_Confirmo_Subscribe_Plan::TYPE_VARIABLE) {
            return sprintf('%s (fixed price — not supported)', $name);
        }

        return sprintf('%s (%s / %s)', $name, $asset, $interval);
    }
}
