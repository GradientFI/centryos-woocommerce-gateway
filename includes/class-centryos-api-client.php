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
    
    /**
     * API Base URLs for different environments
     */
    private static $api_base_urls = [
        'staging' => [
            'accounts' => 'https://staging-api.accounts.walletos.xyz',
            'liquidity' => 'https://staging-api.liquidity.walletos.xyz'
        ],
        'production' => [
            'accounts' => 'https://api.accounts.walletos.xyz',
            'liquidity' => 'https://api.liquidity.walletos.xyz'
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