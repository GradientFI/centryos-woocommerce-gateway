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

    $event_type = isset($data['eventType']) ? strtoupper((string) $data['eventType']) : '';

    // Refund lifecycle events carry no orderId and must bypass the payment-only
    // orderId/already-paid guards below — correlate and dispatch them separately.
    if (self::is_refund_event($event_type)) {
        return self::handle_refund_webhook($data, $event_type);
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

  /**
   * Refund lifecycle events from CentryOS: REFUND.REQUESTED / REFUND.APPROVED /
   * REFUND.REJECTED. They carry no orderId and must bypass the payment-only
   * orderId/already-paid guards in handle_webhook().
   */
  private static function is_refund_event($event_type)
  {
    return strpos($event_type, 'REFUND') === 0;
  }

  /**
   * Correlate a refund event to its order (refund payloads are keyed by transaction
   * ids, not orderId) and dispatch by event type.
   */
  private static function handle_refund_webhook($data, $event_type)
  {
    $payload = isset($data['payload']) && is_array($data['payload']) ? $data['payload'] : [];

    // Prefer an explicit orderId if a future payload ever includes one, then fall
    // back to the original payment transaction id stored on the order at payment time.
    $order = null;
    if (!empty($payload['orderId'])) {
      $order = wc_get_order(intval($payload['orderId']));
    }
    if (!$order && !empty($payload['originalTransactionId'])) {
      $order = self::find_order_by_transaction_id($payload['originalTransactionId']);
    }

    $log_context = [
      'event_type'              => $event_type,
      'status'                  => $data['status'] ?? null,
      'refund_transaction_id'   => $payload['transactionId'] ?? null,
      'original_transaction_id' => $payload['originalTransactionId'] ?? null,
    ];

    if (!$order) {
      // Acknowledge (200) so CentryOS does not retry indefinitely, but log loudly:
      // a refund event we cannot map to an order needs operator attention.
      self::log('error', 'refund: could not correlate to an order', $log_context);
      return new WP_REST_Response([
        'success' => true,
        'message' => 'Refund webhook received but no matching order found'
      ], 200);
    }

    $log_context['order_id'] = $order->get_id();

    switch ($event_type) {
      case 'REFUND.REQUESTED':
        self::log('info', 'processing: refund requested', $log_context);
        return self::process_refund_requested($order, $data, $payload);

      case 'REFUND.APPROVED':
        self::log('info', 'processing: refund approved', $log_context);
        return self::process_refund_approved($order, $data, $payload);

      case 'REFUND.REJECTED':
        self::log('info', 'processing: refund rejected', $log_context);
        return self::process_refund_rejected($order, $data, $payload);
    }

    self::log('info', 'acknowledged: unhandled refund event', $log_context);
    return self::refund_ack('Refund webhook received, event not actionable', $data);
  }

  /**
   * Find an order by the CentryOS payment transaction id stored at payment time.
   * Uses wc_get_orders so it works on both HPOS and legacy post storage.
   */
  private static function find_order_by_transaction_id($transaction_id)
  {
    if (empty($transaction_id) || !function_exists('wc_get_orders')) {
      return null;
    }

    $orders = wc_get_orders([
      'limit'      => 1,
      'return'     => 'objects',
      'meta_query' => [
        [
          'key'   => '_centryos_transaction_id',
          'value' => $transaction_id,
        ],
      ],
    ]);

    return (!empty($orders) && $orders[0] instanceof WC_Order) ? $orders[0] : null;
  }

  /**
   * REFUND.REQUESTED (status AWAITING_APPROVAL): a refund has been requested and is
   * pending approval on CentryOS. No money has moved and it may still be rejected,
   * so the order is moved into the transitional refund-pending status only.
   */
  private static function process_refund_requested($order, $data, $payload)
  {
    $refund_txn_id = $payload['transactionId'] ?? '';

    if (self::refund_state_recorded($order, $refund_txn_id, 'requested')) {
      return self::refund_ack('Refund request already recorded', $data);
    }

    // For refunds started outside WooCommerce there is no prior capture from the
    // woocommerce_create_refund hook, so record the current paid status here.
    if (class_exists('CentryOS_Refund_Status')) {
      CentryOS_Refund_Status::capture_prior_status_value($order);
    }

    $amount   = $payload['amount'] ?? 'N/A';
    $currency = $payload['currency'] ?? $order->get_currency();
    $reason   = isset($payload['metadata']['reason']) ? $payload['metadata']['reason'] : '';

    $order->add_order_note(sprintf(
      // translators: 1: amount, 2: currency, 3: reason
      __('CentryOS refund requested and awaiting approval. Amount: %1$s %2$s. Reason: %3$s. No funds have moved yet.', 'centryos-payment-gateway-for-woocommerce'),
      $amount,
      $currency,
      $reason !== '' ? $reason : __('n/a', 'centryos-payment-gateway-for-woocommerce')
    ));

    if (!empty($refund_txn_id)) {
      $order->update_meta_data('_centryos_refund_id', $refund_txn_id);
    }

    if (class_exists('CentryOS_Refund_Status')) {
      CentryOS_Refund_Status::set_pending($order);
    }

    self::record_refund_state($order, $refund_txn_id, 'requested');

    do_action('centryos_webhook_refund_requested', $order->get_id(), $data);

    return self::refund_ack('Refund request recorded', $data);
  }

  /**
   * REFUND.APPROVED (status PENDING): the refund was approved and submitted to the
   * payment processor. CentryOS sends no further settled webhook, so this is the
   * terminal confirmation. Ensures a WooCommerce refund record exists and resolves
   * the order out of refund-pending.
   */
  private static function process_refund_approved($order, $data, $payload)
  {
    $refund_txn_id = $payload['transactionId'] ?? '';

    if (self::refund_state_recorded($order, $refund_txn_id, 'approved')) {
      return self::refund_ack('Refund approval already processed', $data);
    }

    $amount     = isset($payload['amount']) ? floatval($payload['amount']) : 0.0;
    $currency   = $payload['currency'] ?? $order->get_currency();
    $stripe_ref = isset($payload['metadata']['stripeRefundId']) ? $payload['metadata']['stripeRefundId'] : '';
    $reason     = isset($payload['metadata']['note'])
      ? $payload['metadata']['note']
      : (isset($payload['metadata']['reason']) ? $payload['metadata']['reason'] : '');

    // If WooCommerce already has a refund (admin-initiated from the order screen),
    // trust it. Otherwise this refund originated outside WooCommerce (e.g. the
    // CentryOS dashboard) and we create the matching record.
    if (empty($order->get_refunds()) && $amount > 0 && function_exists('wc_create_refund')) {
      // This refund is already approved on CentryOS, so a full refund should land on
      // "refunded" — not the refund-pending status the admin-refund filter applies.
      if (class_exists('CentryOS_Refund_Status')) {
        CentryOS_Refund_Status::$suppress_pending_on_full_refund = true;
      }
      $refund = wc_create_refund([
        'amount'         => $amount,
        'reason'         => $reason,
        'order_id'       => $order->get_id(),
        // Money already moved on CentryOS — do NOT re-invoke the gateway's process_refund().
        'refund_payment' => false,
        'restock_items'  => false,
      ]);
      if (class_exists('CentryOS_Refund_Status')) {
        CentryOS_Refund_Status::$suppress_pending_on_full_refund = false;
      }

      if (is_wp_error($refund)) {
        self::log('error', 'refund.approved: wc_create_refund failed', [
          'order_id' => $order->get_id(),
          'error'    => $refund->get_error_message(),
        ]);
      } else {
        // wc_create_refund() may itself transition a fully-refunded order to
        // refunded; reload so we act on fresh status/totals and don't double-fire a
        // transition on a now-stale order object.
        $order = wc_get_order($order->get_id());
      }
    }

    // Resolve the order out of refund-pending: fully refunded -> refunded, otherwise
    // restore the captured pre-refund paid status. Guarded so we never re-fire a
    // transition WooCommerce already performed when the refund was created.
    if (floatval($order->get_remaining_refund_amount()) <= 0) {
      if ($order->get_status() !== 'refunded') {
        $order->update_status('refunded');
      }
      $order->delete_meta_data('_centryos_pre_refund_status');
      $order->save();
    } elseif (class_exists('CentryOS_Refund_Status')) {
      CentryOS_Refund_Status::restore_prior_status($order);
    }

    $note = sprintf(
      // translators: 1: amount, 2: currency
      __('CentryOS refund approved and submitted to the payment processor. Amount: %1$s %2$s.', 'centryos-payment-gateway-for-woocommerce'),
      $amount,
      $currency
    );
    if (!empty($stripe_ref)) {
      // translators: processor refund reference id
      $note .= ' ' . sprintf(__('Processor refund ID: %s', 'centryos-payment-gateway-for-woocommerce'), $stripe_ref);
    }
    $order->add_order_note($note);

    self::record_refund_state($order, $refund_txn_id, 'approved');

    do_action('centryos_webhook_refund_approved', $order->get_id(), $data);

    return self::refund_ack('Refund approval processed', $data);
  }

  /**
   * REFUND.REJECTED (status VOID): the refund request was rejected on CentryOS.
   * Move the order out of refund-pending back to its prior paid status. If a
   * WooCommerce refund record already exists (admin-initiated refunds record one
   * optimistically), it is now incorrect — flag it for manual reversal rather than
   * deleting it, since auto-reversal is destructive.
   */
  private static function process_refund_rejected($order, $data, $payload)
  {
    $refund_txn_id = $payload['transactionId'] ?? '';

    if (self::refund_state_recorded($order, $refund_txn_id, 'rejected')) {
      return self::refund_ack('Refund rejection already processed', $data);
    }

    $reason = isset($payload['metadata']['reason']) ? $payload['metadata']['reason'] : '';
    $has_wc_refund = !empty($order->get_refunds());

    if (class_exists('CentryOS_Refund_Status')) {
      CentryOS_Refund_Status::restore_prior_status($order);
    }

    $note = sprintf(
      // translators: refund rejection reason
      __('⚠ CentryOS REJECTED this refund request. Reason: %s.', 'centryos-payment-gateway-for-woocommerce'),
      $reason !== '' ? $reason : __('n/a', 'centryos-payment-gateway-for-woocommerce')
    );
    if ($has_wc_refund) {
      $note .= ' ' . __('A WooCommerce refund was already recorded for this order but the funds were NOT returned — please review and reverse the refund manually.', 'centryos-payment-gateway-for-woocommerce');
    }
    $order->add_order_note($note);

    self::record_refund_state($order, $refund_txn_id, 'rejected');

    do_action('centryos_webhook_refund_rejected', $order->get_id(), $data);

    return self::refund_ack('Refund rejection recorded', $data);
  }

  /**
   * Per-refund idempotency. Tracks the last processed state for each refund
   * transaction id on the order so duplicate webhook deliveries are no-ops.
   */
  private static function refund_state_recorded($order, $refund_txn_id, $state)
  {
    if (empty($refund_txn_id)) {
      return false;
    }
    $states = $order->get_meta('_centryos_refund_states');
    $states = is_array($states) ? $states : [];
    return isset($states[$refund_txn_id]) && $states[$refund_txn_id] === $state;
  }

  private static function record_refund_state($order, $refund_txn_id, $state)
  {
    if (!empty($refund_txn_id)) {
      $states = $order->get_meta('_centryos_refund_states');
      $states = is_array($states) ? $states : [];
      $states[$refund_txn_id] = $state;
      $order->update_meta_data('_centryos_refund_states', $states);
    }
    $order->save();
  }

  private static function refund_ack($message, $data)
  {
    return new WP_REST_Response([
      'success'   => true,
      'message'   => $message,
      'eventType' => $data['eventType'] ?? null,
      'status'    => $data['status'] ?? null,
    ], 200);
  }
}
