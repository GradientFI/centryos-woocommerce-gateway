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
    $data = json_decode($request->get_body(), true);

    // Log webhook for debugging
    if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
        // Only log if both WP_DEBUG and WP_DEBUG_LOG are enabled
        error_log('[CentryOS Webhook] Received: ' . wp_json_encode($data));
    }

    // Verify webhook signature
    $signature = $request->get_header('signature');
    $secret = defined('CENTRYOS_WEBHOOK_SECRET') ? CENTRYOS_WEBHOOK_SECRET : '';

    if (empty($secret)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Webhook secret not configured.'
        ], 500);
    }

    if (empty($signature) || !self::verify_signature($request->get_body(), $signature, $secret)) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Invalid or missing signature.'
        ], 403);
    }

    // Validate payload
    if (empty($data['payload']['orderId'])) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order ID not found in payload.'
        ], 400);
    }

    $order_id = intval($data['payload']['orderId']);
    $order = wc_get_order($order_id);

    if (!$order) {
        return new WP_REST_Response([
            'success' => false,
            'message' => 'Order not found: ' . $order_id
        ], 404);
    }

    // Check payment status
    if (self::is_payment_successful($data)) {
        return self::process_successful_payment($order, $data);
    }

    // Payment not successful
    // translators: %s: JSON encoded webhook data
    $order->add_order_note(sprintf(
        __('Webhook received (payment not successful): %s', 'centryos-payment-gateway-for-woocommerce'),
        wp_json_encode($data)
    ));

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Webhook received but payment not successful',
        'status'  => $data['status'] ?? 'unknown'
    ], 200);
  }

  private static function verify_signature($payload, $signature, $secret) 
  {
    $computed_signature = hash_hmac('sha512', $payload, $secret);
    return hash_equals($computed_signature, $signature);
  }


  private function get_credential($setting_key, $constant_name)
  {
    if (defined($constant_name)) {
      return constant($constant_name);
    }
    return $this->get_option($setting_key, '');
  }

  /**
   * Check if payment was successful
   */
  private static function is_payment_successful($data)
  {
    if (isset($data['status']) && strtolower($data['status']) === 'success') {
      return true;
    }

    if (
      isset($data['eventType']) && $data['eventType'] === 'COLLECTION' &&
      isset($data['status']) && $data['status'] === 'SUCCESS'
    ) {
      return true;
    }

    return false;
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
