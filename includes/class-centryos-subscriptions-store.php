<?php
/**
 * CentryOS Subscriptions Store
 *
 * Custom-table persistence for customer subscriptions. Subscriptions are keyed
 * by the CentryOS/Stripe subscription id and linked back to the originating
 * WooCommerce order, so webhook events and the admin list can look them up.
 *
 * @package CentryOS_Gateway
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CentryOS_Subscriptions_Store {

    const STATUS_ACTIVE    = 'active';
    const STATUS_TRIALING  = 'trialing';
    const STATUS_PAST_DUE  = 'past_due';
    const STATUS_PENDING_CANCEL = 'pending_cancel';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Fully-qualified table name.
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'centryos_subscriptions';
    }

    /**
     * Create / upgrade the subscriptions table. Called on plugin activation.
     */
    public static function install() {
        global $wpdb;

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id VARCHAR(191) NOT NULL DEFAULT '',
            external_id VARCHAR(191) NOT NULL DEFAULT '',
            order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(32) NOT NULL DEFAULT 'active',
            amount DECIMAL(19,4) NOT NULL DEFAULT 0,
            currency VARCHAR(8) NOT NULL DEFAULT '',
            interval_type VARCHAR(16) NOT NULL DEFAULT 'month',
            interval_count INT UNSIGNED NOT NULL DEFAULT 1,
            trial_days INT UNSIGNED NOT NULL DEFAULT 0,
            cycle_count INT UNSIGNED NOT NULL DEFAULT 0,
            current_period_start DATETIME NULL DEFAULT NULL,
            next_renewal_date DATETIME NULL DEFAULT NULL,
            canceled_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY subscription_id (subscription_id),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Insert or update a subscription record keyed by subscription_id.
     *
     * @param string $subscription_id CentryOS/Stripe subscription id.
     * @param array  $data            Column => value pairs.
     * @return int|false Row id on success, false on failure.
     */
    public static function upsert_by_subscription_id($subscription_id, array $data) {
        global $wpdb;

        if (empty($subscription_id)) {
            return false;
        }

        $now      = current_time('mysql', true);
        $existing = self::get_by_subscription_id($subscription_id);

        $data['subscription_id'] = $subscription_id;
        $data['updated_at']      = $now;

        $formats = self::formats_for($data);

        if ($existing) {
            $updated = $wpdb->update(self::table_name(), $data, ['id' => $existing->id], $formats, ['%d']);
            return $updated === false ? false : (int) $existing->id;
        }

        $data['created_at'] = $now;
        $formats[]          = '%s';

        $inserted = $wpdb->insert(self::table_name(), $data, $formats);
        return $inserted === false ? false : (int) $wpdb->insert_id;
    }

    /**
     * Update a record by primary key.
     */
    public static function update($id, array $data) {
        global $wpdb;
        $data['updated_at'] = current_time('mysql', true);
        return $wpdb->update(self::table_name(), $data, ['id' => (int) $id], self::formats_for($data), ['%d']);
    }

    /**
     * Mark a subscription cancelled (terminal).
     */
    public static function mark_canceled($subscription_id) {
        $existing = self::get_by_subscription_id($subscription_id);
        if (!$existing) {
            return false;
        }
        return self::update($existing->id, [
            'status'      => self::STATUS_CANCELLED,
            'canceled_at' => current_time('mysql', true),
        ]);
    }

    /**
     * @return object|null
     */
    public static function get_by_subscription_id($subscription_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE subscription_id = %s LIMIT 1',
            $subscription_id
        ));
    }

    /**
     * @return object|null
     */
    public static function get_by_order_id($order_id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1',
            $order_id
        ));
    }

    /**
     * @return object|null
     */
    public static function get($id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' WHERE id = %d LIMIT 1',
            $id
        ));
    }

    /**
     * Paginated/filtered list for the admin screen.
     *
     * @param array $args { status?:string, search?:string, per_page?:int, page?:int }
     * @return array{items:object[],total:int}
     */
    public static function list(array $args = []) {
        global $wpdb;

        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $offset   = ($page - 1) * $per_page;

        $where  = '1=1';
        $params = [];

        if (!empty($args['status'])) {
            $where   .= ' AND status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where   .= ' AND (subscription_id LIKE %s OR external_id LIKE %s OR order_id LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $table = self::table_name();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total     = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));

        $list_sql      = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $list_params   = array_merge($params, [$per_page, $offset]);
        $items         = $wpdb->get_results($wpdb->prepare($list_sql, $list_params));
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return ['items' => $items ?: [], 'total' => $total];
    }

    /**
     * Map column values to $wpdb format specifiers.
     */
    private static function formats_for(array $data) {
        $int_cols   = ['order_id', 'customer_id', 'product_id', 'interval_count', 'trial_days', 'cycle_count'];
        $float_cols = ['amount'];

        $formats = [];
        foreach (array_keys($data) as $col) {
            if (in_array($col, $int_cols, true)) {
                $formats[] = '%d';
            } elseif (in_array($col, $float_cols, true)) {
                $formats[] = '%f';
            } else {
                $formats[] = '%s';
            }
        }
        return $formats;
    }
}
