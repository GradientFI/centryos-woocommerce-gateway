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
    
    /**
     * API Base URLs for different environments
     */
    private static $api_base_urls = [
        'staging' => [
            'accounts' => 'https://staging-api.accounts.walletos.xyz',
            'liquidity' => 'https://staging-api.liquidity.walletos.xyz'
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
            $this->log_error('JWT generation failed', $response);
            return $response;
        }
    
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if (isset($data['data']['token'])) {
            return $data['data']['token'];
        }
        
        $this->log_error('JWT response invalid', $body);
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
        
        $response = wp_remote_post($this->get_payment_link_endpoint(), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 20
        ]);
        
        if (is_wp_error($response)) {
            $this->log_error('Payment link creation failed', $response);
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['data']['url'])) {
            return $data['data']['url'];
        }
        
        $this->log_error('Payment link response invalid', $body);
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
            $this->log_error( 'Refund request failed', $response );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = isset( $data['message'] ) ? $data['message'] : __( 'Refund request failed.', 'centryos-payment-gateway-for-woocommerce' );
            $this->log_error( 'Refund API error', $body );
            return new WP_Error( 'refund_failed', $message );
        }

        return isset( $data['data'] ) ? $data['data'] : [];
    }

    /**
     * Log error for debugging
     * 
     * @param string $message Error message
     * @param mixed $data Additional data
     */
    private function log_error($message, $data) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // Only log if both WP_DEBUG and WP_DEBUG_LOG are enabled
            if (is_array($data) || is_object($data)) {
                error_log(sprintf('[CentryOS Gateway] %s: %s', $message, wp_json_encode($data)));
            } else {
                error_log(sprintf('[CentryOS Gateway] %s: %s', $message, $data));
            }
        }
    }
}