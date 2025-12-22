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
        
        // Meta Pixel settings
        $this->enable_meta_pixel = $this->get_option('enable_meta_pixel', 'no');
        $this->meta_pixel_id = $this->get_option('meta_pixel_id', '');
        $this->meta_pixel_advanced_matching = $this->get_option('meta_pixel_advanced_matching', 'yes');
        $this->meta_pixel_track_initiate_checkout = $this->get_option('meta_pixel_track_initiate_checkout', 'yes');
        $this->meta_pixel_track_add_payment_info = $this->get_option('meta_pixel_track_add_payment_info', 'yes');
        $this->meta_pixel_track_purchase = $this->get_option('meta_pixel_track_purchase', 'yes');
        
        // Initialize Meta Pixel if enabled
        if ($this->enable_meta_pixel === 'yes' && !empty($this->meta_pixel_id)) {
            require_once CENTRYOS_GATEWAY_PLUGIN_DIR . 'includes/class-centryos-pixel-tracker.php';
            new CentryOS_Pixel_Tracker(
                $this->meta_pixel_id,
                $this->meta_pixel_advanced_matching === 'yes',
                [
                    'initiate_checkout' => $this->meta_pixel_track_initiate_checkout === 'yes',
                    'add_payment_info' => $this->meta_pixel_track_add_payment_info === 'yes',
                    'purchase' => $this->meta_pixel_track_purchase === 'yes'
                ]
            );
        }
        
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_modal_scripts']);
        add_action('wp_ajax_centryos_get_payment_url', [$this, 'ajax_get_payment_url']);
        add_action('wp_ajax_nopriv_centryos_get_payment_url', [$this, 'ajax_get_payment_url']);
        add_action('wp_ajax_centryos_check_payment_status', [$this, 'ajax_check_payment_status']);
        add_action('wp_ajax_nopriv_centryos_check_payment_status', [$this, 'ajax_check_payment_status']);
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
     * Validate Meta Pixel ID
     */
    public function validate_meta_pixel_id_field($key, $value) {
        $enable_pixel = isset($_POST['woocommerce_centryos_gateway_enable_meta_pixel']) ? 'yes' : 'no';
        
        if ($enable_pixel === 'yes' && empty($value)) {
            WC_Admin_Settings::add_error(__('Meta Pixel ID is required when Meta Pixel is enabled.', 'centryos-payment-gateway-for-woocommerce'));
            return '';
        }
        
        if (!empty($value) && !preg_match('/^[0-9]{15,16}$/', $value)) {
            WC_Admin_Settings::add_error(__('Meta Pixel ID must be 15-16 digits.', 'centryos-payment-gateway-for-woocommerce'));
            return '';
        }
        
        return $value;
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
              'default' => '',
              'description' => __('Enter your CentryOS Client ID. For security, you can also set this in wp-config.php as CENTRYOS_CLIENT_ID constant.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'secret' => [
              'title' => 'API Secret',
              'type'  => 'password',
              'default' => '',
              'description' => __('Enter your CentryOS API Secret. For security, you can also set this in wp-config.php as CENTRYOS_API_SECRET constant.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'webhook_url' => [
              'title' => 'Webhook URL',
              'type'  => 'text',
              'default' => 'https://f7c6d0f0af0d.ngrok-free.app/wordpress/wp-json/v1/wh/centryos/payment-complete',
              'description' => __('Copy this webhook URL and configure it in your CentryOS dashboard.', 'centryos-payment-gateway-for-woocommerce'),
              'custom_attributes' => [
                  'readonly' => 'readonly'
              ]
          ],
          'webhook_secret' => [
              'title' => 'Webhook Secret',
              'type'  => 'password',
              'default' => '',
              'description' => __('Enter your CentryOS Webhook Secret for signature verification. For security, you can also set this in wp-config.php as CENTRYOS_WEBHOOK_SECRET constant.', 'centryos-payment-gateway-for-woocommerce')
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
          
          // Meta Pixel Settings Section
          'meta_pixel_section' => [
              'title' => __('Meta Pixel Settings', 'centryos-payment-gateway-for-woocommerce'),
              'type'  => 'title',
              'description' => __('Configure Meta Pixel (Facebook Pixel) for retargeting and conversion tracking.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'enable_meta_pixel' => [
              'title' => __('Enable Meta Pixel', 'centryos-payment-gateway-for-woocommerce'),
              'type'  => 'checkbox',
              'label' => __('Enable Meta Pixel tracking', 'centryos-payment-gateway-for-woocommerce'),
              'default' => 'no',
              'description' => __('Track checkout and purchase events for retargeting campaigns.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'meta_pixel_id' => [
              'title' => __('Meta Pixel ID', 'centryos-payment-gateway-for-woocommerce'),
              'type'  => 'text',
              'default' => '',
              'description' => __('Enter your Meta Pixel ID (e.g., 123456789012345). Find it in your Meta Events Manager.', 'centryos-payment-gateway-for-woocommerce'),
              'placeholder' => '123456789012345',
              'custom_attributes' => [
                  'pattern' => '[0-9]{15,16}',
                  'maxlength' => '16'
              ]
          ],
          'meta_pixel_advanced_matching' => [
              'title' => __('Advanced Matching', 'centryos-payment-gateway-for-woocommerce'),
              'type'  => 'checkbox',
              'label' => __('Enable advanced matching (email, phone, etc.)', 'centryos-payment-gateway-for-woocommerce'),
              'default' => 'yes',
              'description' => __('Send customer data (email, phone) to Meta for better matching. Requires privacy compliance.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'meta_pixel_track_initiate_checkout' => [
              'title' => __('Track Events', 'centryos-payment-gateway-for-woocommerce'),
              'type'  => 'checkbox',
              'label' => __('Track InitiateCheckout event', 'centryos-payment-gateway-for-woocommerce'),
              'default' => 'yes',
              'description' => __('Fire InitiateCheckout when customer reaches checkout page.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'meta_pixel_track_add_payment_info' => [
              'type'  => 'checkbox',
              'label' => __('Track AddPaymentInfo event', 'centryos-payment-gateway-for-woocommerce'),
              'default' => 'yes',
              'description' => __('Fire AddPaymentInfo when customer selects CentryOS payment method.', 'centryos-payment-gateway-for-woocommerce')
          ],
          'meta_pixel_track_purchase' => [
              'type'  => 'checkbox',
              'label' => __('Track Purchase event', 'centryos-payment-gateway-for-woocommerce'),
              'default' => 'yes',
              'description' => __('Fire Purchase event when payment is completed successfully.', 'centryos-payment-gateway-for-woocommerce')
          ],
      ];
    }

    
    /**
     * Process payment
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        // Store order ID in session for modal
        if (WC()->session) {
            WC()->session->set('centryos_order_id', $order_id);
        }
        
        $order->update_status('on-hold', __('Awaiting payment via CentryOS', 'centryos-payment-gateway-for-woocommerce'));
        wc_reduce_stock_levels($order_id);
        
        // Return success without redirect - modal will handle payment URL generation
        return [
            'result' => 'success',
            'redirect' => '' // Empty redirect triggers modal
        ];
    }
    
    /**
     * AJAX handler to get payment URL for modal
     */
    public function ajax_get_payment_url() {
        check_ajax_referer('centryos-payment-nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'centryos-payment-gateway-for-woocommerce')]);
        }
        
        // Verify order belongs to current user or is a guest order (for security)
        $current_user_id = get_current_user_id();
        $order_user_id = $order->get_user_id();
        
        // Allow if: user is admin, order belongs to current user, or both are guest (0)
        if (!current_user_can('manage_woocommerce')) {
            if ($order_user_id !== $current_user_id && ($order_user_id !== 0 || $current_user_id !== 0)) {
                // Additional check: verify order was created recently (within last 10 minutes) for guest orders
                $order_date = $order->get_date_created();
                if ($order_date) {
                    $order_timestamp = $order_date->getTimestamp();
                    $current_timestamp = current_time('timestamp');
                    if (($current_timestamp - $order_timestamp) > 600) { // 10 minutes
                        wp_send_json_error(['message' => __('Unauthorized access.', 'centryos-payment-gateway-for-woocommerce')]);
                    }
                } else {
                    wp_send_json_error(['message' => __('Unauthorized access.', 'centryos-payment-gateway-for-woocommerce')]);
                }
            }
        }
        
        $api_client = new CentryOS_API_Client($this->client_id, $this->secret, $this->api_url);
        $payload = $this->build_payload($order);
        $payment_url = $api_client->create_payment_link($payload);
        
        if (is_wp_error($payment_url)) {
            wp_send_json_error(['message' => $payment_url->get_error_message()]);
        }
        
        // Add customer information as query strings
        $payment_url = $this->add_customer_query_strings($payment_url, $order);
        
        wp_send_json_success([
            'payment_url' => $payment_url,
            'order_id' => $order_id,
            'return_url' => $this->get_return_url($order)
        ]);
    }
    
    /**
     * AJAX handler to check payment status
     */
    public function ajax_check_payment_status() {
        check_ajax_referer('centryos-payment-nonce', 'nonce');
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'centryos-payment-gateway-for-woocommerce')]);
        }
        
        // Verify order belongs to current user or is a guest order (for security)
        $current_user_id = get_current_user_id();
        $order_user_id = $order->get_user_id();
        
        // Allow if: user is admin, order belongs to current user, or both are guest (0)
        if (!current_user_can('manage_woocommerce')) {
            if ($order_user_id !== $current_user_id && ($order_user_id !== 0 || $current_user_id !== 0)) {
                // Additional check: verify order was created recently (within last 10 minutes) for guest orders
                $order_date = $order->get_date_created();
                if ($order_date) {
                    $order_timestamp = $order_date->getTimestamp();
                    $current_timestamp = current_time('timestamp');
                    if (($current_timestamp - $order_timestamp) > 600) { // 10 minutes
                        wp_send_json_error(['message' => __('Unauthorized access.', 'centryos-payment-gateway-for-woocommerce')]);
                    }
                } else {
                    wp_send_json_error(['message' => __('Unauthorized access.', 'centryos-payment-gateway-for-woocommerce')]);
                }
            }
        }
        
        // Check if order is paid
        $is_paid = $order->is_paid();
        
        wp_send_json_success([
            'paid' => $is_paid,
            'status' => $order->get_status(),
            'redirect_url' => $is_paid ? $this->get_return_url($order) : ''
        ]);
    }
    
    /**
     * Enqueue modal scripts and styles
     */
    public function enqueue_modal_scripts() {
        if (is_checkout() || is_wc_endpoint_url('order-received')) {
            wp_enqueue_script(
                'centryos-modal',
                CENTRYOS_GATEWAY_PLUGIN_URL . 'assets/js/centryos-modal.js',
                ['jquery'],
                CENTRYOS_GATEWAY_VERSION,
                true
            );
            wp_enqueue_style(
                'centryos-modal',
                CENTRYOS_GATEWAY_PLUGIN_URL . 'assets/css/centryos-modal.css',
                [],
                CENTRYOS_GATEWAY_VERSION
            );
            
            $order_id = WC()->session ? WC()->session->get('centryos_order_id') : 0;
            
            wp_localize_script('centryos-modal', 'centryosData', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('centryos-payment-nonce'),
                'checkoutUrl' => wc_get_checkout_url(),
                'orderId' => $order_id
            ]);
            
            // Debug: Log that scripts are being enqueued
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[CentryOS Gateway] Modal scripts enqueued on checkout page. Order ID from session: ' . $order_id);
            }
        }
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
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Fire Meta Pixel Purchase event if enabled
        $pixel_data = $order->get_meta('_centryos_pixel_purchase');
        if ($pixel_data && $this->enable_meta_pixel === 'yes' && $this->meta_pixel_track_purchase === 'yes') {
            ?>
            <script>
            if (typeof fbq !== 'undefined') {
                fbq('track', 'Purchase', {
                    value: <?php echo esc_js($pixel_data['value']); ?>,
                    currency: '<?php echo esc_js($pixel_data['currency']); ?>',
                    contents: <?php echo wp_json_encode($pixel_data['contents']); ?>
                });
            }
            </script>
            <?php
        }
        
        echo '<p>' . esc_html__('Thank you! Your payment was processed via CentryOS.', 'centryos-payment-gateway-for-woocommerce') . '</p>';
    }
    
    /**
     * Generate webhook URL field (always shows current URL)
     */
    public function generate_webhook_url_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $defaults = [
            'title' => '',
            'disabled' => false,
            'class' => '',
            'css' => '',
            'placeholder' => '',
            'type' => 'text',
            'desc_tip' => false,
            'description' => '',
            'custom_attributes' => [],
        ];
        
        $data = wp_parse_args($data, $defaults);
        // Use saved value or default to ngrok URL
        $value = $this->get_option($key, 'https://f7c6d0f0af0d.ngrok-free.app/wordpress/wp-json/v1/wh/centryos/payment-complete');
        
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <legend class="screen-reader-text"><span><?php echo wp_kses_post($data['title']); ?></span></legend>
                    <input
                        class="input-text regular-input <?php echo esc_attr($data['class']); ?>"
                        type="text"
                        name="<?php echo esc_attr($field_key); ?>"
                        id="<?php echo esc_attr($field_key); ?>"
                        value="<?php echo esc_attr($value); ?>"
                        placeholder="<?php echo esc_attr($data['placeholder']); ?>"
                        style="<?php echo esc_attr($data['css']); ?>"
                        <?php disabled($data['disabled'], true); ?>
                        <?php echo $this->get_custom_attribute_html($data); ?>
                    />
                    <?php echo $this->get_description_html($data); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
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