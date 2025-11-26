<?php
/**
 * Uninstall script
 * 
 * Fired when the plugin is uninstalled
 *
 * @package CentryOS_Gateway
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove plugin settings
delete_option('woocommerce_centryos_gateway_settings');

// Remove order meta data (optional - comment out if you want to keep order history)
// global $wpdb;
// $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_centryos_%'");