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
        $this->supports = ['products'];
        
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
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
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
        
        $order->update_status('on-hold', __('Awaiting payment via CentryOS', 'centryos-payment-gateway-for-woocommerce'));
        wc_reduce_stock_levels($order_id);
        
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
        return [
            'currency' => get_woocommerce_currency(),
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
     * Thank you page
     */
    public function thankyou_page() {
        echo '<p>' . esc_html__('Thank you! You were redirected to CentryOS to complete payment.', 'centryos-payment-gateway-for-woocommerce') . '</p>';
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