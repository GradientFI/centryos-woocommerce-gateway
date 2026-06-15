<?php

/**
 * CentryOS Webhook Handler
 *
 * @package CentryOS_Gateway
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
  exit;
}

class CentryOS_Webhook_Handler
{

  /**
   * Initialize webhook handler
   */
  public static function init()
  {
    add_action('rest_api_init', [__CLASS__, 'register_webhook_endpoint']);
  }

  /**
   * Register REST API endpoint
   */
  public static function register_webhook_endpoint()
  {
    register_rest_route('v1', '/wh/centryos/payment-complete', [
      'methods' => 'POST',
      'callback' => [__CLASS__, 'handle_webhook'],
      'permission_callback' => '__return_true'
    ]);
  }

  /**
   * Handle incoming webhook
   */
  public static function handle_webhook(WP_REST_Request $request) {
    $body = $request->get_body();
    $data = json_decode($body, true);

    $signature = $request->get_header('signature');

    self::log('info', 'received', [
      'method'      => $request->get_method(),
      'route'       => $request->get_route(),
      'remote_ip'   => self::client_ip(),
      'signature'   => $signature !== null ? substr($signature, 0, 16) . '…' : null,
      'event_type'  => is_array($data) ? ($data['eventType'] ?? null) : null,
      'status'      => is_array($data) ? ($data['status'] ?? null) : null,
      'order_id'    => is_array($data) ? ($data['payload']['orderId'] ?? null) : null,
      'body'        => $body,
    ]);

    // Verify webhook signature
    $secret = defined('CENTRYOS_WEBHOOK_SECRET') ? CENTRYOS_WEBHOOK_SECRET : '';

    if (empty($secret)) {
        self::log('error', 'rejected: webhook secret not configured');
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook secret not configured.'
        ], 500);
    }

    if (empty($signature) || !self::verify_signature($body, $signature, $secret)) {
        self::log('warning', 'rejected: invalid or missing signature', [
            'signature_present' => !empty($signature),
        ]);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid or missing signature.'
        ], 403);
    }

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        self::log('warning', 'rejected: invalid JSON payload', [
            'json_error' => json_last_error_msg(),
        ]);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid JSON payload.'
        ], 400);
    }

    // Validate payload
    if (empty($data['payload']['orderId'])) {
        self::log('warning', 'rejected: orderId missing from payload', ['data' => $data]);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order ID not found in payload.'
        ], 400);
    }

    $order_id = intval($data['payload']['orderId']);
    $order = wc_get_order($order_id);

    if (!$order) {
        self::log('warning', 'rejected: order not found', ['order_id' => $order_id]);
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order not found: ' . $order_id
        ], 404);
    }

    $log_context = [
        'order_id'   => $order_id,
        'status'     => $data['status'] ?? null,
        'event_type' => $data['eventType'] ?? null,
    ];

    // Subscription lifecycle events reference the originating order but must
    // bypass the already-paid skip below (renewals fire against a paid order and
    // their job is to create *new* orders / advance the schedule).
    $event_type = isset($data['eventType']) ? strtoupper((string) $data['eventType']) : '';
    if (self::is_recurring_event($event_type)) {
        self::log('info', 'processing: recurring event', $log_context);
        return self::handle_recurring_webhook($order, $data, $event_type);
    }

    // Skip duplicate processing for already-paid orders
    if ($order->is_paid()) {
        self::log('info', 'skipped: order already paid', $log_context);
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Order already paid, skipping duplicate webhook'
        ], 200);
    }

    if (self::is_payment_successful($data)) {
        self::log('info', 'processing: payment success', $log_context);
        return self::process_successful_payment($order, $data);
    }

    if (self::is_payment_failed($data)) {
        self::log('info', 'processing: payment failure', $log_context);
        return self::process_failed_payment($order, $data);
    }

    // Unknown/pending status — acknowledge without mutating the order.
    self::log('info', 'acknowledged: status not actionable', $log_context);
    return new WP_REST_Response([
        'success' => true,
        'message' => 'Webhook received, status not actionable',
        'status'  => $data['status'] ?? 'unknown'
    ], 200);
  }

  /**
   * Write a structured entry to PHP's error_log and (when available) the
   * WooCommerce log (Status > Logs > centryos-webhook). Logging is unconditional
   * so events are visible without WP_DEBUG or a working WC log handler.
   */
  private static function log($level, $event, array $context = [])
  {
    $message = $event;
    if (!empty($context)) {
      $encoded = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
      $message .= ' ' . $encoded;
    }

    error_log('[CentryOS Webhook][' . $level . '] ' . $message);

    if (function_exists('wc_get_logger')) {
      try {
        wc_get_logger()->log($level, $message, ['source' => 'centryos-webhook']);
      } catch (\Throwable $e) {
        error_log('[CentryOS Webhook][error] wc_get_logger threw: ' . $e->getMessage());
      }
    }
  }

  private static function verify_signature($payload, $signature, $secret)
  {
    $computed_signature = hash_hmac('sha512', $payload, $secret);
    return hash_equals($computed_signature, $signature);
  }

  /**
   * Best-effort client IP for log context. Honors common proxy headers but
   * does not authenticate them — purely informational.
   */
  private static function client_ip()
  {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
      if (!empty($_SERVER[$key])) {
        $value = is_string($_SERVER[$key]) ? $_SERVER[$key] : '';
        // X-Forwarded-For may be a comma list; take the first entry.
        $first = trim(explode(',', $value)[0]);
        if ($first !== '') {
          return $first;
        }
      }
    }
    return null;
  }

  /**
   * Check if payment was successful
   */
  private static function is_payment_successful($data)
  {
    $status = isset($data['status']) ? strtoupper($data['status']) : '';

    if ($status === 'SUCCESS') {
      return true;
    }

    if (
      isset($data['eventType']) && $data['eventType'] === 'COLLECTION' &&
      $status === 'SUCCESS'
    ) {
      return true;
    }

    return false;
  }

  /**
   * Check if payment explicitly failed. Unknown/pending statuses return false
   * so the order isn't mutated on non-actionable events.
   */
  private static function is_payment_failed($data)
  {
    $status = isset($data['status']) ? strtoupper($data['status']) : '';

    return in_array($status, ['FAILED', 'FAILURE', 'DECLINED', 'CANCELLED', 'CANCELED', 'EXPIRED'], true);
  }

  /**
   * Process failed payment. Idempotent: skips orders already in failed/cancelled state.
   */
  private static function process_failed_payment($order, $data)
  {
    $status = $data['status'] ?? 'unknown';

    if (in_array($order->get_status(), ['failed', 'cancelled', 'refunded'], true)) {
      return new WP_REST_Response([
        'success' => true,
        'message' => 'Order already in terminal non-paid state, skipping duplicate failure webhook',
        'status'  => $status
      ], 200);
    }

    $order->update_status('failed', sprintf(
      // translators: %s: Payment status from webhook
      __('Payment failed via CentryOS webhook. Status: %s', 'centryos-payment-gateway-for-woocommerce'),
      $status
    ));

    wc_increase_stock_levels($order->get_id());

    do_action('centryos_webhook_payment_failed', $order->get_id(), $data);

    return new WP_REST_Response([
      'success' => true,
      'message' => 'Webhook received, order marked as failed',
      'status'  => $status
    ], 200);
  }

  /**
   * Process successful payment
   */
  private static function process_successful_payment($order, $data)
  {
    $payload = $data['payload'];

    $transaction_id = $payload['transactionId'] ?? 'N/A';
    $wallet_id      = $payload['walletId'] ?? 'N/A';
    $amount         = $payload['amount'] ?? 'N/A';
    $currency       = $payload['currency'] ?? 'N/A';
    $method         = $payload['method'] ?? 'N/A';
    $summary        = $payload['summary'] ?? '';

    // Add admin order note
    // translators: %1$s: Transaction ID, %2$s: Amount, %3$s: Currency, %4$s: Payment method, %5$s: Summary
    $note = sprintf(
      __('Payment confirmed via CentryOS webhook. Transaction ID: %1$s, Amount: %2$s %3$s, Method: %4$s. Summary: %5$s', 'centryos-payment-gateway-for-woocommerce'),
      $transaction_id,
      $amount,
      $currency,
      $method,
      $summary
    );
    $order->add_order_note($note);

    // Store transaction metadata
    if (!empty($transaction_id)) {
      $order->update_meta_data('_centryos_transaction_id', $transaction_id);
    }
    if (!empty($wallet_id)) {
      $order->update_meta_data('_centryos_wallet_id', $wallet_id);
    }
    if (!empty($payload['entityId'])) {
      $order->update_meta_data('_centryos_entity_id', $payload['entityId']);
    }

    $order->save();

    // Mark order as paid
    $order->payment_complete($transaction_id);

    // Optional: trigger action hook for integrations
    do_action('centryos_webhook_payment_success', $order->get_id(), $payload);

    return new WP_REST_Response([
      'success' => true,
      'message' => 'Order marked as paid',
      'orderId' => $order->get_id(),
      'transactionId' => $transaction_id
    ], 200);
  }

  /**
   * Whether an event is a subscription lifecycle event
   * (COLLECTION.RECURRING.CREATED / PAID / FAILED / UPDATED / DELETED).
   */
  private static function is_recurring_event($event_type)
  {
    return strpos((string) $event_type, 'COLLECTION.RECURRING.') === 0;
  }

  /**
   * Route a recurring (subscription) webhook to the right processor.
   *
   * @param WC_Order $order      Originating order (payload.orderId).
   * @param array    $data       Decoded webhook body.
   * @param string   $event_type Uppercased eventType.
   */
  private static function handle_recurring_webhook($order, $data, $event_type)
  {
    $payload   = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];
    $recurring = isset($payload['recurringCharge']) && is_array($payload['recurringCharge']) ? $payload['recurringCharge'] : [];
    $subscription_id = $recurring['subscriptionId'] ?? '';

    if (empty($subscription_id)) {
      self::log('warning', 'recurring event missing subscriptionId', ['event_type' => $event_type]);
      return new WP_REST_Response(['success' => true, 'message' => 'Missing subscriptionId, acknowledged'], 200);
    }

    if (!class_exists('CentryOS_Subscriptions_Store')) {
      self::log('error', 'subscriptions store unavailable');
      return new WP_REST_Response(['success' => false, 'message' => 'Subscriptions store unavailable'], 500);
    }

    switch ($event_type) {
      case 'COLLECTION.RECURRING.CREATED':
        return self::process_recurring_created($order, $payload, $recurring, $subscription_id);
      case 'COLLECTION.RECURRING.PAID':
        return self::process_recurring_paid($order, $payload, $recurring, $subscription_id);
      case 'COLLECTION.RECURRING.FAILED':
        return self::process_recurring_failed($subscription_id, $payload);
      case 'COLLECTION.RECURRING.UPDATED':
        return self::process_recurring_updated($subscription_id, $recurring);
      case 'COLLECTION.RECURRING.DELETED':
        return self::process_recurring_deleted($subscription_id);
    }

    return new WP_REST_Response(['success' => true, 'message' => 'Recurring event acknowledged'], 200);
  }

  /**
   * Record a newly-created subscription and link it to the originating order.
   * For trial subscriptions the originating order is marked paid immediately
   * (the trial has started even though nothing is charged yet).
   */
  private static function process_recurring_created($order, $payload, $recurring, $subscription_id)
  {
    $interval   = isset($recurring['interval']) && is_array($recurring['interval']) ? $recurring['interval'] : [];
    $period     = $interval['type'] ?? 'month';
    $int_count  = max(1, (int) ($interval['count'] ?? 1));
    $amount     = isset($recurring['amount']) ? floatval($recurring['amount']) : floatval($payload['amount'] ?? 0);
    $trial_days = (int) ($recurring['trialPeriodDays'] ?? 0);
    $currency   = $payload['currency'] ?? $order->get_currency();
    $external_id = $payload['paymentLink']['externalId'] ?? '';

    $next = $trial_days > 0
      ? gmdate('Y-m-d H:i:s', strtotime("+{$trial_days} days"))
      : self::compute_next_renewal($period, $int_count);

    CentryOS_Subscriptions_Store::upsert_by_subscription_id($subscription_id, [
      'external_id'          => $external_id,
      'order_id'             => $order->get_id(),
      'customer_id'          => $order->get_customer_id(),
      'product_id'           => self::find_subscription_product_id($order),
      'status'               => $trial_days > 0 ? CentryOS_Subscriptions_Store::STATUS_TRIALING : CentryOS_Subscriptions_Store::STATUS_ACTIVE,
      'amount'               => $amount,
      'currency'             => $currency,
      'interval_type'        => $period,
      'interval_count'       => $int_count,
      'trial_days'           => $trial_days,
      'current_period_start' => current_time('mysql', true),
      'next_renewal_date'    => $next,
    ]);

    $order->update_meta_data('_centryos_subscription_id', $subscription_id);
    $order->save();

    // translators: 1: subscription id, 2: next renewal date
    $order->add_order_note(sprintf(
      __('CentryOS subscription created. Subscription ID: %1$s. Next renewal: %2$s', 'centryos-payment-gateway-for-woocommerce'),
      $subscription_id,
      $next
    ));

    // A trial means no charge now — treat the order as paid so the buyer's
    // checkout/embedded flow completes; the first real charge fires at trial end.
    if ($trial_days > 0 && !$order->is_paid()) {
      $order->payment_complete();
      $order->add_order_note(__('Subscription trial started (no charge due yet).', 'centryos-payment-gateway-for-woocommerce'));
    }

    do_action('centryos_webhook_subscription_created', $order->get_id(), $subscription_id, $payload);

    return new WP_REST_Response(['success' => true, 'message' => 'Subscription recorded', 'subscriptionId' => $subscription_id], 200);
  }

  /**
   * Handle a successful recurring charge. The first charge settles the
   * originating order; subsequent charges create new renewal orders.
   */
  private static function process_recurring_paid($order, $payload, $recurring, $subscription_id)
  {
    $record = CentryOS_Subscriptions_Store::get_by_subscription_id($subscription_id);
    if (!$record) {
      // PAID arrived before CREATED — record it now, then re-read.
      self::process_recurring_created($order, $payload, $recurring, $subscription_id);
      $record = CentryOS_Subscriptions_Store::get_by_subscription_id($subscription_id);
    }

    $transaction_id   = $payload['transactionId'] ?? '';
    $new_cycle        = ($record ? (int) $record->cycle_count : 0) + 1;
    $is_trial         = $record && (int) $record->trial_days > 0;
    $renewal_order_id = null;

    if ($new_cycle === 1 && !$is_trial) {
      // No trial: the first charge IS the originating order. Settle it (idempotent
      // if a COLLECTION webhook already did) and never create a renewal order here.
      if (!$order->is_paid()) {
        if (!empty($transaction_id)) {
          $order->update_meta_data('_centryos_transaction_id', $transaction_id);
          $order->save();
        }
        $order->payment_complete($transaction_id);
        $order->add_order_note(__('Initial subscription payment confirmed via CentryOS.', 'centryos-payment-gateway-for-woocommerce'));
      }
    } else {
      // Trial's first real charge (the trial start was the originating order), or
      // any subsequent renewal: create a fresh order.
      $renewal_order_id = self::create_renewal_order($order, $record, $payload, $subscription_id);
    }

    if ($record) {
      CentryOS_Subscriptions_Store::update($record->id, [
        'status'               => CentryOS_Subscriptions_Store::STATUS_ACTIVE,
        'cycle_count'          => $new_cycle,
        'current_period_start' => current_time('mysql', true),
        'next_renewal_date'    => self::compute_next_renewal($record->interval_type, (int) $record->interval_count),
      ]);
    }

    do_action('centryos_webhook_subscription_renewed', $order->get_id(), $subscription_id, $renewal_order_id, $payload);

    return new WP_REST_Response([
      'success'        => true,
      'message'        => 'Recurring charge processed',
      'subscriptionId' => $subscription_id,
      'renewalOrderId' => $renewal_order_id,
    ], 200);
  }

  /**
   * Create a WooCommerce renewal order containing only the subscription product
   * at the recurring rate (one-time items from the original cart do not recur).
   *
   * @return int|null Renewal order id or null on failure.
   */
  private static function create_renewal_order($parent_order, $record, $payload, $subscription_id)
  {
    $amount   = $record ? floatval($record->amount) : floatval($payload['amount'] ?? 0);
    $currency = $record && !empty($record->currency) ? $record->currency : ($payload['currency'] ?? $parent_order->get_currency());
    $product  = ($record && $record->product_id) ? wc_get_product($record->product_id) : null;

    $renewal = wc_create_order(['customer_id' => $parent_order->get_customer_id()]);
    if (is_wp_error($renewal)) {
      self::log('error', 'failed to create renewal order', ['subscription_id' => $subscription_id]);
      return null;
    }

    if ($product) {
      $renewal->add_product($product, 1, [
        'subtotal' => $amount,
        'total'    => $amount,
      ]);
    } else {
      $fee = new WC_Order_Item_Fee();
      $fee->set_name(__('Subscription renewal', 'centryos-payment-gateway-for-woocommerce'));
      $fee->set_total($amount);
      $renewal->add_item($fee);
    }

    $renewal->set_address($parent_order->get_address('billing'), 'billing');
    $renewal->set_address($parent_order->get_address('shipping'), 'shipping');
    $renewal->set_currency($currency);
    $renewal->set_payment_method('centryos_gateway');
    $renewal->set_payment_method_title('CentryOS Payment Gateway');

    $renewal->update_meta_data('_centryos_subscription_id', $subscription_id);
    $renewal->update_meta_data('_centryos_renewal_parent_order', $parent_order->get_id());

    $transaction_id = $payload['transactionId'] ?? '';
    if (!empty($transaction_id)) {
      $renewal->update_meta_data('_centryos_transaction_id', $transaction_id);
    }

    $renewal->calculate_totals();
    // translators: %s: subscription id
    $renewal->add_order_note(sprintf(
      __('Subscription renewal for %s.', 'centryos-payment-gateway-for-woocommerce'),
      $subscription_id
    ));
    $renewal->payment_complete($transaction_id);
    $renewal->save();

    return $renewal->get_id();
  }

  /**
   * Mark a subscription past_due after a failed charge.
   */
  private static function process_recurring_failed($subscription_id, $payload)
  {
    $record = CentryOS_Subscriptions_Store::get_by_subscription_id($subscription_id);
    if ($record) {
      CentryOS_Subscriptions_Store::update($record->id, ['status' => CentryOS_Subscriptions_Store::STATUS_PAST_DUE]);
      do_action('centryos_webhook_subscription_payment_failed', (int) $record->order_id, $subscription_id, $payload);
    }
    return new WP_REST_Response(['success' => true, 'message' => 'Subscription marked past_due'], 200);
  }

  /**
   * Acknowledge a subscription update. Lightweight: refresh next renewal when
   * the event carries interval data. Merchant-initiated cancellation is tracked
   * locally at request time and finalised by the DELETED event.
   */
  private static function process_recurring_updated($subscription_id, $recurring)
  {
    $record = CentryOS_Subscriptions_Store::get_by_subscription_id($subscription_id);
    if ($record && !empty($recurring['interval']['type'])) {
      CentryOS_Subscriptions_Store::update($record->id, [
        'next_renewal_date' => self::compute_next_renewal(
          $recurring['interval']['type'],
          max(1, (int) ($recurring['interval']['count'] ?? 1))
        ),
      ]);
    }
    return new WP_REST_Response(['success' => true, 'message' => 'Subscription update acknowledged'], 200);
  }

  /**
   * Finalise cancellation (terminal) when the provider deletes the subscription.
   */
  private static function process_recurring_deleted($subscription_id)
  {
    CentryOS_Subscriptions_Store::mark_canceled($subscription_id);
    $record = CentryOS_Subscriptions_Store::get_by_subscription_id($subscription_id);
    do_action('centryos_webhook_subscription_cancelled', $record ? (int) $record->order_id : 0, $subscription_id);
    return new WP_REST_Response(['success' => true, 'message' => 'Subscription cancelled'], 200);
  }

  /**
   * The product id of the first subscription line item in an order.
   */
  private static function find_subscription_product_id($order)
  {
    if (!class_exists('CentryOS_Product_Subscription')) {
      return 0;
    }
    foreach ($order->get_items() as $item) {
      $product = $item->get_product();
      if ($product && CentryOS_Product_Subscription::is_subscription_product($product)) {
        return $product->get_id();
      }
    }
    return 0;
  }

  /**
   * Next renewal timestamp = now + (interval_count × period), as UTC 'Y-m-d H:i:s'.
   */
  private static function compute_next_renewal($period, $interval, $base_ts = null)
  {
    $base_ts = $base_ts ?: time();
    $map     = ['day' => 'days', 'week' => 'weeks', 'month' => 'months', 'year' => 'years'];
    $unit    = $map[$period] ?? 'months';
    $count   = max(1, (int) $interval);
    return gmdate('Y-m-d H:i:s', strtotime("+{$count} {$unit}", $base_ts));
  }
}
