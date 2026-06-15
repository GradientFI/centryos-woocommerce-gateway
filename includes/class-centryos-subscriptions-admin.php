<?php
/**
 * CentryOS Subscriptions Admin
 *
 * Adds a "CentryOS Subscriptions" screen under the WooCommerce menu where
 * merchants can view customer subscriptions and cancel them (cancel at period
 * end). Cancellation calls the liquidity-service; the local record is marked
 * pending cancellation and finalised by the DELETED webhook.
 *
 * @package CentryOS_Gateway
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CentryOS_Subscriptions_Admin {

    const CANCEL_ACTION = 'centryos_cancel_subscription';
    const PER_PAGE      = 20;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_' . self::CANCEL_ACTION, [__CLASS__, 'handle_cancel']);
    }

    public static function register_menu() {
        add_submenu_page(
            'woocommerce',
            __('CentryOS Subscriptions', 'centryos-payment-gateway-for-woocommerce'),
            __('CentryOS Subscriptions', 'centryos-payment-gateway-for-woocommerce'),
            'manage_woocommerce',
            'centryos-subscriptions',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Human-readable status labels.
     */
    private static function status_labels() {
        return [
            CentryOS_Subscriptions_Store::STATUS_ACTIVE         => __('Active', 'centryos-payment-gateway-for-woocommerce'),
            CentryOS_Subscriptions_Store::STATUS_TRIALING       => __('Trialing', 'centryos-payment-gateway-for-woocommerce'),
            CentryOS_Subscriptions_Store::STATUS_PAST_DUE       => __('Past due', 'centryos-payment-gateway-for-woocommerce'),
            CentryOS_Subscriptions_Store::STATUS_PENDING_CANCEL => __('Pending cancellation', 'centryos-payment-gateway-for-woocommerce'),
            CentryOS_Subscriptions_Store::STATUS_CANCELLED      => __('Cancelled', 'centryos-payment-gateway-for-woocommerce'),
        ];
    }

    /**
     * Render the subscriptions list screen.
     */
    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'centryos-payment-gateway-for-woocommerce'));
        }

        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        $result = CentryOS_Subscriptions_Store::list([
            'status'   => $status,
            'search'   => $search,
            'per_page' => self::PER_PAGE,
            'page'     => $paged,
        ]);

        $items       = $result['items'];
        $total       = $result['total'];
        $total_pages = max(1, (int) ceil($total / self::PER_PAGE));
        $labels      = self::status_labels();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('CentryOS Subscriptions', 'centryos-payment-gateway-for-woocommerce') . '</h1>';

        self::render_admin_notice();

        // Filters.
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="centryos-subscriptions" />';
        echo '<p class="search-box">';
        echo '<select name="status">';
        echo '<option value="">' . esc_html__('All statuses', 'centryos-payment-gateway-for-woocommerce') . '</option>';
        foreach ($labels as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($status, $key, false),
                esc_html($label)
            );
        }
        echo '</select> ';
        printf(
            '<input type="search" name="s" value="%s" placeholder="%s" /> ',
            esc_attr($search),
            esc_attr__('Subscription / order / external ID', 'centryos-payment-gateway-for-woocommerce')
        );
        submit_button(__('Filter', 'centryos-payment-gateway-for-woocommerce'), '', '', false);
        echo '</p>';
        echo '</form>';

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Subscription', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Customer', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Product', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Status', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Rate', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Billing', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Next renewal', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Order', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '<th>' . esc_html__('Actions', 'centryos-payment-gateway-for-woocommerce') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($items)) {
            echo '<tr><td colspan="9">' . esc_html__('No subscriptions found.', 'centryos-payment-gateway-for-woocommerce') . '</td></tr>';
        } else {
            foreach ($items as $row) {
                self::render_row($row, $labels);
            }
        }

        echo '</tbody></table>';

        // Pagination.
        if ($total_pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]));
            echo '</div></div>';
        }

        echo '</div>';
    }

    /**
     * Render a single subscription row.
     */
    private static function render_row($row, $labels) {
        $product      = $row->product_id ? wc_get_product($row->product_id) : null;
        $product_name = $product ? $product->get_name() : '—';

        $customer_name = '';
        if ($row->customer_id) {
            $user = get_userdata($row->customer_id);
            $customer_name = $user ? $user->display_name : ('#' . $row->customer_id);
        } elseif ($row->order_id) {
            $order = wc_get_order($row->order_id);
            $customer_name = $order ? trim($order->get_formatted_billing_full_name()) : '';
        }
        if ($customer_name === '') {
            $customer_name = '—';
        }

        $status_label = $labels[$row->status] ?? ucfirst((string) $row->status);
        $billing      = sprintf(
            // translators: 1: interval count, 2: period (day/week/month/year)
            __('Every %1$d %2$s', 'centryos-payment-gateway-for-woocommerce'),
            (int) $row->interval_count,
            esc_html($row->interval_type)
        );

        $can_cancel = in_array($row->status, [
            CentryOS_Subscriptions_Store::STATUS_ACTIVE,
            CentryOS_Subscriptions_Store::STATUS_TRIALING,
            CentryOS_Subscriptions_Store::STATUS_PAST_DUE,
        ], true);

        echo '<tr>';
        echo '<td><code>' . esc_html($row->subscription_id) . '</code></td>';
        echo '<td>' . esc_html($customer_name) . '</td>';
        echo '<td>' . esc_html($product_name) . '</td>';
        echo '<td>' . esc_html($status_label) . '</td>';
        echo '<td>' . wp_kses_post(wc_price($row->amount, ['currency' => $row->currency])) . '</td>';
        echo '<td>' . esc_html($billing) . '</td>';
        echo '<td>' . esc_html($row->next_renewal_date ? mysql2date(get_option('date_format'), $row->next_renewal_date) : '—') . '</td>';

        if ($row->order_id) {
            printf(
                '<td><a href="%s">#%d</a></td>',
                esc_url(get_edit_post_link($row->order_id)),
                (int) $row->order_id
            );
        } else {
            echo '<td>—</td>';
        }

        echo '<td>';
        if ($can_cancel) {
            $url = wp_nonce_url(
                admin_url('admin-post.php?action=' . self::CANCEL_ACTION . '&subscription=' . (int) $row->id),
                self::CANCEL_ACTION . '_' . (int) $row->id
            );
            printf(
                '<a href="%s" class="button" onclick="return confirm(%s);">%s</a>',
                esc_url($url),
                esc_js(wp_json_encode(__('Cancel this subscription at the end of the current period?', 'centryos-payment-gateway-for-woocommerce'))),
                esc_html__('Cancel', 'centryos-payment-gateway-for-woocommerce')
            );
        } else {
            echo '&mdash;';
        }
        echo '</td>';
        echo '</tr>';
    }

    /**
     * Handle the cancel action (admin-post).
     */
    public static function handle_cancel() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'centryos-payment-gateway-for-woocommerce'));
        }

        $id = isset($_GET['subscription']) ? absint($_GET['subscription']) : 0;

        check_admin_referer(self::CANCEL_ACTION . '_' . $id);

        $record = $id ? CentryOS_Subscriptions_Store::get($id) : null;
        if (!$record || empty($record->subscription_id)) {
            self::redirect_with_notice('error', __('Subscription not found.', 'centryos-payment-gateway-for-woocommerce'));
        }

        $client = self::api_client();
        $result = $client->cancel_subscription($record->subscription_id, true);

        if (is_wp_error($result)) {
            self::redirect_with_notice('error', sprintf(
                // translators: %s: error message
                __('Cancellation failed: %s', 'centryos-payment-gateway-for-woocommerce'),
                $result->get_error_message()
            ));
        }

        CentryOS_Subscriptions_Store::update($record->id, [
            'status' => CentryOS_Subscriptions_Store::STATUS_PENDING_CANCEL,
        ]);

        self::redirect_with_notice('success', __('Subscription will be cancelled at the end of the current period.', 'centryos-payment-gateway-for-woocommerce'));
    }

    /**
     * Build an API client from the gateway settings / wp-config constants.
     */
    private static function api_client() {
        $settings  = get_option('woocommerce_centryos_gateway_settings', []);
        $client_id = defined('CENTRYOS_CLIENT_ID') ? CENTRYOS_CLIENT_ID : ($settings['client_id'] ?? '');
        $secret    = defined('CENTRYOS_API_SECRET') ? CENTRYOS_API_SECRET : ($settings['secret'] ?? '');
        $api_url   = $settings['api_url'] ?? 'staging';
        return new CentryOS_API_Client($client_id, $secret, $api_url);
    }

    /**
     * Redirect back to the list with a transient notice.
     */
    private static function redirect_with_notice($type, $message) {
        set_transient('centryos_subscriptions_notice', ['type' => $type, 'message' => $message], 30);
        wp_safe_redirect(admin_url('admin.php?page=centryos-subscriptions'));
        exit;
    }

    /**
     * Render and clear the one-shot admin notice.
     */
    private static function render_admin_notice() {
        $notice = get_transient('centryos_subscriptions_notice');
        if (!$notice) {
            return;
        }
        delete_transient('centryos_subscriptions_notice');

        $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            esc_html($notice['message'])
        );
    }
}
