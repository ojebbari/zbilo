<?php
/**
 * SpaceRemit Callback Handler
 * Enhanced version with proper webhook validation, idempotency, and error handling
 */

if (!defined('ABSPATH')) {
    exit;
}

class SpaceRemit_Callback {

    /**
     * Constructor
     */
    public function __construct() {
        // Register query var ?spaceremit_callback=1
        add_filter('query_vars', array($this, 'add_query_vars'));

        // Handle all callbacks on template_redirect (before template loads)
        add_action('template_redirect', array($this, 'handle_callback'), 5);
    }

    /**
     * Add query vars
     */
    public function add_query_vars($vars) {
        $vars[] = 'spaceremit_callback';
        return $vars;
    }

    /**
     * Handle SpaceRemit callback entry point
     * Runs on template_redirect to prevent HTML output
     */
    public function handle_callback() {
        // Check if this is a SpaceRemit callback
        if (!get_query_var('spaceremit_callback')) {
            return;
        }

        SpaceRemit_Logger::debug('SpaceRemit callback triggered', array(
            'method' => $_SERVER['REQUEST_METHOD'],
            'has_payment_code' => isset($_POST['SP_payment_code']),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));

        // Server-to-server webhook (JSON POST without form data)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['SP_payment_code'])) {
            // Clean any output buffers and set JSON headers early
            while (ob_get_level()) {
                ob_end_clean();
            }
            
            // Prevent any HTML output
            remove_all_actions('wp_head');
            remove_all_actions('wp_footer');
            
            $this->handle_webhook_notification();
            // Ensure execution stops (handle_webhook_notification always exits, but belt and suspenders)
            die();
        }

        // Browser return via POST from payment page (form submission)
        elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SP_payment_code'])) {
            $this->handle_payment_callback();
            // Ensure execution stops (handle_payment_callback redirects and exits, but belt and suspenders)
            die();
        }

        // GET request - redirect to thank-you page
        elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->handle_get_return();
            // Ensure execution stops (handle_get_return redirects and exits, but belt and suspenders)
            die();
        }
        
        // If we somehow get here, prevent any template rendering
        exit;
    }

    /**
     * Handle GET callback and redirect to thank-you page
     */
    private function handle_get_return() {

        // Try to resolve order from query, or from stored transaction by payment id
        $payment_id = isset($_GET['SP_payment_code']) ? sanitize_text_field($_GET['SP_payment_code']) : (isset($_GET['payment_id']) ? sanitize_text_field($_GET['payment_id']) : '');
        $order_id   = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;

        $order = $order_id ? wc_get_order($order_id) : ($payment_id ? $this->get_order_by_spaceremit_id($payment_id) : false);
        if (!$order) {
            SpaceRemit_Logger::warning('GET return: Order not found', array(
                'payment_id' => $payment_id,
                'order_id' => $order_id
            ));
            wp_safe_redirect(wc_get_page_permalink('checkout'));
            exit;
        }

        // Verify order key for security
        $key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        if ($order && $key && hash_equals($order->get_order_key(), $key)) {
            // Optional: sync status if SpaceRemit returned a payment_id on the GET
            if (!empty($payment_id)) {
                $gateway = new WC_Gateway_SpaceRemit();
                $api     = new SpaceRemit_API($gateway->server_key, $gateway->public_key);
                if ($api->check_payment($payment_id, array())) {
                    $this->update_payment_status($order, $api->data_return);
                }
            }

            // Land on the native WooCommerce thank-you page
            wp_safe_redirect($order->get_checkout_order_received_url());
            exit;
        }

        // If key doesn't match, still try to sync status but redirect safely
        if ($payment_id) {
            $gateway = new WC_Gateway_SpaceRemit();
            $api     = new SpaceRemit_API($gateway->server_key, $gateway->public_key);
            if ($api->check_payment($payment_id, array())) {
                $this->update_payment_status($order, $api->data_return);
            }
        }

        // Always land on WooCommerce order received (thank-you) page
        wp_safe_redirect($order->get_checkout_order_received_url());
        exit;
    }

    /**
     * Handle payment callback from checkout form (POST)
     */
    private function handle_payment_callback() {
        $payment_code = sanitize_text_field($_POST['SP_payment_code']);
        $order_id     = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;

        SpaceRemit_Logger::info('Processing payment callback', array(
            'payment_code' => $payment_code,
            'order_id' => $order_id
        ));

        if (!$order_id || !$payment_code) {
            SpaceRemit_Logger::error('Payment callback: Invalid data', array(
                'has_order_id' => !empty($order_id),
                'has_payment_code' => !empty($payment_code)
            ));
            wp_die(__('Invalid payment data.', 'spaceremit-woocommerce'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            SpaceRemit_Logger::error('Payment callback: Order not found', array('order_id' => $order_id));
            wp_die(__('Order not found.', 'spaceremit-woocommerce'));
        }

        // Verify with SpaceRemit API
        $gateway = new WC_Gateway_SpaceRemit();
        $api     = new SpaceRemit_API($gateway->server_key, $gateway->public_key);

        $acceptable_data = array(
            'currency'        => $order->get_currency(),
            'original_amount' => floatval($order->get_total()),
            'status_tag'      => $api->get_status_tags($gateway->testmode),
        );

        $response = $api->check_payment($payment_code, $acceptable_data);

        if ($response) {
            $payment_details = $api->data_return;
            $this->process_payment_response($order, $payment_details, $payment_code);
        } else {
            $error_message = is_array($api->data_return) && isset($api->data_return['message']) ? $api->data_return['message'] : 'unknown error';
            SpaceRemit_Logger::error('Payment verification failed', array(
                'payment_code' => $payment_code,
                'order_id' => $order_id,
                'error' => $error_message
            ));
            $order->add_order_note(__('SpaceRemit payment verification failed: ', 'spaceremit-woocommerce') . $error_message);
            wp_redirect($order->get_cancel_order_url());
            exit;
        }
    }

    /**
     * Handle webhook notification from SpaceRemit (JSON POST)
     * Enhanced with validation, idempotency, and comprehensive logging
     * Always returns clean JSON response (no HTML)
     */
    private function handle_webhook_notification() {
        // Set JSON headers immediately before ANY output
        header('Content-Type: application/json; charset=utf-8');
        header('X-Robots-Tag: noindex');
        
        // Disable WordPress error display
        @ini_set('display_errors', 0);
        
        $start_time = microtime(true);
        
        // Get raw input
        $json_data = file_get_contents('php://input');
        $request_data = json_decode($json_data, true);

        SpaceRemit_Logger::info('Webhook notification received', array(
            'content_length' => strlen($json_data),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ));

        // Validate JSON structure
        if ($request_data === null) {
            SpaceRemit_Logger::error('Webhook: Invalid JSON', array(
                'json_error' => json_last_error_msg(),
                'raw_input_preview' => substr($json_data, 0, 200)
            ));
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Invalid JSON data', 'message' => json_last_error_msg()));
            exit;
        }

        // Validate webhook signature if provided
        if (!$this->validate_webhook_signature($json_data)) {
            SpaceRemit_Logger::error('Webhook: Signature validation failed', array(
                'has_signature' => isset($_SERVER['HTTP_X_SPACEREMIT_SIGNATURE'])
            ));
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Unauthorized', 'message' => 'Invalid webhook signature'));
            exit;
        }

        // Extract payment ID
        $payment_id = isset($request_data['data']['id']) ? sanitize_text_field($request_data['data']['id']) : '';
        
        if (empty($payment_id)) {
            SpaceRemit_Logger::error('Webhook: Missing payment ID', array(
                'request_structure' => array_keys($request_data)
            ));
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Bad Request', 'message' => 'Missing payment ID'));
            exit;
        }

        // Find order
        $order = $this->get_order_by_spaceremit_id($payment_id);
        if (!$order) {
            SpaceRemit_Logger::warning('Webhook: Order not found', array(
                'payment_id' => $payment_id
            ));
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Not Found', 'message' => 'Order not found for payment ID: ' . $payment_id));
            exit;
        }

        $order_id = $order->get_id();

        SpaceRemit_Logger::info('Webhook: Order found', array(
            'payment_id' => $payment_id,
            'order_id' => $order_id,
            'current_order_status' => $order->get_status()
        ));

        // Check idempotency - prevent duplicate processing
        $webhook_processed = $this->is_webhook_already_processed($payment_id, $request_data);
        if ($webhook_processed) {
            SpaceRemit_Logger::info('Webhook: Already processed (idempotent)', array(
                'payment_id' => $payment_id,
                'order_id' => $order_id
            ));
            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(array('status' => 'success', 'message' => 'Already processed', 'order_id' => $order_id));
            exit;
        }

        // Verify payment with SpaceRemit API
        $gateway = new WC_Gateway_SpaceRemit();
        $api     = new SpaceRemit_API($gateway->server_key, $gateway->public_key);

        if ($api->check_payment($payment_id, array())) {
            $payment_details = $api->data_return;
            
            SpaceRemit_Logger::info('Webhook: Payment verified', array(
                'payment_id' => $payment_id,
                'order_id' => $order_id,
                'status_tag' => $payment_details['status_tag'] ?? 'unknown',
                'amount' => $payment_details['original_amount'] ?? 'unknown'
            ));

            // Update payment status
            $this->update_payment_status($order, $payment_details);

            // Mark webhook as processed
            $this->mark_webhook_processed($payment_id, $request_data);

            // Calculate processing time
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);

            SpaceRemit_Logger::info('Webhook: Successfully processed', array(
                'payment_id' => $payment_id,
                'order_id' => $order_id,
                'new_order_status' => $order->get_status(),
                'processing_time_ms' => $processing_time
            ));

            http_response_code(200);
            header('Content-Type: application/json');
            echo json_encode(array(
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'order_id' => $order_id,
                'payment_id' => $payment_id,
                'order_status' => $order->get_status(),
                'processing_time_ms' => $processing_time
            ));
            exit;
        } else {
            $error_message = is_array($api->data_return) && isset($api->data_return['message']) ? $api->data_return['message'] : 'Payment verification failed';
            
            SpaceRemit_Logger::error('Webhook: Payment verification failed', array(
                'payment_id' => $payment_id,
                'order_id' => $order_id,
                'error' => $error_message
            ));

            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'Verification Failed', 'message' => $error_message));
            exit;
        }
    }

    /**
     * Validate webhook signature
     */
    private function validate_webhook_signature($json_data) {
        // Check if signature validation is enabled
        $gateway = new WC_Gateway_SpaceRemit();
        $webhook_secret = $gateway->get_option('webhook_secret', '');

        // If no webhook secret is configured, skip validation
        if (empty($webhook_secret)) {
            SpaceRemit_Logger::debug('Webhook signature validation skipped (no secret configured)');
            return true;
        }

        // Get signature from header
        $received_signature = isset($_SERVER['HTTP_X_SPACEREMIT_SIGNATURE']) ? $_SERVER['HTTP_X_SPACEREMIT_SIGNATURE'] : '';
        
        if (empty($received_signature)) {
            SpaceRemit_Logger::warning('Webhook signature missing in header');
            return false;
        }

        // Calculate expected signature
        $expected_signature = hash_hmac('sha256', $json_data, $webhook_secret);

        // Compare signatures
        if (hash_equals($expected_signature, $received_signature)) {
            SpaceRemit_Logger::debug('Webhook signature validated successfully');
            return true;
        }

        SpaceRemit_Logger::error('Webhook signature mismatch', array(
            'expected_prefix' => substr($expected_signature, 0, 10),
            'received_prefix' => substr($received_signature, 0, 10)
        ));

        return false;
    }

    /**
     * Check if webhook has already been processed (idempotency)
     */
    private function is_webhook_already_processed($payment_id, $request_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spaceremit_transactions';
        $status_tag = isset($request_data['data']['status_tag']) ? $request_data['data']['status_tag'] : '';

        // Get current status from database
        $current_transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT status_tag, updated_at FROM $table_name WHERE spaceremit_payment_id = %s",
            $payment_id
        ));

        if (!$current_transaction) {
            return false;
        }

        // If status hasn't changed and was updated recently (within 5 minutes), consider it already processed
        if ($current_transaction->status_tag === $status_tag) {
            $updated_timestamp = strtotime($current_transaction->updated_at);
            $now_timestamp = current_time('timestamp');
            
            if (($now_timestamp - $updated_timestamp) < 300) { // 5 minutes
                return true;
            }
        }

        return false;
    }

    /**
     * Mark webhook as processed
     */
    private function mark_webhook_processed($payment_id, $request_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spaceremit_transactions';
        
        // Store webhook processing metadata
        $wpdb->update(
            $table_name,
            array(
                'gateway_response' => maybe_serialize(array(
                    'last_webhook' => current_time('mysql'),
                    'webhook_data' => $request_data
                ))
            ),
            array('spaceremit_payment_id' => $payment_id),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Process payment response and redirect appropriately
     */
    private function process_payment_response($order, $payment_details, $payment_code) {
        global $wpdb;

        $order_id             = $order->get_id();
        $spaceremit_payment_id= $payment_details['id'];
        $status_tag           = $payment_details['status_tag'];

        // Ensure a transaction row exists
        $table_name = $wpdb->prefix . 'spaceremit_transactions';
        $existing   = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table_name WHERE spaceremit_payment_id = %s",
            $spaceremit_payment_id
        ));

        if (!$existing) {
            $wpdb->insert(
                $table_name,
                array(
                    'order_id'              => $order_id,
                    'spaceremit_payment_id' => $spaceremit_payment_id,
                    'amount'                => isset($payment_details['original_amount']) ? (float) $payment_details['original_amount'] : (float) $order->get_total(),
                    'currency'              => isset($payment_details['currency']) ? $payment_details['currency'] : $order->get_currency(),
                    'status'                => $this->map_status_tag_to_wc_status($status_tag),
                    'status_tag'            => $status_tag,
                    'customer_email'        => $order->get_billing_email(),
                    'customer_name'         => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'payment_method'        => isset($payment_details['payment_method']) ? $payment_details['payment_method'] : 'spaceremit',
                    'created_at'            => current_time('mysql'),
                    'updated_at'            => current_time('mysql'),
                ),
                array('%d','%s','%f','%s','%s','%s','%s','%s','%s','%s','%s')
            );

            SpaceRemit_Logger::info('Transaction record created', array(
                'order_id' => $order_id,
                'payment_id' => $spaceremit_payment_id,
                'status_tag' => $status_tag
            ));
        }

        // Update WooCommerce order status
        $this->update_order_status($order, $status_tag, $payment_details);

        // Decide redirect target
        if (in_array($status_tag, array('A','T'), true)) {
            $redirect_url = $order->get_checkout_order_received_url();
        } elseif (in_array($status_tag, array('F','C','E'), true)) {
            $redirect_url = $order->get_cancel_order_url();
        } else {
            $redirect_url = $order->get_checkout_payment_url();
        }

        $order->add_order_note(sprintf(
            __('SpaceRemit payment processed. Status: %s. Redirecting to: %s', 'spaceremit-woocommerce'),
            $status_tag,
            $redirect_url
        ));

        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Update payment status from webhook/verification
     * This is the critical method that synchronizes status from SpaceRemit
     */
    private function update_payment_status($order, $payment_details) {
        global $wpdb;

        $table_name            = $wpdb->prefix . 'spaceremit_transactions';
        $spaceremit_payment_id = $payment_details['id'];
        $status_tag            = $payment_details['status_tag'];

        $previous_status = $order->get_status();

        SpaceRemit_Logger::info('Updating payment status', array(
            'payment_id' => $spaceremit_payment_id,
            'order_id' => $order->get_id(),
            'previous_order_status' => $previous_status,
            'new_status_tag' => $status_tag
        ));

        // Update transaction table
        $updated = $wpdb->update(
            $table_name,
            array(
                'status'     => $this->map_status_tag_to_wc_status($status_tag),
                'status_tag' => $status_tag,
                'updated_at' => current_time('mysql'),
            ),
            array('spaceremit_payment_id' => $spaceremit_payment_id),
            array('%s','%s','%s'),
            array('%s')
        );

        if ($updated === false) {
            SpaceRemit_Logger::error('Failed to update transaction table', array(
                'payment_id' => $spaceremit_payment_id,
                'wpdb_error' => $wpdb->last_error
            ));
        }

        // Update WooCommerce order status
        $this->update_order_status($order, $status_tag, $payment_details);

        SpaceRemit_Logger::info('Payment status updated successfully', array(
            'payment_id' => $spaceremit_payment_id,
            'order_id' => $order->get_id(),
            'previous_status' => $previous_status,
            'new_status' => $order->get_status()
        ));
    }

    /**
     * Apply WooCommerce order status changes
     * Enhanced with better logging and status handling
     */
    private function update_order_status($order, $status_tag, $payment_details) {
        $api          = new SpaceRemit_API();
        $status_label = $api->get_status_label($status_tag);
        $order_id     = $order->get_id();

        SpaceRemit_Logger::debug('Applying order status change', array(
            'order_id' => $order_id,
            'status_tag' => $status_tag,
            'status_label' => $status_label,
            'current_status' => $order->get_status()
        ));

        switch ($status_tag) {
            case 'A': // Completed
            case 'T': // Test
                if (!$order->is_paid()) {
                    $order->payment_complete($payment_details['id']);
                    $order->add_order_note(sprintf(
                        __('SpaceRemit payment completed (%s). Payment ID: %s', 'spaceremit-woocommerce'),
                        $status_label,
                        $payment_details['id']
                    ));
                    SpaceRemit_Logger::info('Order marked as paid', array(
                        'order_id' => $order_id,
                        'payment_id' => $payment_details['id']
                    ));
                }
                break;

            case 'B': // Pending
                if ($order->get_status() !== 'on-hold') {
                    $order->update_status('on-hold', sprintf(
                        __('SpaceRemit payment pending (%s). Payment ID: %s', 'spaceremit-woocommerce'),
                        $status_label,
                        $payment_details['id']
                    ));
                }
                break;

            case 'D': // Processing
                if ($order->get_status() !== 'processing' && !$order->is_paid()) {
                    $order->update_status('processing', sprintf(
                        __('SpaceRemit payment processing (%s). Payment ID: %s', 'spaceremit-woocommerce'),
                        $status_label,
                        $payment_details['id']
                    ));
                }
                break;

            case 'F': // Failed
                if ($order->get_status() !== 'failed') {
                    $order->update_status('failed', sprintf(
                        __('SpaceRemit payment failed (%s). Payment ID: %s', 'spaceremit-woocommerce'),
                        $status_label,
                        $payment_details['id']
                    ));
                }
                break;

            case 'E': // Expired
            case 'C': // Refused/Cancelled
                if ($order->get_status() !== 'cancelled') {
                    $order->update_status('cancelled', sprintf(
                        __('SpaceRemit payment cancelled/expired (%s). Payment ID: %s', 'spaceremit-woocommerce'),
                        $status_label,
                        $payment_details['id']
                    ));
                }
                break;

            default:
                SpaceRemit_Logger::warning('Unknown status tag received', array(
                    'order_id' => $order_id,
                    'status_tag' => $status_tag
                ));
                break;
        }
    }

    /**
     * Map SpaceRemit status tag to WooCommerce status
     */
    private function map_status_tag_to_wc_status($status_tag) {
        $mapping = array(
            'A' => 'completed',
            'B' => 'pending',
            'C' => 'cancelled',
            'D' => 'processing',
            'E' => 'cancelled',
            'F' => 'failed',
            'T' => 'completed',
        );

        return isset($mapping[$status_tag]) ? $mapping[$status_tag] : 'pending';
    }

    /**
     * Resolve order by SpaceRemit payment ID from custom table
     */
    private function get_order_by_spaceremit_id($payment_id) {
        global $wpdb;

        $table_name  = $wpdb->prefix . 'spaceremit_transactions';
        $transaction = $wpdb->get_row($wpdb->prepare(
            "SELECT order_id FROM $table_name WHERE spaceremit_payment_id = %s",
            $payment_id
        ));

        if ($transaction) {
            return wc_get_order($transaction->order_id);
        }

        return false;
    }
}
