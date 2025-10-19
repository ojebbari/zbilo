<?php
/**
 * Plugin Name: SpaceRemit WooCommerce Payment Gateway Jeb
 * Plugin URI: https://spaceremit.com/
 * Description: Accept payments via SpaceRemit payment gateway with WooCommerce HPOS compatibility and Checkout Blocks support.
 * Version: 1.0.5
 * Author: SpaceRemit
 * Author URI: https://spaceremit.com/
 * Text Domain: spaceremit-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 5.0
 * WC tested up to: 8.2
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SPACEREMIT_WC_VERSION', '1.0.5');
define('SPACEREMIT_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SPACEREMIT_WC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SPACEREMIT_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main SpaceRemit WooCommerce class
 */
class SpaceRemit_WooCommerce {

    /**
     * Single instance of the class
     */
    protected static $_instance = null;

    /**
     * Main SpaceRemit_WooCommerce Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'init_callback_handler'));
        add_action('woocommerce_blocks_loaded', array($this, 'init_blocks_support'));
        add_filter('plugin_action_links_' . SPACEREMIT_WC_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('SpaceRemit_WooCommerce', 'uninstall'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Check minimum WooCommerce version
        if (version_compare(WC_VERSION, '5.0', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
            return;
        }

        // Load plugin files
        $this->includes();

        // Initialize payment gateway
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway_class'));

        // HPOS and Blocks compatibility
        add_action('before_woocommerce_init', array($this, 'declare_compatibility'));

        // Load admin features
        if (is_admin()) {
            new SpaceRemit_Admin();
        }

        // Load text domain
        add_action('init', array($this, 'load_textdomain'));

        // Add AJAX handlers
        add_action('wp_ajax_spaceremit_verify_keys', array($this, 'ajax_verify_keys'));
        add_action('wp_ajax_spaceremit_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * Initialize WooCommerce Blocks support
     */
    public function init_blocks_support() {
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            require_once SPACEREMIT_WC_PLUGIN_PATH . 'includes/class-wc-gateway-spaceremit-blocks.php';
            
            add_action(
                'woocommerce_blocks_payment_method_type_registration',
                array($this, 'register_payment_method_type')
            );
            
            // Simplified blocks assets - no external SpaceRemit library
            add_action('wp_enqueue_scripts', array($this, 'enqueue_blocks_assets'));
        }
    }

    /**
     * Register payment method type for blocks
     */
    public function register_payment_method_type($payment_method_registry) {
        $payment_method_registry->register(new WC_Gateway_SpaceRemit_Blocks());
    }

    /**
     * Enqueue blocks assets - updated with new key structure
     */
    public function enqueue_blocks_assets() {
        // Only load on checkout page and if not admin
        if (is_admin() || !is_checkout()) {
            return;
        }

        $gateway_settings = get_option('woocommerce_spaceremit_settings', array());
        
        if (empty($gateway_settings['enabled']) || 'yes' !== $gateway_settings['enabled']) {
            return;
        }

        // Determine which keys to use based on test mode
        $testmode = isset($gateway_settings['testmode']) && 'yes' === $gateway_settings['testmode'];
        
        if ($testmode) {
            $public_key = $gateway_settings['test_public_key'] ?? '';
        } else {
            $public_key = $gateway_settings['live_public_key'] ?? '';
        }
        
        // Fallback to old key format for backward compatibility
        if (empty($public_key)) {
            $public_key = $gateway_settings['public_key'] ?? '';
        }

        // Don't load if no public key is set
        if (empty($public_key)) {
            return;
        }

        // Get the blocks script asset info
        $script_asset_path = SPACEREMIT_WC_PLUGIN_PATH . 'assets/js/spaceremit-blocks.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array(
                    'wc-blocks-registry',
                    'wc-settings',
                    'wp-element',
                    'wp-i18n',
                    'wp-html-entities'
                ),
                'version' => SPACEREMIT_WC_VERSION
            );

        // Enqueue blocks script WITHOUT external SpaceRemit library
        wp_enqueue_script(
            'wc-spaceremit-blocks',
            SPACEREMIT_WC_PLUGIN_URL . 'assets/js/spaceremit-blocks.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        // Localize script with settings data
        wp_localize_script('wc-spaceremit-blocks', 'wc_spaceremit_blocks_params', array(
            'title' => $gateway_settings['title'] ?? __('SpaceRemit', 'spaceremit-woocommerce'),
            'description' => $gateway_settings['description'] ?? __('Pay securely using SpaceRemit payment gateway.', 'spaceremit-woocommerce'),
            'logo_url' => SPACEREMIT_WC_PLUGIN_URL . 'assets/images/spaceremit-logo.png',
            'public_key' => $public_key,
            'testmode' => $testmode,
            'enable_local_methods' => isset($gateway_settings['enable_local_methods']) && 'yes' === $gateway_settings['enable_local_methods'],
            'enable_card_payment' => isset($gateway_settings['enable_card_payment']) && 'yes' === $gateway_settings['enable_card_payment'],
            'supports' => array('products')
        ));

        // Set script translations
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'wc-spaceremit-blocks',
                'spaceremit-woocommerce',
                SPACEREMIT_WC_PLUGIN_PATH . 'languages'
            );
        }
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once SPACEREMIT_WC_PLUGIN_PATH . 'includes/class-spaceremit-api.php';
        require_once SPACEREMIT_WC_PLUGIN_PATH . 'includes/class-wc-gateway-spaceremit.php';
        require_once SPACEREMIT_WC_PLUGIN_PATH . 'includes/class-spaceremit-callback.php';
        require_once SPACEREMIT_WC_PLUGIN_PATH . 'includes/class-spaceremit-logger.php';
        
        if (is_admin()) {
            require_once SPACEREMIT_WC_PLUGIN_PATH . 'includes/class-spaceremit-admin.php';
        }
    }

    /**
     * Add SpaceRemit gateway to WooCommerce
     */
    public function add_gateway_class($gateways) {
        $gateways[] = 'WC_Gateway_SpaceRemit';
        return $gateways;
    }

    /**
     * Declare HPOS and Blocks compatibility
     */
    public function declare_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            // HPOS compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                __FILE__,
                true
            );
            
            // Cart and checkout blocks compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                __FILE__,
                true
            );
        }
    }

    /**
     * Initialize callback handler
     */
    public function init_callback_handler() {
        new SpaceRemit_Callback();
    }

    /**
     * Add plugin action links
     */
    public function plugin_action_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=spaceremit') . '">' . __('Settings', 'spaceremit-woocommerce') . '</a>',
            '<a href="' . admin_url('admin.php?page=spaceremit-transactions') . '">' . __('Transactions', 'spaceremit-woocommerce') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Check requirements
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(SPACEREMIT_WC_PLUGIN_BASENAME);
            wp_die(__('SpaceRemit WooCommerce Payment Gateway requires WooCommerce to be installed and active.', 'spaceremit-woocommerce'));
        }

        $this->create_tables();
        $this->create_log_directory();
        
        // Create callback endpoint
        add_rewrite_rule(
            '^spaceremit-callback/?$',
            'index.php?spaceremit_callback=1',
            'top'
        );
        flush_rewrite_rules();

        // Set default options
        $this->set_default_options();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Plugin uninstall
     */
    public static function uninstall() {
        global $wpdb;

        // Remove options
        delete_option('woocommerce_spaceremit_settings');
        delete_option('spaceremit_version');

        // Remove tables if user chooses to
        $remove_data = get_option('spaceremit_remove_data_on_uninstall', 'no');
        if ('yes' === $remove_data) {
            $table_name = $wpdb->prefix . 'spaceremit_transactions';
            $wpdb->query("DROP TABLE IF EXISTS $table_name");
        }
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'spaceremit_transactions';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            spaceremit_payment_id varchar(255) NOT NULL,
            amount decimal(10,2) NOT NULL,
            currency varchar(3) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            status_tag varchar(5) NOT NULL,
            customer_email varchar(100) NOT NULL,
            customer_name varchar(255) NOT NULL,
            payment_method varchar(50) NOT NULL,
            gateway_response longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY spaceremit_payment_id (spaceremit_payment_id),
            KEY order_id (order_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Update version
        update_option('spaceremit_version', SPACEREMIT_WC_VERSION);
    }

    /**
     * Create log directory
     */
    private function create_log_directory() {
        $log_dir = WP_CONTENT_DIR . '/uploads/spaceremit-logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Create .htaccess to protect log files
            $htaccess_content = "Order deny,allow\nDeny from all";
            file_put_contents($log_dir . '.htaccess', $htaccess_content);
        }
    }

    /**
     * Set default options - updated with new key structure
     */
    private function set_default_options() {
        $default_settings = array(
            'enabled' => 'no',
            'title' => __('SpaceRemit', 'spaceremit-woocommerce'),
            'description' => __('Pay securely using SpaceRemit payment gateway with local payment methods and card payments.', 'spaceremit-woocommerce'),
            'testmode' => 'yes',
            'enable_local_methods' => 'yes',
            'enable_card_payment' => 'yes',
            'live_public_key' => '',
            'live_secret_key' => '',
            'test_public_key' => '',
            'test_secret_key' => '',
            'debug' => 'no'
        );

        $existing_settings = get_option('woocommerce_spaceremit_settings', array());
        
        // Migrate old keys to new structure if they exist
        if (!empty($existing_settings['server_key']) && empty($existing_settings['live_secret_key'])) {
            $existing_settings['live_secret_key'] = $existing_settings['server_key'];
        }
        if (!empty($existing_settings['public_key']) && empty($existing_settings['live_public_key'])) {
            $existing_settings['live_public_key'] = $existing_settings['public_key'];
        }
        
        $settings = array_merge($default_settings, $existing_settings);
        update_option('woocommerce_spaceremit_settings', $settings);
    }

    /**
     * AJAX: Verify API keys - updated for new key structure
     */
    public function ajax_verify_keys() {
        check_ajax_referer('spaceremit_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Insufficient permissions.', 'spaceremit-woocommerce'));
        }

        $live_public_key = sanitize_text_field($_POST['live_public_key'] ?? '');
        $live_secret_key = sanitize_text_field($_POST['live_secret_key'] ?? '');
        $test_public_key = sanitize_text_field($_POST['test_public_key'] ?? '');
        $test_secret_key = sanitize_text_field($_POST['test_secret_key'] ?? '');

        $api = new SpaceRemit_API();
        $results = $api->verify_keys($live_public_key, $live_secret_key, $test_public_key, $test_secret_key);

        if (!empty($results)) {
            wp_send_json_success($results);
        } else {
            wp_send_json_error(__('No API keys provided for verification.', 'spaceremit-woocommerce'));
        }
    }

    /**
     * AJAX: Test connection - updated for new key structure
     */
    public function ajax_test_connection() {
        check_ajax_referer('spaceremit_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Insufficient permissions.', 'spaceremit-woocommerce'));
        }

        $test_mode = isset($_POST['test_mode']) && $_POST['test_mode'] === '1';
        
        $gateway = new WC_Gateway_SpaceRemit();
        
        if ($test_mode) {
            $api = new SpaceRemit_API($gateway->test_secret_key, $gateway->test_public_key, true);
        } else {
            $api = new SpaceRemit_API($gateway->live_secret_key, $gateway->live_public_key, false);
        }

        $result = $api->test_connection();

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>' . __('SpaceRemit WooCommerce Payment Gateway', 'spaceremit-woocommerce') . '</strong>: ' . sprintf(__('WooCommerce is required for this plugin to work. Please %sinstall and activate WooCommerce%s.', 'spaceremit-woocommerce'), '<a href="' . admin_url('plugin-install.php?s=woocommerce&tab=search&type=term') . '">', '</a>') . '</p></div>';
    }

    /**
     * WooCommerce version notice
     */
    public function woocommerce_version_notice() {
        echo '<div class="error"><p><strong>' . __('SpaceRemit WooCommerce Payment Gateway', 'spaceremit-woocommerce') . '</strong>: ' . sprintf(__('This plugin requires WooCommerce version 5.0 or higher. You are running version %s. Please update WooCommerce.', 'spaceremit-woocommerce'), WC_VERSION) . '</p></div>';
    }

    /**
     * Load text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('spaceremit-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
}

/**
 * Main instance of SpaceRemit_WooCommerce
 */
// Global: auto-complete any order on thank-you (all gateways)
if ( ! function_exists('spaceremit_auto_complete_all_gateways') ) {
    function spaceremit_auto_complete_all_gateways( $order_id ) {
        if ( ! $order_id ) { return; }
        $order = wc_get_order( $order_id );
        if ( ! $order ) { return; }
        if ( $order->is_paid() ) { return; } // optional guard
        $order->update_status( 'completed' );
    }
}
add_action('woocommerce_thankyou', 'spaceremit_auto_complete_all_gateways', 10, 1);


function SpaceRemit_WC() {
    return SpaceRemit_WooCommerce::instance();
}

// Initialize the plugin
SpaceRemit_WC();