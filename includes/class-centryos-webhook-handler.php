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

    self::log('info', 'received', ['body' => $body]);

    // Verify webhook signature
    $signature = $request->get_header('signature');
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
}
