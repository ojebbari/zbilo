<?php
/**
 * SpaceRemit API Integration Class
 * Updated version with separate Live and Test API keys support
 */

if (!defined('ABSPATH')) {
    exit;
}

class SpaceRemit_API {
    
    const BASE_URL = 'https://spaceremit.com/apiinfo-v2';
    const TIMEOUT = 30;
    const MAX_RETRIES = 3;
    
    private $server_key;
    private $public_key;
    private $testmode;
    public $data_return;
    private $last_error;
    
    /**
     * Constructor
     */
    public function __construct($server_key = null, $public_key = null, $testmode = null) {
        // If specific keys are provided, use them
        if ($server_key && $public_key) {
            $this->server_key = $server_key;
            $this->public_key = $public_key;
            $this->testmode = $testmode !== null ? $testmode : false;
        } else {
            // Auto-detect keys based on gateway settings
            $this->testmode = $testmode !== null ? $testmode : ('yes' === $this->get_setting('testmode'));
            
            if ($this->testmode) {
                $this->server_key = $this->get_setting('test_secret_key');
                $this->public_key = $this->get_setting('test_public_key');
            } else {
                $this->server_key = $this->get_setting('live_secret_key');
                $this->public_key = $this->get_setting('live_public_key');
            }
            
            // Fallback to old key format for backward compatibility
            if (empty($this->server_key)) {
                $this->server_key = $this->get_setting('server_key');
            }
            if (empty($this->public_key)) {
                $this->public_key = $this->get_setting('public_key');
            }
        }
        
        SpaceRemit_Logger::debug('SpaceRemit API initialized', array(
            'testmode' => $this->testmode,
            'has_server_key' => !empty($this->server_key),
            'has_public_key' => !empty($this->public_key),
            'server_key_prefix' => !empty($this->server_key) ? substr($this->server_key, 0, 10) . '...' : 'empty',
            'public_key_prefix' => !empty($this->public_key) ? substr($this->public_key, 0, 10) . '...' : 'empty'
        ));
    }
    
    /**
     * Get gateway setting
     */
    private function get_setting($key) {
        $settings = get_option('woocommerce_spaceremit_settings', array());
        return $settings[$key] ?? '';
    }
    
    /**
     * Get current API keys info
     */
    public function get_keys_info() {
        return array(
            'testmode' => $this->testmode,
            'server_key_set' => !empty($this->server_key),
            'public_key_set' => !empty($this->public_key),
            'server_key_prefix' => !empty($this->server_key) ? substr($this->server_key, 0, 10) . '...' : '',
            'public_key_prefix' => !empty($this->public_key) ? substr($this->public_key, 0, 10) . '...' : ''
        );
    }
    
    /**
     * Send API request to SpaceRemit with retry logic
     */
    public function send_api_request($data, $request_method = 'POST', $retry_count = 0) {
        if (empty($this->server_key)) {
            $this->data_return = array(
                'response_status' => 'failed',
                'message' => sprintf(
                    __('Server key is not configured for %s mode.', 'spaceremit-woocommerce'),
                    $this->testmode ? 'test' : 'live'
                )
            );
            return false;
        }
        
        $data['private_key'] = $this->server_key;
        
        // Add test mode indicator
        if ($this->testmode) {
            $data['test_mode'] = true;
        }
        
        // Add request ID for tracking
        $request_id = wp_generate_uuid4();
        $data['request_id'] = $request_id;
        
        SpaceRemit_Logger::debug('Sending API request', array(
            'request_id' => $request_id,
            'method' => $request_method,
            'retry_count' => $retry_count,
            'testmode' => $this->testmode,
            'data_keys' => array_keys($data),
            'server_key_prefix' => substr($this->server_key, 0, 10) . '...'
        ));
        
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => self::BASE_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $request_method,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array(
                'authorization: ' . $this->server_key,
                'Content-Type: application/json',
                'User-Agent: SpaceRemit-WooCommerce/' . SPACEREMIT_WC_VERSION . ($this->testmode ? '-test' : '-live'),
                'X-Request-ID: ' . $request_id,
                'X-Test-Mode: ' . ($this->testmode ? '1' : '0')
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true
        ));

        $response = curl_exec($curl);
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        $curl_info = curl_getinfo($curl);
        curl_close($curl);

        // Log the request
        SpaceRemit_Logger::log_api_request(self::BASE_URL, $data, $response, $http_status);

        if ($curl_error) {
            $error_message = 'cURL Error: ' . $curl_error;
            SpaceRemit_Logger::error($error_message, array(
                'request_id' => $request_id,
                'testmode' => $this->testmode,
                'curl_info' => $curl_info
            ));
            
            // Retry on network errors
            if ($retry_count < self::MAX_RETRIES && $this->is_retryable_error($curl_error)) {
                sleep(pow(2, $retry_count)); // Exponential backoff
                return $this->send_api_request($data, $request_method, $retry_count + 1);
            }
            
            $this->data_return = array(
                'response_status' => 'failed',
                'message' => $error_message
            );
            return false;
        }

        if ($http_status === 200) {
            $decoded_response = json_decode($response, true);
            
            if ($decoded_response !== null) {
                SpaceRemit_Logger::debug('API request successful', array(
                    'request_id' => $request_id,
                    'testmode' => $this->testmode,
                    'response_status' => $decoded_response['response_status'] ?? 'unknown'
                ));
                
                $this->data_return = $decoded_response;
                return true;
            } else {
                $error_message = 'Failed to decode response JSON. Response: ' . substr($response, 0, 500);
                SpaceRemit_Logger::error($error_message, array(
                    'request_id' => $request_id,
                    'testmode' => $this->testmode,
                    'json_error' => json_last_error_msg()
                ));
                
                $this->data_return = array(
                    'response_status' => 'failed',
                    'message' => $error_message
                );
            }
        } else {
            $error_message = 'HTTP Error ' . $http_status . ': ' . substr($response, 0, 500);
            SpaceRemit_Logger::error($error_message, array(
                'request_id' => $request_id,
                'testmode' => $this->testmode,
                'http_status' => $http_status
            ));
            
            // Retry on server errors (5xx)
            if ($retry_count < self::MAX_RETRIES && $http_status >= 500) {
                sleep(pow(2, $retry_count));
                return $this->send_api_request($data, $request_method, $retry_count + 1);
            }
            
            $this->data_return = array(
                'response_status' => 'failed',
                'message' => $error_message
            );
        }
        
        return false;
    }
    
    /**
     * Check if error is retryable
     */
    private function is_retryable_error($error) {
        $retryable_errors = array(
            'Connection timed out',
            'Couldn\'t connect to server',
            'Operation timed out',
            'SSL connection timeout'
        );
        
        foreach ($retryable_errors as $retryable_error) {
            if (strpos($error, $retryable_error) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check payment status with enhanced validation
     */
    public function check_payment($payment_id, $acceptable_data = array()) {
        if (empty($payment_id)) {
            $this->data_return = array(
                'response_status' => 'failed',
                'message' => __('Payment ID is required.', 'spaceremit-woocommerce')
            );
            return false;
        }
        
        SpaceRemit_Logger::info('Checking payment status', array(
            'payment_id' => $payment_id,
            'testmode' => $this->testmode,
            'acceptable_data' => $acceptable_data
        ));
        
        $data = array('payment_id' => $payment_id);
        $response = $this->send_api_request($data);
        
        if (!$response) {
            return false;
        }
        
        $response_data = $this->data_return;
        
        if ($response_data["response_status"] !== "success") {
            SpaceRemit_Logger::warning('Payment check failed', array(
                'payment_id' => $payment_id,
                'testmode' => $this->testmode,
                'response' => $response_data
            ));
            
            $this->data_return = array(
                'response_status' => 'failed',
                'message' => $response_data["message"] ?? __('Payment verification failed.', 'spaceremit-woocommerce')
            );
            return false;
        }
        
        $payment_data = $response_data['data'] ?? array();
        
        if (empty($payment_data)) {
            $this->data_return = array(
                'response_status' => 'failed',
                'message' => __('No payment data received.', 'spaceremit-woocommerce')
            );
            return false;
        }
        
        // Validate acceptable data
        if (!empty($acceptable_data)) {
            $validation_result = $this->validate_payment_data($payment_data, $acceptable_data);
            if (!$validation_result['valid']) {
                SpaceRemit_Logger::warning('Payment data validation failed', array(
                    'payment_id' => $payment_id,
                    'testmode' => $this->testmode,
                    'validation_error' => $validation_result['error']
                ));
                
                $this->data_return = array(
                    'response_status' => 'failed',
                    'message' => $validation_result['error']
                );
                return false;
            }
        }
        
        SpaceRemit_Logger::info('Payment verification successful', array(
            'payment_id' => $payment_id,
            'testmode' => $this->testmode,
            'status_tag' => $payment_data['status_tag'] ?? 'unknown'
        ));
        
        $this->data_return = $payment_data;
        return true;
    }
    
    /**
     * Validate payment data against acceptable criteria
     */
    private function validate_payment_data($payment_data, $acceptable_data) {
        foreach ($acceptable_data as $key => $expected_value) {
            if (!isset($payment_data[$key])) {
                return array(
                    'valid' => false,
                    'error' => sprintf(__('Missing required field: %s', 'spaceremit-woocommerce'), $key)
                );
            }
            
            $actual_value = $payment_data[$key];
            
            if ($key === "status_tag") {
                if (is_array($expected_value)) {
                    if (!in_array($actual_value, $expected_value)) {
                        return array(
                            'valid' => false,
                            'error' => sprintf(
                                __('Invalid status tag: %s. Expected one of: %s', 'spaceremit-woocommerce'),
                                $actual_value,
                                implode(', ', $expected_value)
                            )
                        );
                    }
                } else {
                    if ($actual_value !== $expected_value) {
                        return array(
                            'valid' => false,
                            'error' => sprintf(
                                __('Invalid status tag: %s. Expected: %s', 'spaceremit-woocommerce'),
                                $actual_value,
                                $expected_value
                            )
                        );
                    }
                }
            } elseif ($key === "original_amount") {
                // Allow small floating point differences
                if (abs(floatval($actual_value) - floatval($expected_value)) > 0.01) {
                    return array(
                        'valid' => false,
                        'error' => sprintf(
                            __('Amount mismatch: %s. Expected: %s', 'spaceremit-woocommerce'),
                            $actual_value,
                            $expected_value
                        )
                    );
                }
            } else {
                if ($actual_value != $expected_value) {
                    return array(
                        'valid' => false,
                        'error' => sprintf(
                            __('Invalid %s: %s. Expected: %s', 'spaceremit-woocommerce'),
                            $key,
                            $actual_value,
                            $expected_value
                        )
                    );
                }
            }
        }
        
        return array('valid' => true);
    }
    
    /**
     * Get payment status tags
     */
    public function get_status_tags($include_test = null) {
        if ($include_test === null) {
            $include_test = $this->testmode;
        }
        
        $tags = array("A", "B", "D", "E", "F");
        
        if ($include_test) {
            $tags[] = "T"; // Test payments
        }
        
        return $tags;
    }
    
    /**
     * Get paid status tags
     */
    public function get_paid_status_tags($include_test = null) {
        $all_tags = $this->get_status_tags($include_test);
        return array_diff($all_tags, array("F")); // Remove failed status
    }
    
    /**
     * Convert status tag to readable status
     */
    public function get_status_label($status_tag) {
        $statuses = array(
            'A' => __('Completed', 'spaceremit-woocommerce'),
            'B' => __('Pending', 'spaceremit-woocommerce'),
            'C' => __('Refused', 'spaceremit-woocommerce'),
            'D' => __('Processing', 'spaceremit-woocommerce'),
            'E' => __('Expired', 'spaceremit-woocommerce'),
            'F' => __('Failed', 'spaceremit-woocommerce'),
            'T' => __('Test Payment', 'spaceremit-woocommerce')
        );
        
        return $statuses[$status_tag] ?? __('Unknown', 'spaceremit-woocommerce');
    }
    
    /**
     * Get status color for display
     */
    public function get_status_color($status_tag) {
        $colors = array(
            'A' => '#46b450', // Green
            'B' => '#ffb900', // Yellow
            'C' => '#dc3232', // Red
            'D' => '#00a0d2', // Blue
            'E' => '#666666', // Gray
            'F' => '#dc3232', // Red
            'T' => '#9b59b6'  // Purple
        );
        
        return $colors[$status_tag] ?? '#666666';
    }
    
    /**
     * Validate webhook signature (placeholder for future implementation)
     */
    public function validate_webhook($payload, $signature = null) {
        // TODO: Implement signature validation when SpaceRemit provides it
        SpaceRemit_Logger::debug('Webhook validation called', array(
            'has_signature' => !empty($signature),
            'payload_length' => strlen($payload),
            'testmode' => $this->testmode
        ));
        
        return true;
    }
    
    /**
     * Get last error
     */
    public function get_last_error() {
        return $this->last_error;
    }
    
    /**
     * Test API connection
     */
    public function test_connection() {
        SpaceRemit_Logger::info('Testing API connection', array(
            'testmode' => $this->testmode,
            'server_key_prefix' => substr($this->server_key, 0, 10) . '...'
        ));
        
        $test_data = array(
            'test_connection' => true,
            'timestamp' => time()
        );
        
        $response = $this->send_api_request($test_data);
        
        if ($response) {
            SpaceRemit_Logger::info('API connection test successful', array(
                'testmode' => $this->testmode
            ));
            return array(
                'success' => true,
                'message' => sprintf(
                    __('Connection successful (%s mode)', 'spaceremit-woocommerce'),
                    $this->testmode ? 'test' : 'live'
                )
            );
        } else {
            SpaceRemit_Logger::error('API connection test failed', array(
                'testmode' => $this->testmode,
                'error' => $this->data_return
            ));
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Connection failed (%s mode): %s', 'spaceremit-woocommerce'),
                    $this->testmode ? 'test' : 'live',
                    $this->data_return['message'] ?? __('Unknown error', 'spaceremit-woocommerce')
                )
            );
        }
    }
    
    /**
     * Verify API keys
     */
    public function verify_keys($live_public_key = null, $live_secret_key = null, $test_public_key = null, $test_secret_key = null) {
        $results = array();
        
        // Test live keys if provided
        if ($live_public_key && $live_secret_key) {
            $live_api = new SpaceRemit_API($live_secret_key, $live_public_key, false);
            $live_result = $live_api->test_connection();
            $results['live'] = $live_result;
        }
        
        // Test test keys if provided
        if ($test_public_key && $test_secret_key) {
            $test_api = new SpaceRemit_API($test_secret_key, $test_public_key, true);
            $test_result = $test_api->test_connection();
            $results['test'] = $test_result;
        }
        
        return $results;
    }
}