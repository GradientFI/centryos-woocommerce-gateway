<?php
/**
 * Plugin Name: CentryOS Payment Gateway for WooCommerce
 * Description: Accept payments via CentryOS hosted payment links. Fully compatible with WooCommerce Blocks.
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: CentryOS
 * Author URI: https://centryos.xyz
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: centryos-woocommerce-gateway
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin constants
define('CENTRYOS_GATEWAY_VERSION', '1.1.0');
define('CENTRYOS_GATEWAY_PLUGIN_FILE', __FILE__);
define('CENTRYOS_GATEWAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CENTRYOS_GATEWAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CENTRYOS_GATEWAY_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Initialize the plugin
 */
function centryos_gateway_init() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'centryos_gateway_woocommerce_missing_notice');
        return;
    }

    // Load plugin classes
    require_once CENTRYOS_GATEWAY_PLUGIN_DIR . 'includes/class-centryos-api-client.php';
    require_once CENTRYOS_GATEWAY_PLUGIN_DIR . 'includes/class-centryos-gateway.php';
    require_once CENTRYOS_GATEWAY_PLUGIN_DIR . 'includes/class-centryos-webhook-handler.php';
    
    // Register payment gateway
    add_filter('woocommerce_payment_gateways', 'centryos_gateway_register');
    
    // Initialize webhook handler
    CentryOS_Webhook_Handler::init();
}
add_action('plugins_loaded', 'centryos_gateway_init');

/**
 * Register the payment gateway
 */
function centryos_gateway_register($methods) {
    $methods[] = 'CentryOS_Gateway';
    return $methods;
}

/**
 * WooCommerce Blocks support
 */
function centryos_gateway_blocks_support() {
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    
    require_once CENTRYOS_GATEWAY_PLUGIN_DIR . 'includes/class-centryos-blocks-support.php';
    
    add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
        $registry->register(new CentryOS_Blocks_Support());
    });
}
add_action('woocommerce_blocks_loaded', 'centryos_gateway_blocks_support');

/**
 * Admin notice if WooCommerce is not active
 */
function centryos_gateway_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><strong>CentryOS Payment Gateway</strong> requires WooCommerce to be installed and active.</p>
    </div>
    <?php
}

/**
 * Load plugin textdomain for translations
 */
function centryos_gateway_load_textdomain() {
    load_plugin_textdomain(
        'centryos-woocommerce-gateway',
        false,
        dirname(CENTRYOS_GATEWAY_PLUGIN_BASENAME) . '/languages'
    );
}
add_action('init', 'centryos_gateway_load_textdomain');

/**
 * Plugin activation hook
 */
function centryos_gateway_activate() {
    // Check requirements
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(CENTRYOS_GATEWAY_PLUGIN_BASENAME);
        wp_die('CentryOS Payment Gateway requires PHP 7.4 or higher.');
    }
    
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(CENTRYOS_GATEWAY_PLUGIN_BASENAME);
        wp_die('CentryOS Payment Gateway requires WooCommerce to be installed and active.');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'centryos_gateway_activate');

/**
 * Plugin deactivation hook
 */
function centryos_gateway_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'centryos_gateway_deactivate');