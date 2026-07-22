<?php
/**
 * CentryOS Product Subscription Configuration
 *
 * Adds a "CentryOS Subscription" product-data tab so merchants can tag a simple
 * product as subscription-based and configure its recurring rate, billing
 * period/interval and trial length.
 *
 * @package CentryOS_Gateway
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CentryOS_Product_Subscription {

    const META_ENABLED      = '_centryos_is_subscription';
    const META_PRICE        = '_centryos_sub_price';
    const META_PERIOD       = '_centryos_sub_period';
    const META_INTERVAL     = '_centryos_sub_interval';
    const META_TRIAL        = '_centryos_sub_trial';
    const META_TRIAL_PERIOD = '_centryos_sub_trial_period';

    /**
     * Approximate day-equivalents per period, used to convert a free-trial
     * interval (value + unit) into the day count the liquidity-service expects.
     */
    const PERIOD_DAYS = [
        'day'   => 1,
        'week'  => 7,
        'month' => 30,
        'year'  => 365,
    ];

    /**
     * Register hooks.
     */
    public static function init() {
        add_filter('woocommerce_product_data_tabs', [__CLASS__, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [__CLASS__, 'render_panel']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_fields']);

        // Guardrails: at most one subscription product per cart (Stripe allows a
        // single subscription per checkout). Mixing with one-time products is OK.
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 3);
        add_action('woocommerce_check_cart_items', [__CLASS__, 'validate_cart_items']);

        // Relabel the add-to-cart button for subscription products (single product
        // page + shop/archive loop).
        add_filter('woocommerce_product_single_add_to_cart_text', [__CLASS__, 'add_to_cart_text'], 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', [__CLASS__, 'add_to_cart_text'], 10, 2);
    }

    /**
     * Use "Add subscription to cart" for subscription products.
     *
     * @param string          $text
     * @param WC_Product|null $product
     * @return string
     */
    public static function add_to_cart_text($text, $product = null) {
        if ($product && self::is_subscription_product($product)) {
            return __('Add subscription to cart', 'centryos-payment-gateway-for-woocommerce');
        }
        return $text;
    }

    /**
     * Allowed billing periods (keys map directly to the liquidity-service
     * interval type; labels are plural to match the unit dropdowns).
     */
    public static function periods() {
        return [
            'day'   => __('Days', 'centryos-payment-gateway-for-woocommerce'),
            'week'  => __('Weeks', 'centryos-payment-gateway-for-woocommerce'),
            'month' => __('Months', 'centryos-payment-gateway-for-woocommerce'),
            'year'  => __('Years', 'centryos-payment-gateway-for-woocommerce'),
        ];
    }

    /**
     * Add the CentryOS Subscription tab to the product data metabox.
     */
    public static function add_product_tab($tabs) {
        $tabs['centryos_subscription'] = [
            'label'    => __('CentryOS Subscription', 'centryos-payment-gateway-for-woocommerce'),
            'target'   => 'centryos_subscription_data',
            // Subscriptions are only supported for simple products in this release.
            'class'    => ['show_if_simple'],
            'priority' => 65,
        ];
        return $tabs;
    }

    /**
     * Render the subscription configuration panel.
     */
    public static function render_panel() {
        global $post;
        $pid = isset($post->ID) ? (int) $post->ID : 0;

        $interval     = get_post_meta($pid, self::META_INTERVAL, true);
        $period       = get_post_meta($pid, self::META_PERIOD, true) ?: 'month';
        $trial        = get_post_meta($pid, self::META_TRIAL, true);
        $trial_period = get_post_meta($pid, self::META_TRIAL_PERIOD, true) ?: 'day';
        ?>
        <div id="centryos_subscription_data" class="panel woocommerce_options_panel hidden">
            <?php
            woocommerce_wp_checkbox([
                'id'          => self::META_ENABLED,
                'label'       => __('Subscription product', 'centryos-payment-gateway-for-woocommerce'),
                'description' => __('Bill this product on a recurring schedule via CentryOS.', 'centryos-payment-gateway-for-woocommerce'),
            ]);

            woocommerce_wp_text_input([
                'id'          => self::META_PRICE,
                'label'       => sprintf(
                    // translators: %s: shop currency symbol
                    __('Recurring rate (%s)', 'centryos-payment-gateway-for-woocommerce'),
                    get_woocommerce_currency_symbol()
                ),
                'desc_tip'    => true,
                'description' => __('Amount charged each billing cycle. Leave blank to use the product price.', 'centryos-payment-gateway-for-woocommerce'),
                'data_type'   => 'price',
            ]);

            // "Subscriptions Per Interval" — value + unit (the billing cycle).
            self::render_value_unit_field(
                __('Subscriptions Per Interval', 'centryos-payment-gateway-for-woocommerce'),
                self::META_INTERVAL,
                $interval,
                self::META_PERIOD,
                $period,
                [
                    'min'         => 1,
                    'placeholder' => '1',
                    'help'        => __('How often the customer is charged, e.g. 1 Month = monthly.', 'centryos-payment-gateway-for-woocommerce'),
                ]
            );

            // "Free trial interval" — value + unit (0/blank = no trial).
            self::render_value_unit_field(
                __('Free trial interval', 'centryos-payment-gateway-for-woocommerce'),
                self::META_TRIAL,
                $trial,
                self::META_TRIAL_PERIOD,
                $trial_period,
                [
                    'min'         => 0,
                    'placeholder' => __('Enter free trial interval', 'centryos-payment-gateway-for-woocommerce'),
                    'help'        => __('Free trial length before the first charge. Leave blank or 0 for no trial.', 'centryos-payment-gateway-for-woocommerce'),
                ]
            );
            ?>
        </div>
        <?php
    }

    /**
     * Render a paired "value + unit" form field (a number input followed by a
     * period dropdown), mirroring the reference subscription UI layout.
     */
    private static function render_value_unit_field($label, $value_id, $value, $unit_id, $unit_value, array $opts = []) {
        $min  = isset($opts['min']) ? (int) $opts['min'] : 0;
        $help = $opts['help'] ?? '';
        ?>
        <p class="form-field <?php echo esc_attr($value_id); ?>_field">
            <label for="<?php echo esc_attr($value_id); ?>"><?php echo esc_html($label); ?></label>
            <input type="number"
                   class="short"
                   style="width:48%;"
                   id="<?php echo esc_attr($value_id); ?>"
                   name="<?php echo esc_attr($value_id); ?>"
                   value="<?php echo esc_attr($value); ?>"
                   min="<?php echo esc_attr($min); ?>"
                   step="1"
                   placeholder="<?php echo esc_attr($opts['placeholder'] ?? ''); ?>" />
            <select class="select short"
                    style="width:30%;margin-left:5px;"
                    id="<?php echo esc_attr($unit_id); ?>"
                    name="<?php echo esc_attr($unit_id); ?>">
                <?php foreach (self::periods() as $key => $plabel) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($unit_value, $key); ?>><?php echo esc_html($plabel); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($help !== '') {
                echo wc_help_tip($help); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } ?>
        </p>
        <?php
    }

    /**
     * Persist subscription fields on product save.
     */
    public static function save_fields($post_id) {
        // Nonce is verified by WooCommerce before this hook fires.
        $enabled = isset($_POST[self::META_ENABLED]) ? 'yes' : 'no';
        update_post_meta($post_id, self::META_ENABLED, $enabled);

        if ($enabled !== 'yes') {
            return;
        }

        $price = isset($_POST[self::META_PRICE]) ? wc_clean(wp_unslash($_POST[self::META_PRICE])) : '';
        update_post_meta($post_id, self::META_PRICE, $price === '' ? '' : wc_format_decimal($price));

        $period = isset($_POST[self::META_PERIOD]) ? sanitize_text_field(wp_unslash($_POST[self::META_PERIOD])) : 'month';
        if (!array_key_exists($period, self::periods())) {
            $period = 'month';
        }
        update_post_meta($post_id, self::META_PERIOD, $period);

        $interval = isset($_POST[self::META_INTERVAL]) ? absint($_POST[self::META_INTERVAL]) : 1;
        update_post_meta($post_id, self::META_INTERVAL, max(1, $interval));

        $trial = isset($_POST[self::META_TRIAL]) ? absint($_POST[self::META_TRIAL]) : 0;
        update_post_meta($post_id, self::META_TRIAL, $trial);

        $trial_period = isset($_POST[self::META_TRIAL_PERIOD]) ? sanitize_text_field(wp_unslash($_POST[self::META_TRIAL_PERIOD])) : 'day';
        if (!array_key_exists($trial_period, self::periods())) {
            $trial_period = 'day';
        }
        update_post_meta($post_id, self::META_TRIAL_PERIOD, $trial_period);
    }

    /**
     * Whether a product is configured as a CentryOS subscription.
     *
     * @param WC_Product|int $product
     * @return bool
     */
    public static function is_subscription_product($product) {
        $product = is_numeric($product) ? wc_get_product($product) : $product;
        if (!$product instanceof WC_Product) {
            return false;
        }
        return $product->get_meta(self::META_ENABLED) === 'yes';
    }

    /**
     * Normalized subscription config for a product.
     *
     * @param WC_Product|int $product
     * @return array{price:float,period:string,interval:int,trial_days:int}|null
     */
    public static function get_config($product) {
        $product = is_numeric($product) ? wc_get_product($product) : $product;
        if (!$product instanceof WC_Product || !self::is_subscription_product($product)) {
            return null;
        }

        $price = $product->get_meta(self::META_PRICE);
        $price = ($price === '' || $price === null) ? floatval($product->get_price()) : floatval($price);

        $period = $product->get_meta(self::META_PERIOD);
        if (!array_key_exists($period, self::periods())) {
            $period = 'month';
        }

        $interval = max(1, absint($product->get_meta(self::META_INTERVAL)));

        // Free trial: stored as a value + unit; convert to the day count the
        // liquidity-service expects (trialPeriodDays).
        $trial_value  = absint($product->get_meta(self::META_TRIAL));
        $trial_period = $product->get_meta(self::META_TRIAL_PERIOD);
        if (!array_key_exists($trial_period, self::periods())) {
            $trial_period = 'day';
        }
        $trial_days = $trial_value * (self::PERIOD_DAYS[$trial_period] ?? 1);

        return [
            'price'      => $price,
            'period'     => $period,
            'interval'   => $interval,
            'trial_days' => $trial_days,
        ];
    }

    /**
     * Block adding a second subscription product to the cart.
     *
     * @param bool $passed
     * @param int  $product_id
     * @return bool
     */
    public static function validate_add_to_cart($passed, $product_id, $quantity = 1) {
        if (!$passed || !self::is_subscription_product($product_id)) {
            return $passed;
        }

        if (self::cart_has_subscription()) {
            wc_add_notice(
                __('You can only purchase one subscription at a time. Please complete this subscription separately.', 'centryos-payment-gateway-for-woocommerce'),
                'error'
            );
            return false;
        }

        return $passed;
    }

    /**
     * Block checkout if the cart somehow holds more than one subscription product.
     */
    public static function validate_cart_items() {
        if (self::cart_subscription_count() > 1) {
            wc_add_notice(
                __('Your cart contains more than one subscription. Please purchase subscriptions one at a time.', 'centryos-payment-gateway-for-woocommerce'),
                'error'
            );
        }
    }

    /**
     * Whether the current cart already contains a subscription product.
     */
    public static function cart_has_subscription() {
        return self::cart_subscription_count() > 0;
    }

    /**
     * Count subscription products currently in the cart.
     */
    public static function cart_subscription_count() {
        if (!function_exists('WC') || !WC()->cart) {
            return 0;
        }

        $count = 0;
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['product_id']) && self::is_subscription_product($cart_item['product_id'])) {
                $count++;
            }
        }
        return $count;
    }
}
