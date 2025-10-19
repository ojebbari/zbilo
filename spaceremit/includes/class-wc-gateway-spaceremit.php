<?php
/**
 * SpaceRemit WooCommerce Payment Gateway Class
 * Updated version with separate Live and Test API keys and field sanitization
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_SpaceRemit extends WC_Payment_Gateway {

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'spaceremit';
        $this->icon = SPACEREMIT_WC_PLUGIN_URL . 'assets/images/spaceremit-logo.png';
        $this->has_fields = true;
        $this->method_title = __('SpaceRemit', 'spaceremit-woocommerce');
        $this->method_description = __('Accept payments via SpaceRemit payment gateway with support for local payment methods and card payments.', 'spaceremit-woocommerce');

        // Load the settings
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->testmode = 'yes' === $this->get_option('testmode');
        
        // Live API Keys
        $this->live_public_key = $this->get_option('live_public_key');
        $this->live_secret_key = $this->get_option('live_secret_key');
        
        // Test API Keys
        $this->test_public_key = $this->get_option('test_public_key');
        $this->test_secret_key = $this->get_option('test_secret_key');
        
        // Set current keys based on mode
        $this->public_key = $this->testmode ? $this->test_public_key : $this->live_public_key;
        $this->server_key = $this->testmode ? $this->test_secret_key : $this->live_secret_key;
        
        $this->enable_local_methods = 'yes' === $this->get_option('enable_local_methods');
        $this->enable_card_payment = 'yes' === $this->get_option('enable_card_payment');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        
        // HPOS and Blocks compatibility
        $this->supports = array(
            'products',
            'refunds'
        );
    }

    /**
     * Sanitize field value for SpaceRemit API
     * Remove spaces, symbols, and special characters - only allow alphanumeric
     */
    private function sanitize_spaceremit_field($value) {
        // Convert to string and remove any non-alphanumeric characters
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', (string) $value);
        
        // Ensure it's not empty, if empty use a default value
        if (empty($sanitized)) {
            $sanitized = 'ORDER' . time();
        }
        
        return $sanitized;
    }

    /**
     * Sanitize name field - allow letters and numbers only
     */
    private function sanitize_name_field($value) {
        // Remove special characters but keep basic letters and numbers
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '', (string) $value);
        
        // If empty after sanitization, use default
        if (empty($sanitized)) {
            $sanitized = 'Customer' . time();
        }
        
        return $sanitized;
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        if ('yes' !== $this->enabled) {
            return false;
        }

        if (empty($this->server_key) || empty($this->public_key)) {
            return false;
        }

        return parent::is_available();
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'spaceremit-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable SpaceRemit Payment', 'spaceremit-woocommerce'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'spaceremit-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title for the payment method the customer sees during checkout.', 'spaceremit-woocommerce'),
                'default' => __('SpaceRemit', 'spaceremit-woocommerce'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'spaceremit-woocommerce'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'spaceremit-woocommerce'),
                'default' => __('Pay securely using SpaceRemit payment gateway with local payment methods and card payments.', 'spaceremit-woocommerce'),
                'desc_tip' => true,
            ),
            'testmode' => array(
                'title' => __('Test mode', 'spaceremit-woocommerce'),
                'label' => __('Enable Test Mode', 'spaceremit-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'spaceremit-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'live_keys_section' => array(
                'title' => __('Live API Keys', 'spaceremit-woocommerce'),
                'type' => 'title',
                'description' => __('Your live SpaceRemit API keys for processing real payments. Get these from your SpaceRemit merchant account.', 'spaceremit-woocommerce'),
            ),
            'live_public_key' => array(
                'title' => __('Live Public Key', 'spaceremit-woocommerce'),
                'type' => 'text',
                'description' => __('Get your Live Public Key from your SpaceRemit merchant account.', 'spaceremit-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'live_secret_key' => array(
                'title' => __('Live Secret Key', 'spaceremit-woocommerce'),
                'type' => 'password',
                'description' => __('Get your Live Secret Key from your SpaceRemit merchant account.', 'spaceremit-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_keys_section' => array(
                'title' => __('Test API Keys', 'spaceremit-woocommerce'),
                'type' => 'title',
                'description' => __('Your test SpaceRemit API keys for testing payments. Get these from your SpaceRemit merchant account.', 'spaceremit-woocommerce'),
            ),
            'test_public_key' => array(
                'title' => __('Test Public Key', 'spaceremit-woocommerce'),
                'type' => 'text',
                'description' => __('Get your Test Public Key from your SpaceRemit merchant account.', 'spaceremit-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'test_secret_key' => array(
                'title' => __('Test Secret Key', 'spaceremit-woocommerce'),
                'type' => 'password',
                'description' => __('Get your Test Secret Key from your SpaceRemit merchant account.', 'spaceremit-woocommerce'),
                'default' => '',
                'desc_tip' => true,
            ),
            'payment_options_section' => array(
                'title' => __('Payment Options', 'spaceremit-woocommerce'),
                'type' => 'title',
                'description' => __('Configure which payment methods to enable for your customers.', 'spaceremit-woocommerce'),
            ),
            'enable_local_methods' => array(
                'title' => __('Local Payment Methods', 'spaceremit-woocommerce'),
                'label' => __('Enable Local Payment Methods', 'spaceremit-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Enable local payment methods (bank transfers, e-wallets, etc.).', 'spaceremit-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'enable_card_payment' => array(
                'title' => __('Card Payment', 'spaceremit-woocommerce'),
                'label' => __('Enable Card Payment', 'spaceremit-woocommerce'),
                'type' => 'checkbox',
                'description' => __('Enable credit/debit card payments.', 'spaceremit-woocommerce'),
                'default' => 'yes',
                'desc_tip' => true,
            ),
            'webhook_section' => array(
                'title' => __('Webhook Configuration', 'spaceremit-woocommerce'),
                'type' => 'title',
                'description' => sprintf(
                    __('Configure this webhook URL in your SpaceRemit merchant account: %s', 'spaceremit-woocommerce'),
                    '<code>' . home_url('/?spaceremit_callback=1') . '</code>'
                ),
            ),
        );
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        if ($this->testmode) {
            echo '<div class="spaceremit-test-mode-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px;">';
            echo '<strong>' . __('TEST MODE ENABLED', 'spaceremit-woocommerce') . '</strong> - ';
            echo __('This payment will be processed in test mode. No real money will be charged.', 'spaceremit-woocommerce');
            echo '</div>';
        }

        echo '<div id="spaceremit-payment-form">';
        echo '<p class="spaceremit-redirect-notice">' . __('You will be redirected to SpaceRemit to complete your payment securely.', 'spaceremit-woocommerce') . '</p>';
        echo '</div>';
    }

    /**
     * Process the payment and return the result
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            wc_add_notice(__('Order not found.', 'spaceremit-woocommerce'), 'error');
            return array('result' => 'fail');
        }

        // Store order data for payment processing
        $order->update_meta_data('_spaceremit_payment_pending', 'yes');
        $order->update_meta_data('_spaceremit_test_mode', $this->testmode ? 'yes' : 'no');
        $order->save();

        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }

    /**
     * Receipt page
     */
    public function receipt_page($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }

        echo '<div class="spaceremit-receipt-page">';
        echo '<h3>' . __('Complete your payment with SpaceRemit', 'spaceremit-woocommerce') . '</h3>';
        
        if ($this->testmode) {
            echo '<div class="spaceremit-test-mode-notice" style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 15px 0; border-radius: 4px;">';
            echo '<strong>' . __('TEST MODE ENABLED', 'spaceremit-woocommerce') . '</strong><br>';
            echo __('This payment will be processed in test mode using test API keys. No real money will be charged.', 'spaceremit-woocommerce');
            echo '</div>';
        }
        
        echo '<p>' . __('Please complete your payment below.', 'spaceremit-woocommerce') . '</p>';
        
        $this->generate_spaceremit_form($order);
        echo '</div>';
    }

    /**
     * Generate SpaceRemit payment form - Updated with field sanitization
     */
    public function generate_spaceremit_form($order) {
        $order_id = $order->get_id();
        $amount = $order->get_total();
        $currency = $order->get_currency();
        
        // Get customer details and sanitize them
        $customer_first_name = $order->get_billing_first_name();
        $customer_last_name = $order->get_billing_last_name();
        $customer_name = trim($customer_first_name . ' ' . $customer_last_name);
        $customer_email = $order->get_billing_email();
        $customer_phone = $order->get_billing_phone();

        // Sanitize all fields for SpaceRemit API requirements
        $sanitized_name = $this->sanitize_name_field($customer_name);
        $sanitized_email = $this->sanitize_spaceremit_field($customer_email);
        $sanitized_phone = $this->sanitize_spaceremit_field($customer_phone);
        $sanitized_notes = $this->sanitize_spaceremit_field('WooCommerceOrder' . $order_id);

        // Create callback
$callback_url = add_query_arg(
    array(
        'spaceremit_callback' => '1',
        'order_id'            => $order->get_id(),
        'key'                 => $order->get_order_key(),
    ),
    home_url('/')
);

// Keep these as-is (these are already correct)
$return_url = $this->get_return_url($order);
$cancel_url = $order->get_cancel_order_url();


        ?>
        <div id="spaceremit-payment-container">
            <div id="spaceremit-loading" style="text-align: center; padding: 20px;">
                <p><?php _e('Initializing SpaceRemit payment system...', 'spaceremit-woocommerce'); ?></p>
                <?php if ($this->testmode): ?>
                <p><small><?php _e('Using test API keys', 'spaceremit-woocommerce'); ?></small></p>
                <?php else: ?>
                <p><small><?php _e('Using live API keys', 'spaceremit-woocommerce'); ?></small></p>
                <?php endif; ?>
            </div>
            
            <div id="spaceremit-error" style="display: none;" class="notice notice-error">
                <p><?php _e('Unable to load payment system. Please try again.', 'spaceremit-woocommerce'); ?></p>
                <p>
                    <button type="button" onclick="location.reload();" class="button"><?php _e('Retry', 'spaceremit-woocommerce'); ?></button>
                    <a href="<?php echo esc_url($cancel_url); ?>" class="button"><?php _e('Cancel Order', 'spaceremit-woocommerce'); ?></a>
                </p>
            </div>

            <div id="spaceremit-content" style="display: none;">
                <!-- SpaceRemit Form with sanitized fields -->
                <form action="<?php echo esc_url($callback_url); ?>" method="POST" id="spaceremit-form" style="width: 400px; padding: 10px;">
                    <!-- Hidden fields - all sanitized for SpaceRemit API -->
                    <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
                    <input type="hidden" name="amount" value="<?php echo esc_attr($amount); ?>">
                    <input type="hidden" name="currency" value="<?php echo esc_attr($currency); ?>">
                    <input type="hidden" name="fullname" value="<?php echo esc_attr($sanitized_name); ?>">
                    <input type="hidden" name="email" value="<?php echo esc_attr($sanitized_email); ?>">
                    <input type="hidden" name="phone" value="<?php echo esc_attr($sanitized_phone); ?>">
                    <input type="hidden" name="notes" value="<?php echo esc_attr($sanitized_notes); ?>">
                    <input type="hidden" name="test_mode" value="<?php echo esc_attr($this->testmode ? '1' : '0'); ?>">
                    
                    <!-- Debug info for admin (hidden in production) -->
                    <?php if (current_user_can('manage_options') && $this->testmode): ?>
                    <div style="background: #f0f0f0; padding: 10px; margin-bottom: 10px; font-size: 12px; border-radius: 4px;">
                        <strong>Debug Info (Test Mode Only):</strong><br>
                        Original Name: <?php echo esc_html($customer_name); ?><br>
                        Sanitized Name: <?php echo esc_html($sanitized_name); ?><br>
                        Original Email: <?php echo esc_html($customer_email); ?><br>
                        Sanitized Email: <?php echo esc_html($sanitized_email); ?><br>
                        Original Notes: WooCommerce Order #<?php echo $order_id; ?><br>
                        Sanitized Notes: <?php echo esc_html($sanitized_notes); ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Local payment methods -->
                    <?php if ($this->enable_local_methods): ?>
                    <div class="sp-one-type-select">
                        <input type="radio" name="sp-pay-type-radio" value="local-methods-pay" id="sp_local_methods_radio" checked>
                        <label for="sp_local_methods_radio">
                            <div><?php _e('Local payment methods', 'spaceremit-woocommerce'); ?></div>
                        </label>
                        <div id="spaceremit-local-methods-pay"></div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Card payment -->
                    <?php if ($this->enable_card_payment): ?>
                    <div class="sp-one-type-select">
                        <input type="radio" name="sp-pay-type-radio" value="card-pay" id="sp_card_radio">
                        <label for="sp_card_radio">
                            <div><?php _e('Card payment', 'spaceremit-woocommerce'); ?></div>
                        </label>
                        <div id="spaceremit-card-pay"></div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Submit button -->
                    <div>
                        <button type="submit" id="spaceremit-pay-button" class="button alt">
                            <?php printf(__('Pay %s', 'spaceremit-woocommerce'), wc_price($amount)); ?>
                            <?php if ($this->testmode): ?>
                            <small>(<?php _e('Test Mode', 'spaceremit-woocommerce'); ?>)</small>
                            <?php endif; ?>
                        </button>
                        <a href="<?php echo esc_url($cancel_url); ?>" class="button" style="margin-left: 10px;">
                            <?php _e('Cancel', 'spaceremit-woocommerce'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <script>
        (function() {
            'use strict';
            
            // Prevent multiple initializations
            if (window.spaceremitPaymentInitialized) {
                console.log('SpaceRemit payment already initialized');
                return;
            }
            window.spaceremitPaymentInitialized = true;
            
            // Create isolated SpaceRemit configuration
            function initializeSpaceRemit() {
                // Clear any existing global variables to prevent conflicts
                try {
                    // Only clear if we're reinitializing
                    if (window.SP_PUBLIC_KEY && window.SP_PUBLIC_KEY !== "<?php echo esc_js($this->public_key); ?>") {
                        delete window.SP_PUBLIC_KEY;
                        delete window.SP_FORM_ID;
                        delete window.SP_SELECT_RADIO_NAME;
                        delete window.LOCAL_METHODS_BOX_STATUS;
                        delete window.LOCAL_METHODS_PARENT_ID;
                        delete window.CARD_BOX_STATUS;
                        delete window.CARD_BOX_PARENT_ID;
                        delete window.SP_FORM_AUTO_SUBMIT_WHEN_GET_CODE;
                        delete window.SP_SUCCESSFUL_PAYMENT;
                        delete window.SP_FAILD_PAYMENT;
                        delete window.SP_RECIVED_MESSAGE;
                        delete window.SP_NEED_AUTH;
                    }
                } catch(e) {
                    // Ignore errors when deleting
                }
                
                // Set up SpaceRemit configuration with appropriate keys
                window.SP_PUBLIC_KEY = "<?php echo esc_js($this->public_key); ?>";
                window.SP_FORM_ID = "#spaceremit-form";
                window.SP_SELECT_RADIO_NAME = "sp-pay-type-radio";
                window.LOCAL_METHODS_BOX_STATUS = <?php echo $this->enable_local_methods ? 'true' : 'false'; ?>;
                window.LOCAL_METHODS_PARENT_ID = "#spaceremit-local-methods-pay";
                window.CARD_BOX_STATUS = <?php echo $this->enable_card_payment ? 'true' : 'false'; ?>;
                window.CARD_BOX_PARENT_ID = "#spaceremit-card-pay";
                window.SP_FORM_AUTO_SUBMIT_WHEN_GET_CODE = true;

                // Callback functions
                window.SP_SUCCESSFUL_PAYMENT = function(spaceremit_code) {
                    console.log('Payment successful:', spaceremit_code);
                    
                    var form = document.getElementById('spaceremit-form');
                    if (form) {
                        var input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'SP_payment_code';
                        input.value = spaceremit_code;
                        form.appendChild(input);
                        form.submit();
                    }
                };

                window.SP_FAILD_PAYMENT = function() {
                    console.log('Payment failed');
                    alert('<?php _e('Payment failed. Please try again.', 'spaceremit-woocommerce'); ?>');
                    var btn = document.getElementById('spaceremit-pay-button');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<?php printf(__('Pay %s', 'spaceremit-woocommerce'), wc_price($amount)); ?><?php if ($this->testmode): ?> <small>(<?php _e('Test Mode', 'spaceremit-woocommerce'); ?>)</small><?php endif; ?>';
                    }
                };

                window.SP_RECIVED_MESSAGE = function(message) {
                    if (message) {
                        alert(message);
                    }
                };
                
                console.log('SpaceRemit configuration initialized', {
                    testMode: <?php echo $this->testmode ? 'true' : 'false'; ?>,
                    publicKey: "<?php echo esc_js(substr($this->public_key, 0, 10)); ?>...",
                    sanitizedFields: {
                        name: "<?php echo esc_js($sanitized_name); ?>",
                        email: "<?php echo esc_js($sanitized_email); ?>",
                        notes: "<?php echo esc_js($sanitized_notes); ?>"
                    }
                });
            }

            // Load SpaceRemit library with deduplication
            function loadSpaceRemitLibrary() {
                // Check if script is already loading or loaded
                var existingScript = document.querySelector('script[src*="spaceremit.js"]');
                if (existingScript) {
                    console.log('SpaceRemit script already exists, waiting for load...');
                    
                    // If script exists but library not loaded, wait for it
                    if (!window.spaceremit) {
                        var checkInterval = setInterval(function() {
                            if (window.spaceremit) {
                                clearInterval(checkInterval);
                                onLibraryLoaded();
                            }
                        }, 100);
                        
                        // Timeout after 10 seconds
                        setTimeout(function() {
                            clearInterval(checkInterval);
                            if (!window.spaceremit) {
                                console.error('SpaceRemit library failed to load');
                                showError();
                            }
                        }, 10000);
                    } else {
                        onLibraryLoaded();
                    }
                    return;
                }
                
                // Create new script
                var script = document.createElement('script');
                script.src = 'https://spaceremit.com/apiinfo-v2' + Date.now();
                script.setAttribute('data-spaceremit-script', 'true');
                
                script.onload = function() {
                    console.log('SpaceRemit library loaded');
                    onLibraryLoaded();
                };
                
                script.onerror = function() {
                    console.error('Failed to load SpaceRemit library');
                    showError();
                };
                
                document.head.appendChild(script);
            }
            
            function onLibraryLoaded() {
                document.getElementById('spaceremit-loading').style.display = 'none';
                document.getElementById('spaceremit-content').style.display = 'block';
                
                // Initialize SpaceRemit after a short delay to ensure DOM is ready
                setTimeout(function() {
                    if (window.spaceremit && typeof window.spaceremit.init === 'function') {
                        try {
                            window.spaceremit.init();
                            console.log('SpaceRemit initialized successfully');
                        } catch (error) {
                            console.error('SpaceRemit initialization error:', error);
                        }
                    }
                }, 100);
            }
            
            function showError() {
                document.getElementById('spaceremit-loading').style.display = 'none';
                document.getElementById('spaceremit-error').style.display = 'block';
            }

            // Initialize everything
            function init() {
                initializeSpaceRemit();
                
                setTimeout(function() {
                    loadSpaceRemitLibrary();
                }, 100);
                
                // Button click handler
                document.addEventListener('click', function(e) {
                    if (e.target && e.target.id === 'spaceremit-pay-button') {
                        e.target.disabled = true;
                        e.target.innerHTML = '<?php _e('Processing...', 'spaceremit-woocommerce'); ?>';
                    }
                });
            }

            // Start initialization when DOM is ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        })();
        </script>

        <style>
        #spaceremit-payment-container {
            max-width: 600px;
            margin: 20px 0;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: #f9f9f9;
        }
        
        .sp-one-type-select {
            margin: 15px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            background: #fff;
        }
        
        .sp-one-type-select label {
            font-weight: bold;
            margin-left: 10px;
            cursor: pointer;
        }
        
        .button {
            padding: 10px 20px;
            font-size: 14px;
            border-radius: 3px;
            text-decoration: none;
            display: inline-block;
            border: 1px solid #ccc;
            background: #f7f7f7;
            color: #333;
        }
        
        .button.alt {
            background: #0073aa;
            border-color: #0073aa;
            color: #fff;
        }
        
        .button:hover {
            background: #fafafa;
        }
        
        .button.alt:hover {
            background: #005a87;
        }
        
        .spaceremit-test-mode-notice {
            font-size: 14px;
        }
        </style>
        <?php
    }

    /**
     * Process refund
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('spaceremit_refund_error', __('Order not found.', 'spaceremit-woocommerce'));
        }

        $order->add_order_note(sprintf(__('SpaceRemit refund requested: %s. Reason: %s. Please process manually.', 'spaceremit-woocommerce'), wc_price($amount), $reason));
        
        return false;
    }

    /**
     * Validate fields
     */
    public function validate_fields() {
        return true;
    }

    /**
     * Admin options
     */
    public function admin_options() {
        ?>
        <h2><?php _e('SpaceRemit Payment Gateway', 'spaceremit-woocommerce'); ?></h2>
        <p><?php _e('SpaceRemit payment gateway allows you to accept payments via local payment methods and card payments.', 'spaceremit-woocommerce'); ?></p>
        
        <?php 
        $missing_keys = array();
        if (empty($this->live_public_key)) $missing_keys[] = 'Live Public Key';
        if (empty($this->live_secret_key)) $missing_keys[] = 'Live Secret Key';
        if (empty($this->test_public_key)) $missing_keys[] = 'Test Public Key';
        if (empty($this->test_secret_key)) $missing_keys[] = 'Test Secret Key';
        
        if (!empty($missing_keys)): ?>
        <div class="notice notice-warning">
            <p><?php printf(__('SpaceRemit payment gateway is not fully configured. Missing: %s', 'spaceremit-woocommerce'), implode(', ', $missing_keys)); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($this->testmode): ?>
        <div class="notice notice-info">
            <p><strong><?php _e('Test Mode is enabled.', 'spaceremit-woocommerce'); ?></strong> <?php _e('The gateway will use test API keys for processing payments.', 'spaceremit-woocommerce'); ?></p>
        </div>
        <?php else: ?>
        <div class="notice notice-success">
            <p><strong><?php _e('Live Mode is enabled.', 'spaceremit-woocommerce'); ?></strong> <?php _e('The gateway will use live API keys for processing real payments.', 'spaceremit-woocommerce'); ?></p>
        </div>
        <?php endif; ?>
        
        <div class="notice notice-info">
            <p><strong><?php _e('Field Sanitization:', 'spaceremit-woocommerce'); ?></strong> <?php _e('All form fields are automatically sanitized to contain only alphanumeric characters as required by SpaceRemit API.', 'spaceremit-woocommerce'); ?></p>
        </div>
        
        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>
        
        <script>
        jQuery(document).ready(function($) {
            // Show/hide key sections based on test mode
            function toggleKeySections() {
                var testMode = $('#woocommerce_spaceremit_testmode').is(':checked');
                var $liveSection = $('#woocommerce_spaceremit_live_keys_section').closest('tr');
                var $testSection = $('#woocommerce_spaceremit_test_keys_section').closest('tr');
                
                if (testMode) {
                    $testSection.show().nextUntil('tr:has(h3)').show();
                    $liveSection.show().nextUntil('tr:has(h3)').show();
                } else {
                    $testSection.show().nextUntil('tr:has(h3)').show();
                    $liveSection.show().nextUntil('tr:has(h3)').show();
                }
            }
            
            $('#woocommerce_spaceremit_testmode').change(toggleKeySections);
            toggleKeySections();
        });
        </script>
        <?php
    }
}