<?php
/**
 * CentryOS Blocks Support
 *
 * @package CentryOS_Gateway
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class CentryOS_Blocks_Support extends AbstractPaymentMethodType {
    
    private $gateway;
    protected $name = 'centryos_gateway';
    
    /**
     * Initialize
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_centryos_gateway_settings', []);
        if (function_exists('WC') && WC()->payment_gateways) {
            $gateways = WC()->payment_gateways->payment_gateways();
            $this->gateway = $gateways['centryos_gateway'] ?? null;
        }
    }
    
    /**
     * Check if active
     */
    public function is_active() {
        return !empty($this->gateway) && $this->gateway->is_available();
    }
    
    /**
     * Get script handles
     */
    public function get_payment_method_script_handles() {
        $script_url = CENTRYOS_GATEWAY_PLUGIN_URL . 'assets/js/centryos-blocks.js';
        $script_asset_path = CENTRYOS_GATEWAY_PLUGIN_DIR . 'assets/js/centryos-blocks.js';
        
        wp_register_script(
            'centryos-blocks-integration',
            $script_url,
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            file_exists($script_asset_path) ? filemtime($script_asset_path) : CENTRYOS_GATEWAY_VERSION,
            true
        );
        
        return ['centryos-blocks-integration'];
    }
    
    /**
     * Get payment method data
     */
    public function get_payment_method_data() {
        return [
            'title' => $this->gateway ? $this->gateway->title : __('Pay with CentryOS', 'centryos-payment-gateway-for-woocommerce'),
            'description' => $this->gateway ? $this->gateway->description : '',
            'supports' => $this->gateway ? $this->gateway->supports : ['products'],
        ];
    }
}