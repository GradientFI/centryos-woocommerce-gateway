<?php
/**
 * CentryOS API Client
 * 
 * Handles all API communication with CentryOS
 *
 * @package CentryOS_Gateway
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CentryOS_API_Client {
    
    const JWT_ENDPOINT_PATH = '/v1/ext/jwt/generate-token';
    const PAYMENT_LINK_ENDPOINT_PATH = '/v1/ext/collections/payment-link';
    const REFUND_ENDPOINT_PATH = '/v1/ext/transaction/%s/refund-request';
    const RECURRING_GET_ENDPOINT_PATH = '/v1/ext/collections/recurring/%s';
    const RECURRING_CANCEL_ENDPOINT_PATH = '/v1/ext/collections/recurring/%s/cancel';
    
    /**
     * API Base URLs for different environments
     */
    private static $api_base_urls = [
        'staging' => [
            'accounts' => 'https://account-staging-api.centryos.xyz',
            'liquidity' => 'https://liquidity-staging-api.centryos.xyz'
        ],
        'production' => [
            'accounts' => 'https://user-accounts-api.centryos.xyz',
            'liquidity' => 'https://ledger-api.centryos.xyz'
        ]
    ];
    
    /**
     * @var string Client ID
     */
    private $client_id;
    
    /**
     * @var string API Secret
     */
    private $secret;
    
    /**
     * @var string API Environment (staging or production)
     */
    private $environment;
    
    /**
     * Constructor
     */
    public function __construct($client_id, $secret, $environment = 'staging') {
        $this->client_id = $client_id;
        $this->secret = $secret;
        $this->environment = in_array($environment, ['staging', 'production']) ? $environment : 'staging';
    }
    
    /**
     * Get full JWT endpoint URL
     */
    private function get_jwt_endpoint() {
        $base_url = self::$api_base_urls[$this->environment]['accounts'];
        return $base_url . self::JWT_ENDPOINT_PATH;
    }
    
    /**
     * Get full payment link endpoint URL
     */
    private function get_payment_link_endpoint() {
        $base_url = self::$api_base_urls[$this->environment]['liquidity'];
        return $base_url . self::PAYMENT_LINK_ENDPOINT_PATH;
    }

    /**
     * Get full refund endpoint URL for a given transaction
     */
    private function get_refund_endpoint( $transaction_id ) {
        $base_url = self::$api_base_urls[$this->environment]['liquidity'];
        return $base_url . sprintf( self::REFUND_ENDPOINT_PATH, $transaction_id );
    }
    
    /**
     * Generate JWT token
     * 
     * @return string|WP_Error Token or error
     */
    public function generate_jwt() {
        if (empty($this->client_id) || empty($this->secret)) {
            return new WP_Error('no_credentials', __('Client ID or API Secret not set.', 'centryos-payment-gateway-for-woocommerce'));
        }
    
        $response = wp_remote_post($this->get_jwt_endpoint(), [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->secret),
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode((object)[]),
            'timeout' => 20
        ]);
    
        if (is_wp_error($response)) {
            $this->log_event('error', 'JWT generation failed', [
                'wp_error' => $response->get_error_messages(),
            ]);
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['data']['token'])) {
            return $data['data']['token'];
        }

        $this->log_event('error', 'JWT response invalid', ['body' => $body]);
        return new WP_Error('jwt_failed', __('Failed to generate JWT token.', 'centryos-payment-gateway-for-woocommerce'));
    }
    
    /**
     * Create payment link
     * 
     * @param array $payload Payment data
     * @return string|WP_Error Payment URL or error
     */
    public function create_payment_link($payload) {
        $token = $this->generate_jwt();

        if (is_wp_error($token)) {
            return $token;
        }

        $endpoint = $this->get_payment_link_endpoint();
        $this->log_event('info', 'create_payment_link request', [
            'endpoint' => $endpoint,
            'payload'  => $payload,
        ]);

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20
        ]);

        if (is_wp_error($response)) {
            $this->log_event('error', 'Payment link creation failed', [
                'wp_error' => $response->get_error_messages(),
            ]);
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        $this->log_event('info', 'create_payment_link response', [
            'http_code' => $http_code,
            'body'      => $body,
        ]);

        if (isset($data['data']['url'])) {
            return $data['data']['url'];
        }

        $this->log_event('error', 'Payment link response invalid', [
            'http_code' => $http_code,
            'body'      => $body,
        ]);
        return new WP_Error('create_failed', __('Failed to create payment link.', 'centryos-payment-gateway-for-woocommerce'));
    }
    
    /**
     * Submit a refund request for a transaction
     *
     * @param string $transaction_id CentryOS transaction ID
     * @param float  $amount         Refund amount
     * @param string $reason         Optional refund reason
     * @return array|WP_Error        Parsed response data or error
     */
    public function create_refund( $transaction_id, $amount, $reason = '' ) {
        $token = $this->generate_jwt();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $payload = [ 'amount' => floatval( $amount ) ];
        if ( ! empty( $reason ) ) {
            $payload['reason'] = $reason;
        }

        $response = wp_remote_post( $this->get_refund_endpoint( $transaction_id ), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_event( 'error', 'Refund request failed', [
                'wp_error' => $response->get_error_messages(),
            ] );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : __( 'Refund request failed.', 'centryos-payment-gateway-for-woocommerce' );
            $this->log_event( 'error', 'Refund API error', [
                'http_code' => $http_code,
                'body'      => $body,
            ] );
            return new WP_Error( 'refund_failed', $message );
        }

        return isset( $data['data'] ) ? $data['data'] : [];
    }

    /**
     * Cancel a recurring payment (subscription).
     *
     * @param string $subscription_id    CentryOS recurring payment id.
     * @param bool   $cancel_at_period_end When true (default) the subscription
     *                                      stays active until the current paid
     *                                      period ends; false cancels immediately.
     * @return array|WP_Error             Parsed response data or error.
     */
    public function cancel_subscription( $subscription_id, $cancel_at_period_end = true ) {
        $token = $this->generate_jwt();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $base_url = self::$api_base_urls[$this->environment]['liquidity'];
        $endpoint = $base_url . sprintf( self::RECURRING_CANCEL_ENDPOINT_PATH, rawurlencode( $subscription_id ) );

        $this->log_event( 'info', 'cancel_subscription request', [
            'endpoint'           => $endpoint,
            'subscription_id'    => $subscription_id,
            'cancelAtPeriodEnd'  => (bool) $cancel_at_period_end,
        ] );

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'cancelAtPeriodEnd' => (bool) $cancel_at_period_end ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_event( 'error', 'cancel_subscription failed', [
                'wp_error' => $response->get_error_messages(),
            ] );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        $this->log_event( 'info', 'cancel_subscription response', [
            'http_code' => $http_code,
            'body'      => $body,
        ] );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : __( 'Failed to cancel subscription.', 'centryos-payment-gateway-for-woocommerce' );
            return new WP_Error( 'cancel_failed', $message );
        }

        return isset( $data['data'] ) ? $data['data'] : [];
    }

    /**
     * Fetch a single recurring payment for on-demand status sync.
     *
     * @param string $subscription_id CentryOS recurring payment id.
     * @return array|WP_Error         Parsed response data or error.
     */
    public function get_recurring_payment( $subscription_id ) {
        $token = $this->generate_jwt();

        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $base_url = self::$api_base_urls[$this->environment]['liquidity'];
        $endpoint = $base_url . sprintf( self::RECURRING_GET_ENDPOINT_PATH, rawurlencode( $subscription_id ) );

        $response = wp_remote_get( $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            $this->log_event( 'error', 'get_recurring_payment failed', [
                'wp_error' => $response->get_error_messages(),
            ] );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : __( 'Failed to fetch subscription.', 'centryos-payment-gateway-for-woocommerce' );
            return new WP_Error( 'get_recurring_failed', $message );
        }

        return isset( $data['data'] ) ? $data['data'] : [];
    }

    /**
     * Write a structured entry to PHP's error_log and (when available) the
     * WooCommerce log (Status > Logs > centryos-gateway). Logging is unconditional
     * so events are visible without WP_DEBUG.
     */
    private function log_event($level, $message, array $context = []) {
        $line = $message;
        if (!empty($context)) {
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($context) : json_encode($context);
            $line   .= ' ' . $encoded;
        }

        error_log('[CentryOS Gateway][' . $level . '] ' . $line);

        if (function_exists('wc_get_logger')) {
            try {
                wc_get_logger()->log($level, $line, ['source' => 'centryos-gateway']);
            } catch (\Throwable $e) {
                error_log('[CentryOS Gateway][error] wc_get_logger threw: ' . $e->getMessage());
            }
        }
    }
}