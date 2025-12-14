<?php
/**
 * Meta Pixel Tracker
 * 
 * Handles Meta Pixel (Facebook Pixel) tracking for retargeting and conversion tracking
 *
 * @package CentryOS_Gateway
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CentryOS_Pixel_Tracker {
    
    /**
     * @var string Meta Pixel ID
     */
    private $pixel_id;
    
    /**
     * @var bool Enable advanced matching
     */
    private $advanced_matching;
    
    /**
     * @var array Event tracking configuration
     */
    private $track_events;
    
    /**
     * Constructor
     * 
     * @param string $pixel_id Meta Pixel ID
     * @param bool $advanced_matching Enable advanced matching
     * @param array $track_events Event tracking configuration
     */
    public function __construct($pixel_id, $advanced_matching = true, $track_events = []) {
        $this->pixel_id = $pixel_id;
        $this->advanced_matching = $advanced_matching;
        $this->track_events = wp_parse_args($track_events, [
            'initiate_checkout' => true,
            'add_payment_info' => true,
            'purchase' => true
        ]);
        
        if (!empty($this->pixel_id)) {
            $this->init();
        }
    }
    
    /**
     * Initialize tracking hooks
     */
    private function init() {
        // Inject base pixel code
        add_action('wp_head', [$this, 'inject_pixel_base']);
        
        // Track checkout events based on settings
        if ($this->track_events['initiate_checkout']) {
            add_action('woocommerce_checkout_init', [$this, 'track_initiate_checkout']);
        }
        
        if ($this->track_events['add_payment_info']) {
            add_action('wp_footer', [$this, 'inject_add_payment_info_tracking']);
        }
        
        add_action('wp_footer', [$this, 'inject_tracking_events']);
    }
    
    /**
     * Inject Meta Pixel base code
     */
    public function inject_pixel_base() {
        if (empty($this->pixel_id)) {
            return;
        }
        ?>
        <!-- Meta Pixel Code -->
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo esc_js($this->pixel_id); ?>'<?php echo $this->advanced_matching ? ", {autoConfig: true, debug: false}" : ""; ?>);
        fbq('track', 'PageView');
        </script>
        <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?php echo esc_attr($this->pixel_id); ?>&ev=PageView&noscript=1"
        /></noscript>
        <!-- End Meta Pixel Code -->
        <?php
    }
    
    /**
     * Track InitiateCheckout event
     */
    public function track_initiate_checkout() {
        if (!is_checkout() || !$this->track_events['initiate_checkout']) {
            return;
        }
        
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) {
            return;
        }
        
        $value = $cart->get_total('edit');
        $currency = get_woocommerce_currency();
        $contents = [];
        
        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if ($product) {
                $contents[] = [
                    'id' => (string) $product->get_id(),
                    'quantity' => $cart_item['quantity'],
                    'item_price' => (float) $product->get_price('edit')
                ];
            }
        }
        
        if (WC()->session) {
            WC()->session->set('centryos_pixel_initiate_checkout', [
                'value' => (float) $value,
                'currency' => $currency,
                'contents' => $contents
            ]);
        }
    }
    
    /**
     * Inject AddPaymentInfo tracking
     */
    public function inject_add_payment_info_tracking() {
        if (!is_checkout() || !$this->track_events['add_payment_info']) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('input[name="payment_method"]').on('change', function() {
                if ($(this).val() === 'centryos_gateway') {
                    if (typeof fbq !== 'undefined') {
                        fbq('track', 'AddPaymentInfo', {
                            content_name: 'CentryOS Payment',
                            content_category: 'Payment Method'
                        });
                    }
                }
            });
        });
        </script>
        <?php
    }
    
    /**
     * Inject tracking events in footer
     */
    public function inject_tracking_events() {
        if (!is_checkout()) {
            return;
        }
        
        $initiate_checkout = WC()->session ? WC()->session->get('centryos_pixel_initiate_checkout') : null;
        if ($initiate_checkout && $this->track_events['initiate_checkout']) {
            ?>
            <script>
            if (typeof fbq !== 'undefined') {
                fbq('track', 'InitiateCheckout', {
                    value: <?php echo esc_js($initiate_checkout['value']); ?>,
                    currency: '<?php echo esc_js($initiate_checkout['currency']); ?>',
                    contents: <?php echo wp_json_encode($initiate_checkout['contents']); ?>
                });
            }
            </script>
            <?php
            if (WC()->session) {
                WC()->session->__unset('centryos_pixel_initiate_checkout');
            }
        }
    }
    
    /**
     * Track Purchase event
     * Called from webhook handler when payment is successful
     * 
     * @param WC_Order $order WooCommerce order object
     */
    public static function track_purchase($order) {
        $settings = get_option('woocommerce_centryos_gateway_settings', []);
        $enabled = $settings['enable_meta_pixel'] ?? 'no';
        $pixel_id = $settings['meta_pixel_id'] ?? '';
        $track_purchase = $settings['meta_pixel_track_purchase'] ?? 'yes';
        
        if ($enabled !== 'yes' || empty($pixel_id) || $track_purchase !== 'yes') {
            return;
        }
        
        $value = (float) $order->get_total();
        $currency = $order->get_currency();
        $contents = [];
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $contents[] = [
                    'id' => (string) $product->get_id(),
                    'quantity' => $item->get_quantity(),
                    'item_price' => (float) ($item->get_total() / $item->get_quantity())
                ];
            }
        }
        
        // Store in order meta for thank you page
        $order->update_meta_data('_centryos_pixel_purchase', [
            'value' => $value,
            'currency' => $currency,
            'contents' => $contents,
            'pixel_id' => $pixel_id
        ]);
        $order->save();
    }
}

