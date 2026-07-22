<?php

/**
 * CentryOS custom "Refund Pending" order status.
 *
 * Represents CentryOS's AWAITING_APPROVAL refund stage, which WooCommerce has no
 * native status for. The status is driven by the refund webhooks (see
 * CentryOS_Webhook_Handler); this class owns the registration, the pre-refund
 * status capture used to restore an order if a refund is rejected, and the admin
 * UI guard that hides the Refund button while a refund is awaiting approval.
 *
 * @package CentryOS_Gateway
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
  exit;
}

class CentryOS_Refund_Status
{

  /** Status slug without the wc- prefix (as returned by $order->get_status()). */
  const STATUS = 'refund-pending';

  /** Post status key (with the wc- prefix WooCommerce uses internally). */
  const POST_STATUS = 'wc-refund-pending';

  /** Order meta storing the paid status to restore to if a refund is rejected. */
  const PRIOR_STATUS_META = '_centryos_pre_refund_status';

  /** Paid statuses we are willing to capture / restore to. */
  private static $paid_statuses = ['processing', 'completed', 'on-hold'];

  /**
   * When true, a webhook-driven *approved* refund is being created, so the
   * full-refund status filter leaves WooCommerce's default ("refunded") intact
   * instead of forcing refund-pending.
   */
  public static $suppress_pending_on_full_refund = false;

  public static function init()
  {
    add_action('init', [__CLASS__, 'register_status']);
    add_filter('wc_order_statuses', [__CLASS__, 'add_status']);
    add_action('woocommerce_create_refund', [__CLASS__, 'capture_prior_status'], 10, 2);
    add_filter('woocommerce_order_fully_refunded_status', [__CLASS__, 'force_pending_on_full_refund'], 10, 2);
    add_action('woocommerce_admin_order_data_after_order_details', [__CLASS__, 'disable_admin_refund_button']);
  }

  /**
   * Hooked to woocommerce_order_fully_refunded_status. WooCommerce sets a fully
   * refunded order to "refunded" the instant the refund is created — for a CentryOS
   * order that would skip the awaiting-approval stage entirely. Redirect it to
   * refund-pending so only an approved refund webhook can mark the order refunded.
   * Our own webhook-driven approved refund sets the suppress flag to keep the default.
   *
   * @param string $status   Default parent status WooCommerce would apply.
   * @param int    $order_id  Order being refunded.
   * @return string
   */
  public static function force_pending_on_full_refund($status, $order_id)
  {
    if (self::$suppress_pending_on_full_refund) {
      return $status;
    }
    $order = wc_get_order($order_id);
    if ($order instanceof WC_Order && 'centryos_gateway' === $order->get_payment_method()) {
      return self::STATUS;
    }
    return $status;
  }

  /**
   * Register the custom order status as a post status.
   */
  public static function register_status()
  {
    register_post_status(self::POST_STATUS, [
      'label'                     => _x('Refund Pending', 'Order status', 'centryos-payment-gateway-for-woocommerce'),
      'public'                    => false,
      'internal'                  => false,
      'exclude_from_search'       => false,
      'show_in_admin_all_list'    => true,
      'show_in_admin_status_list' => true,
      // translators: %s: number of orders awaiting refund approval
      'label_count'               => _n_noop(
        'Refund Pending <span class="count">(%s)</span>',
        'Refund Pending <span class="count">(%s)</span>',
        'centryos-payment-gateway-for-woocommerce'
      ),
    ]);
  }

  /**
   * Add the status to the WooCommerce order status list (admin dropdown + filters),
   * inserted right after "Processing".
   */
  public static function add_status($statuses)
  {
    $reordered = [];
    foreach ($statuses as $key => $label) {
      $reordered[$key] = $label;
      if ('wc-processing' === $key) {
        $reordered[self::POST_STATUS] = _x('Refund Pending', 'Order status', 'centryos-payment-gateway-for-woocommerce');
      }
    }
    // Fallback if wc-processing was not present for some reason.
    if (!isset($reordered[self::POST_STATUS])) {
      $reordered[self::POST_STATUS] = _x('Refund Pending', 'Order status', 'centryos-payment-gateway-for-woocommerce');
    }
    return $reordered;
  }

  /**
   * Hooked to woocommerce_create_refund (fires during refund creation, before any
   * order status flip). Captures the pre-refund paid status for CentryOS orders so
   * a later rejection can restore it.
   *
   * @param WC_Order_Refund $refund
   * @param array           $args
   */
  public static function capture_prior_status($refund, $args)
  {
    if (empty($args['order_id'])) {
      return;
    }
    $order = wc_get_order($args['order_id']);
    self::capture_prior_status_value($order);
  }

  /**
   * Store the order's current status as the pre-refund status, but only for
   * CentryOS orders, only once, and only when the current status is a paid status
   * (so we never capture an already-refunded/pending state).
   *
   * @param WC_Order|false $order
   */
  public static function capture_prior_status_value($order)
  {
    if (!$order instanceof WC_Order) {
      return;
    }
    if ('centryos_gateway' !== $order->get_payment_method()) {
      return;
    }
    if ($order->get_meta(self::PRIOR_STATUS_META)) {
      return;
    }
    if (!in_array($order->get_status(), self::$paid_statuses, true)) {
      return;
    }
    $order->update_meta_data(self::PRIOR_STATUS_META, $order->get_status());
    $order->save();
  }

  /**
   * Move an order into the refund-pending status.
   *
   * @param WC_Order $order
   */
  public static function set_pending($order)
  {
    if (!$order instanceof WC_Order) {
      return;
    }
    if ($order->get_status() === self::STATUS) {
      return;
    }
    $order->update_status(self::STATUS, __('Refund awaiting approval on CentryOS.', 'centryos-payment-gateway-for-woocommerce'));
  }

  /**
   * Restore an order from refund-pending back to its captured pre-refund paid
   * status (defaulting to processing), then clear the captured value.
   *
   * @param WC_Order $order
   */
  public static function restore_prior_status($order)
  {
    if (!$order instanceof WC_Order) {
      return;
    }
    $prior = $order->get_meta(self::PRIOR_STATUS_META);
    if (empty($prior) || !in_array($prior, self::$paid_statuses, true)) {
      $prior = 'processing';
    }
    $order->update_status($prior, __('Restored from refund-pending after CentryOS refund resolution.', 'centryos-payment-gateway-for-woocommerce'));
    $order->delete_meta_data(self::PRIOR_STATUS_META);
    $order->save();
  }

  /**
   * Hide/disable the admin order-items "Refund" button while a refund is awaiting
   * approval, so a second refund request can't be submitted. UI-only guard; the
   * authoritative block lives in CentryOS_Gateway::process_refund().
   *
   * @param WC_Order $order
   */
  public static function disable_admin_refund_button($order)
  {
    if (!$order instanceof WC_Order || $order->get_status() !== self::STATUS) {
      return;
    }
    $message = esc_js(__('A refund for this order is already awaiting approval on CentryOS.', 'centryos-payment-gateway-for-woocommerce'));
    ?>
    <style>.wc-order-data-row .refund-items{display:none !important;}</style>
    <script>
      jQuery(function ($) {
        var $btn = $('button.refund-items');
        if ($btn.length) {
          $btn.prop('disabled', true).attr('title', '<?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>');
          $btn.after('<span class="description" style="margin-left:8px;">⚠ <?php echo $message; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>');
        }
      });
    </script>
    <?php
  }
}
