<?php
/**
 * CentryOS Payment Gateway Class
 *
 * @package CentryOS_Gateway
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CentryOS_Gateway extends WC_Payment_Gateway {
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'centryos_gateway';
        $this->method_title = __('CentryOS Payment Gateway', 'centryos-payment-gateway-for-woocommerce');
        $this->method_description = __('Accept payments via CentryOS hosted payment links.', 'centryos-payment-gateway-for-woocommerce');
        $this->has_fields = false;
        $this->supports = [ 'products', 'refunds' ];
        
        $this->init_form_fields();
        $this->init_settings();
        
        $this->title = $this->get_option('title', __('Pay with CentryOS', 'centryos-payment-gateway-for-woocommerce'));
        $this->description = $this->get_option('description', '');
        $this->client_id = $this->get_credential('client_id', 'CENTRYOS_CLIENT_ID');
        $this->secret = $this->get_credential('secret', 'CENTRYOS_API_SECRET');
        $this->webhook_secret = $this->get_credential('webhook_secret', 'CENTRYOS_WEBHOOK_SECRET');
        $this->api_url = $this->get_option('api_url', 'staging');
        $this->customer_pays = $this->get_option('customer_pays', 'yes');
        $payment_options = $this->get_option('payment_options', ['card', 'google_pay', 'apple_pay']);
        $this->payment_options = is_array($payment_options) ? $payment_options : (array) $payment_options;
        $this->hide_centry_tag = $this->get_option('hide_centry_tag', 'no');
        $this->color_default = $this->get_option('color_default', '#9333EA');
        $this->color_subdued = $this->get_option('color_subdued', '#A855F7');
        $this->color_disabled = $this->get_option('color_disabled', '#E9D5FF');
        $this->color_pale = $this->get_option('color_pale', '#F3E8FF');
        $this->checkout_mode = $this->get_option('checkout_mode', 'redirect');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
        add_action('wp_ajax_centryos_check_order_status', [$this, 'ajax_check_order_status']);
        add_action('wp_ajax_nopriv_centryos_check_order_status', [$this, 'ajax_check_order_status']);
    }
    
    /**
     * Get credential from wp-config.php or settings
     */
    private function get_credential($setting_key, $constant_name) {
        if (defined($constant_name)) {
            return constant($constant_name);
        }
        return $this->get_option($setting_key, '');
    }
    
    /**
     * Initialize form fields
     */
    public function init_form_fields() {
      $this->form_fields = [
          'enabled' => [
              'title' => 'Enable/Disable',
              'type'  => 'checkbox',
              'label' => 'Enable CentryOS Payment Gateway',
              'default' => 'yes'
          ],
          'title' => [
              'title' => 'Title',
              'type'  => 'text',
              'default' => 'Pay with CentryOS'
          ],
          'description' => [
              'title' => 'Description',
              'type'  => 'textarea',
              'default' => ''
          ],
          'client_id' => [
              'title' => 'Client ID',
              'type'  => 'text',
              'default' => ''
          ],
          'api_url' => [
              'title' => 'API Environment',
              'type'  => 'select',
              'options' => [
                  'staging' => 'Staging',
                  'production' => 'Production'
              ],
              'default' => 'staging',
              'description' => 'Select the API environment to use'
          ],
          'checkout_mode' => [
              'title' => 'Checkout Mode',
              'type'  => 'select',
              'options' => [
                  'redirect' => 'Redirect to CentryOS (default)',
                  'embedded' => 'Embedded on order page',
              ],
              'default' => 'redirect',
              'description' => 'Embedded keeps the buyer on your site inside an iframe. Requires CentryOS framing to be enabled for your domain.',
          ],
          'customer_pays' => [
              'title' => 'Customer Pays',
              'type'  => 'checkbox',
              'label' => 'Customer pays for processing fees',
              'default' => 'yes'
          ],
          'payment_options' => [
              'title' => 'Payment Options',
              'type'  => 'multiselect',
              'options' => [
                  'apple_pay'  => 'Apple Pay',
                  'card'       => 'Card',
                  'cashapp'    => 'Cash App',
                  'google_pay' => 'Google Pay'
              ],
              'default' => ['card', 'google_pay', 'apple_pay']
          ],
          'hide_centry_tag' => [
              'title' => 'Hide Centry Tag',
              'type'  => 'checkbox',
              'label' => 'Hide CentryOS tag on payment page',
              'default' => 'no'
          ],
          'color_default' => [
              'title' => 'Default Color',
              'type'  => 'color',
              'default' => '#00B2A9',
              'description' => 'Default color for payment elements'
          ],
          'color_subdued' => [
              'title' => 'Subdued Color',
              'type'  => 'color',
              'default' => '#4084B5',
              'description' => 'Subdued color for payment elements'
          ],
          'color_disabled' => [
              'title' => 'Disabled Color',
              'type'  => 'color',
              'default' => '#E2F1FC',
              'description' => 'Disabled color for payment elements'
          ],
          'color_pale' => [
              'title' => 'Pale Color',
              'type'  => 'color',
              'default' => '#E4EFFA',
              'description' => 'Pale color for payment elements'
          ],
      ];
    }

    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        $api_client = new CentryOS_API_Client($this->client_id, $this->secret, $this->api_url);
        $payload = $this->build_payload($order);
        $payment_url = $api_client->create_payment_link($payload);
        
        if (is_wp_error($payment_url)) {
            wc_add_notice(
                __('Payment error: ', 'centryos-payment-gateway-for-woocommerce') . $payment_url->get_error_message(),
                'error'
            );
            return ['result' => 'failure'];
        }
         
        // Add customer information as query strings
        $payment_url = $this->add_customer_query_strings($payment_url, $order);

        wc_reduce_stock_levels($order_id);

        if ($this->checkout_mode === 'embedded') {
            // Force 'pending' so WC_Order::needs_payment() stays true and the
            // woocommerce_receipt_{$id} hook fires on the order-pay page. The
            // webhook handler progresses the order to processing/completed.
            $order->update_status('pending', __('Awaiting payment via embedded CentryOS checkout', 'centryos-payment-gateway-for-woocommerce'));
            $order->update_meta_data('_centryos_payment_url', $payment_url);
            $order->save();

            return [
                'result'   => 'success',
                'redirect' => $order->get_checkout_payment_url(true),
            ];
        }

        $order->update_status('pending', __('Awaiting payment via CentryOS', 'centryos-payment-gateway-for-woocommerce'));

        return [
            'result' => 'success',
            'redirect' => $payment_url
        ];
    }
    
    /**
     * Build payment payload
     * Do not change the payload structure
     */
    private function build_payload($order) {
        $currency = get_woocommerce_currency();

        return [
            'currency' => $currency,
            'expiredAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 day')),
            // translators: %s: Order number
            'name' => sprintf(__('Order #%s', 'centryos-payment-gateway-for-woocommerce'), $order->get_order_number()),
            'amount' => floatval($order->get_total()),
            'amountLocked' => true,
            'redirectTo' => $this->get_return_url($order),
            'isOpenLink' => false,
            'orderId' => (string)$order->get_id(),
            'customerPays' => ($this->customer_pays === 'yes'),
            'acceptedPaymentOptions' => $this->payment_options,
            'dataCollections' => ['Email', 'First name', 'Last name', 'Phone number'],
            'itemDeliveryAddress' => $this->build_item_delivery_address($order),
            'cartItems' => $this->build_cart_items($order, $currency),
            'brandingConfig' => [
                'hideCentryTag' => ($this->hide_centry_tag === 'yes'),
                'colors' => [
                    'default' => $this->color_default,
                    'subdued' => $this->color_subdued,
                    'disabled' => $this->color_disabled,
                    'pale' => $this->color_pale
                ]
            ]

        ];
    }

    /**
     * Build a comma-separated delivery address from the order's shipping address,
     * falling back to the billing address when shipping is empty.
     */
    private function build_item_delivery_address($order) {
        $type = $order->get_shipping_address_1() || $order->get_shipping_city() ? 'shipping' : 'billing';

        $parts = array_filter([
            $type === 'shipping' ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
            $type === 'shipping' ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
            $type === 'shipping' ? $order->get_shipping_city()      : $order->get_billing_city(),
            $type === 'shipping' ? $order->get_shipping_state()     : $order->get_billing_state(),
            $type === 'shipping' ? $order->get_shipping_postcode()  : $order->get_billing_postcode(),
            $type === 'shipping' ? $order->get_shipping_country()   : $order->get_billing_country(),
        ]);

        return implode(', ', $parts);
    }

    /**
     * Build cart items array from order line items
     */
    private function build_cart_items($order, $currency) {
        $items = [];

        foreach ($order->get_items() as $item) {
            $product     = $item->get_product();
            $quantity    = $item->get_quantity();
            $line_total  = floatval($item->get_total());
            $unit_price  = $quantity > 0 ? $line_total / $quantity : $line_total;
            $description = '';
            if ($product) {
                $description = wp_strip_all_tags($product->get_short_description());
                if ($description === '') {
                    $description = wp_strip_all_tags($product->get_description());
                }
            }
            if ($description === '') {
                $description = $item->get_name();
            }

            $items[] = [
                'name'        => $item->get_name(),
                'description' => $description,
                'price'       => round($unit_price, 2),
                'currency'    => $currency,
            ];
        }

        return $items;
    }
    
    /**
     * Add customer information as query strings to payment URL
     */
    private function add_customer_query_strings($payment_url, $order) {
        // Get customer information from order
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();
        $email = $order->get_billing_email();
        $phone = $order->get_billing_phone();
        $shipping_country = $order->get_shipping_country(); // ISO2 country code
        
        // Build query parameters array
        $query_params = [];
        
        if (!empty($first_name)) {
            $query_params['firstName'] = urlencode($first_name);
        }
        if (!empty($last_name)) {
            $query_params['lastName'] = urlencode($last_name);
        }
        if (!empty($email)) {
            $query_params['email'] = urlencode($email);
        }
        if (!empty($phone)) {
            $query_params['phone'] = urlencode($phone);
        }
        if (!empty($shipping_country)) {
            $query_params['shippingCountry'] = urlencode($shipping_country);
        }
        
        // Add query strings to URL
        if (!empty($query_params)) {
            $payment_url = add_query_arg($query_params, $payment_url);
        }
        
        return $payment_url;
    }
    
    /**
     * Process refund
     *
     * @param int    $order_id Order ID
     * @param float  $amount   Refund amount
     * @param string $reason   Refund reason
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'invalid_order', __( 'Order not found.', 'centryos-payment-gateway-for-woocommerce' ) );
        }

        $transaction_id = $order->get_meta( '_centryos_transaction_id' );

        if ( empty( $transaction_id ) ) {
            return new WP_Error( 'no_transaction_id', __( 'No CentryOS transaction ID found for this order.', 'centryos-payment-gateway-for-woocommerce' ) );
        }

        $api_client = new CentryOS_API_Client( $this->client_id, $this->secret, $this->api_url );
        $result     = $api_client->create_refund( $transaction_id, $amount, $reason );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $note = sprintf(
            // translators: 1: refund amount with currency, 2: original transaction ID
            __( 'Refund of %1$s submitted to CentryOS for transaction %2$s.', 'centryos-payment-gateway-for-woocommerce' ),
            wc_price( $amount ),
            $transaction_id
        );

        if ( ! empty( $result['refundId'] ) ) {
            $order->add_meta_data( '_centryos_refund_id', $result['refundId'], false );
            $order->save();
            // translators: refund transaction ID returned by CentryOS
            $note .= ' ' . sprintf( __( 'Refund ID: %s', 'centryos-payment-gateway-for-woocommerce' ), $result['refundId'] );
        }

        $order->add_order_note( $note );

        return true;
    }

    /**
     * Render the embedded CentryOS checkout on the WooCommerce order-pay page.
     * Hooked into woocommerce_receipt_{$gateway_id}; only fires when checkout_mode = embedded.
     */
    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $payment_url = $order->get_meta('_centryos_payment_url');
        if (!$payment_url) {
            echo '<p>' . esc_html__('Payment session is missing. Please return to checkout and try again.', 'centryos-payment-gateway-for-woocommerce') . '</p>';
            echo '<p><a class="button" href="' . esc_url(wc_get_checkout_url()) . '">' . esc_html__('Return to checkout', 'centryos-payment-gateway-for-woocommerce') . '</a></p>';
            return;
        }

        $scheme = wp_parse_url($payment_url, PHP_URL_SCHEME);
        $host   = wp_parse_url($payment_url, PHP_URL_HOST);
        $centryos_origin = ($scheme && $host) ? $scheme . '://' . $host : '';

        wp_enqueue_script(
            'centryos-embed',
            CENTRYOS_GATEWAY_PLUGIN_URL . 'assets/js/centryos-embed.js',
            [],
            CENTRYOS_GATEWAY_VERSION,
            true
        );

        wp_localize_script('centryos-embed', 'CentryOSEmbed', [
            'orderId'        => $order->get_id(),
            'returnUrl'      => $this->get_return_url($order),
            'statusEndpoint' => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('centryos_check_order_status_' . $order->get_id()),
            'centryosOrigin' => $centryos_origin,
            'pollIntervalMs' => 2500,
            'maxPollAttempts' => 240,
        ]);

        ?>
        <style>
            #centryos-embed-wrapper {
                width: 100%;
                max-width: 100%;
                margin: 1em 0;
            }
            #centryos-embed-frame {
                display: block;
                width: 100%;
                height: 100vh;
                /* Floor for very short viewports; vh keeps it responsive otherwise. */
                min-height: 520px;
                border: 0;
            }
        </style>
        <div id="centryos-embed-wrapper">
            <iframe id="centryos-embed-frame"
                    src="<?php echo esc_url($payment_url); ?>"
                    allow="payment *"
                    scrolling="auto"></iframe>
        </div>
        <?php
    }

    /**
     * AJAX handler the embedded iframe polls to detect webhook-driven payment completion.
     * Returns minimal JSON: { status, paid }. No PII.
     */
    public function ajax_check_order_status() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $nonce    = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';

        if (!$order_id || !wp_verify_nonce($nonce, 'centryos_check_order_status_' . $order_id)) {
            wp_send_json_error(['message' => 'invalid_request'], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'order_not_found'], 404);
        }

        wp_send_json_success([
            'status' => $order->get_status(),
            'paid'   => $order->is_paid(),
        ]);
    }

    /**
     * Thank you page
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        if ($order && $order->has_status('failed')) {
            echo '<p>' . esc_html__('Your payment was not completed. Please try again or contact support.', 'centryos-payment-gateway-for-woocommerce') . '</p>';
        } else {
            echo '<p>' . esc_html__('Thank you! You were redirected to CentryOS to complete payment.', 'centryos-payment-gateway-for-woocommerce') . '</p>';
        }
    }
    
/**
     * Display payment fields
     */
    public function payment_fields() {
        if ($this->description) {
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }
    }
}