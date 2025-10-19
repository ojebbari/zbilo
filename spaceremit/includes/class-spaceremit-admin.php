<?php
/**
 * SpaceRemit Admin Dashboard
 * Updated to support separate Live and Test API keys
 */

if (!defined('ABSPATH')) {
    exit;
}

class SpaceRemit_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_spaceremit_get_transactions', array($this, 'ajax_get_transactions'));
        add_action('wp_ajax_spaceremit_sync_payment', array($this, 'ajax_sync_payment'));
        add_action('wp_ajax_spaceremit_verify_keys', array($this, 'ajax_verify_keys'));
        add_action('wp_ajax_spaceremit_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('SpaceRemit Transactions', 'spaceremit-woocommerce'),
            __('SpaceRemit Transactions', 'spaceremit-woocommerce'),
            'manage_woocommerce',
            'spaceremit-transactions',
            array($this, 'admin_page')
        );
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_spaceremit-transactions' && 
            $hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        wp_enqueue_style('spaceremit-admin', SPACEREMIT_WC_PLUGIN_URL . 'assets/css/spaceremit-admin.css', array(), SPACEREMIT_WC_VERSION);
        wp_enqueue_script('spaceremit-admin', SPACEREMIT_WC_PLUGIN_URL . 'assets/js/spaceremit-admin.js', array('jquery'), SPACEREMIT_WC_VERSION, true);
        
        wp_localize_script('spaceremit-admin', 'spaceremit_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spaceremit_admin_nonce'),
            'strings' => array(
                'confirm_sync' => __('Are you sure you want to sync this payment?', 'spaceremit-woocommerce'),
                'sync_success' => __('Payment synced successfully.', 'spaceremit-woocommerce'),
                'sync_error' => __('Error syncing payment.', 'spaceremit-woocommerce'),
                'verifying_keys' => __('Verifying API keys...', 'spaceremit-woocommerce'),
                'keys_verified' => __('API keys verified successfully.', 'spaceremit-woocommerce'),
                'keys_error' => __('Error verifying API keys.', 'spaceremit-woocommerce'),
                'testing_connection' => __('Testing connection...', 'spaceremit-woocommerce'),
                'connection_success' => __('Connection test successful.', 'spaceremit-woocommerce'),
                'connection_error' => __('Connection test failed.', 'spaceremit-woocommerce')
            )
        ));
    }

    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('SpaceRemit Transactions', 'spaceremit-woocommerce'); ?></h1>
            
            <div class="spaceremit-admin-header">
                <div class="spaceremit-stats">
                    <?php $this->display_stats(); ?>
                </div>
                
                <div class="spaceremit-api-status">
                    <?php $this->display_api_status(); ?>
                </div>
                
                <div class="spaceremit-filters">
                    <select id="status-filter">
                        <option value=""><?php _e('All Statuses', 'spaceremit-woocommerce'); ?></option>
                        <option value="completed"><?php _e('Completed', 'spaceremit-woocommerce'); ?></option>
                        <option value="pending"><?php _e('Pending', 'spaceremit-woocommerce'); ?></option>
                        <option value="processing"><?php _e('Processing', 'spaceremit-woocommerce'); ?></option>
                        <option value="failed"><?php _e('Failed', 'spaceremit-woocommerce'); ?></option>
                        <option value="cancelled"><?php _e('Cancelled', 'spaceremit-woocommerce'); ?></option>
                    </select>
                    
                    <input type="date" id="date-from" placeholder="<?php _e('From Date', 'spaceremit-woocommerce'); ?>">
                    <input type="date" id="date-to" placeholder="<?php _e('To Date', 'spaceremit-woocommerce'); ?>">
                    
                    <button type="button" id="filter-transactions" class="button">
                        <?php _e('Filter', 'spaceremit-woocommerce'); ?>
                    </button>
                    
                    <button type="button" id="export-transactions" class="button">
                        <?php _e('Export CSV', 'spaceremit-woocommerce'); ?>
                    </button>
                </div>
            </div>

            <div id="spaceremit-transactions-table">
                <?php $this->display_transactions_table(); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Display API status
     */
    private function display_api_status() {
        $gateway = new WC_Gateway_SpaceRemit();
        $testmode = 'yes' === $gateway->get_option('testmode');
        
        ?>
        <div class="spaceremit-api-status-box">
            <h3><?php _e('API Status', 'spaceremit-woocommerce'); ?></h3>
            
            <div class="api-mode-indicator">
                <?php if ($testmode): ?>
                <span class="mode-badge test-mode"><?php _e('TEST MODE', 'spaceremit-woocommerce'); ?></span>
                <?php else: ?>
                <span class="mode-badge live-mode"><?php _e('LIVE MODE', 'spaceremit-woocommerce'); ?></span>
                <?php endif; ?>
            </div>
            
            <div class="api-keys-status">
                <h4><?php _e('Live Keys', 'spaceremit-woocommerce'); ?></h4>
                <p>
                    <?php _e('Public Key:', 'spaceremit-woocommerce'); ?> 
                    <span class="key-status <?php echo !empty($gateway->live_public_key) ? 'configured' : 'missing'; ?>">
                        <?php echo !empty($gateway->live_public_key) ? __('Configured', 'spaceremit-woocommerce') : __('Missing', 'spaceremit-woocommerce'); ?>
                    </span>
                </p>
                <p>
                    <?php _e('Secret Key:', 'spaceremit-woocommerce'); ?> 
                    <span class="key-status <?php echo !empty($gateway->live_secret_key) ? 'configured' : 'missing'; ?>">
                        <?php echo !empty($gateway->live_secret_key) ? __('Configured', 'spaceremit-woocommerce') : __('Missing', 'spaceremit-woocommerce'); ?>
                    </span>
                </p>
                
                <h4><?php _e('Test Keys', 'spaceremit-woocommerce'); ?></h4>
                <p>
                    <?php _e('Public Key:', 'spaceremit-woocommerce'); ?> 
                    <span class="key-status <?php echo !empty($gateway->test_public_key) ? 'configured' : 'missing'; ?>">
                        <?php echo !empty($gateway->test_public_key) ? __('Configured', 'spaceremit-woocommerce') : __('Missing', 'spaceremit-woocommerce'); ?>
                    </span>
                </p>
                <p>
                    <?php _e('Secret Key:', 'spaceremit-woocommerce'); ?> 
                    <span class="key-status <?php echo !empty($gateway->test_secret_key) ? 'configured' : 'missing'; ?>">
                        <?php echo !empty($gateway->test_secret_key) ? __('Configured', 'spaceremit-woocommerce') : __('Missing', 'spaceremit-woocommerce'); ?>
                    </span>
                </p>
            </div>
            
            <div class="api-actions">
                <button type="button" id="test-live-connection" class="button button-secondary" 
                        <?php echo (empty($gateway->live_public_key) || empty($gateway->live_secret_key)) ? 'disabled' : ''; ?>>
                    <?php _e('Test Live Connection', 'spaceremit-woocommerce'); ?>
                </button>
                
                <button type="button" id="test-test-connection" class="button button-secondary"
                        <?php echo (empty($gateway->test_public_key) || empty($gateway->test_secret_key)) ? 'disabled' : ''; ?>>
                    <?php _e('Test Test Connection', 'spaceremit-woocommerce'); ?>
                </button>
            </div>
        </div>
        
        <style>
        .spaceremit-api-status-box {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .mode-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .mode-badge.test-mode {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .mode-badge.live-mode {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .key-status.configured {
            color: #155724;
            font-weight: bold;
        }
        
        .key-status.missing {
            color: #721c24;
            font-weight: bold;
        }
        
        .api-actions {
            margin-top: 10px;
        }
        
        .api-actions button {
            margin-right: 10px;
        }
        </style>
        <?php
    }

    /**
     * Display statistics
     */
    private function display_stats() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'spaceremit_transactions';
        
        // Get stats for last 30 days
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_completed_amount,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count
            FROM $table_name 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");

        ?>
        <div class="spaceremit-stat-box">
            <h3><?php echo number_format($stats->total_transactions); ?></h3>
            <p><?php _e('Total Transactions (30 days)', 'spaceremit-woocommerce'); ?></p>
        </div>
        
        <div class="spaceremit-stat-box">
            <h3><?php echo wc_price($stats->total_completed_amount); ?></h3>
            <p><?php _e('Completed Amount (30 days)', 'spaceremit-woocommerce'); ?></p>
        </div>
        
        <div class="spaceremit-stat-box">
            <h3><?php echo number_format($stats->completed_count); ?></h3>
            <p><?php _e('Completed', 'spaceremit-woocommerce'); ?></p>
        </div>
        
        <div class="spaceremit-stat-box">
            <h3><?php echo number_format($stats->pending_count); ?></h3>
            <p><?php _e('Pending', 'spaceremit-woocommerce'); ?></p>
        </div>
        
        <div class="spaceremit-stat-box">
            <h3><?php echo number_format($stats->failed_count); ?></h3>
            <p><?php _e('Failed', 'spaceremit-woocommerce'); ?></p>
        </div>
        <?php
    }

    /**
     * Display transactions table
     */
    private function display_transactions_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'spaceremit_transactions';
        $per_page = 20;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;

        $transactions = $wpdb->get_results($wpdb->prepare("
            SELECT t.*, o.post_status as order_status 
            FROM $table_name t
            LEFT JOIN {$wpdb->posts} o ON t.order_id = o.ID
            ORDER BY t.created_at DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        $total_transactions = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $total_pages = ceil($total_transactions / $per_page);

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('ID', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Order', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('SpaceRemit Payment ID', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Customer', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Amount', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Status', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Payment Method', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Mode', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Date', 'spaceremit-woocommerce'); ?></th>
                    <th><?php _e('Actions', 'spaceremit-woocommerce'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="10"><?php _e('No transactions found.', 'spaceremit-woocommerce'); ?></td>
                </tr>
                <?php else: ?>
                <?php foreach ($transactions as $transaction): ?>
                <?php 
                $order = wc_get_order($transaction->order_id);
                $test_mode = $order ? $order->get_meta('_spaceremit_test_mode') : '';
                ?>
                <tr>
                    <td><?php echo esc_html($transaction->id); ?></td>
                    <td>
                        <a href="<?php echo admin_url('post.php?post=' . $transaction->order_id . '&action=edit'); ?>">
                            #<?php echo esc_html($transaction->order_id); ?>
                        </a>
                    </td>
                    <td>
                        <code><?php echo esc_html($transaction->spaceremit_payment_id); ?></code>
                    </td>
                    <td>
                        <?php echo esc_html($transaction->customer_name); ?><br>
                        <small><?php echo esc_html($transaction->customer_email); ?></small>
                    </td>
                    <td>
                        <?php echo wc_price($transaction->amount, array('currency' => $transaction->currency)); ?>
                    </td>
                    <td>
                        <span class="spaceremit-status spaceremit-status-<?php echo esc_attr($transaction->status); ?>">
                            <?php echo esc_html(ucfirst($transaction->status)); ?>
                            <small>(<?php echo esc_html($transaction->status_tag); ?>)</small>
                        </span>
                    </td>
                    <td><?php echo esc_html($transaction->payment_method); ?></td>
                    <td>
                        <?php if ($test_mode === 'yes'): ?>
                        <span class="mode-badge test-mode"><?php _e('TEST', 'spaceremit-woocommerce'); ?></span>
                        <?php else: ?>
                        <span class="mode-badge live-mode"><?php _e('LIVE', 'spaceremit-woocommerce'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($transaction->created_at))); ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small sync-payment" 
                                data-payment-id="<?php echo esc_attr($transaction->spaceremit_payment_id); ?>"
                                data-transaction-id="<?php echo esc_attr($transaction->id); ?>"
                                data-test-mode="<?php echo esc_attr($test_mode === 'yes' ? '1' : '0'); ?>">
                            <?php _e('Sync', 'spaceremit-woocommerce'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $page
                ));
                ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    /**
     * AJAX: Get transactions
     */
    public function ajax_get_transactions() {
        check_ajax_referer('spaceremit_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', 'spaceremit-woocommerce'));
        }

        // Get filter parameters
        $status = sanitize_text_field($_POST['status'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $date_to = sanitize_text_field($_POST['date_to'] ?? '');

        global $wpdb;
        $table_name = $wpdb->prefix . 'spaceremit_transactions';
        
        $where_conditions = array('1=1');
        $where_values = array();

        if (!empty($status)) {
            $where_conditions[] = 'status = %s';
            $where_values[] = $status;
        }

        if (!empty($date_from)) {
            $where_conditions[] = 'DATE(created_at) >= %s';
            $where_values[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_conditions[] = 'DATE(created_at) <= %s';
            $where_values[] = $date_to;
        }

        $where_clause = implode(' AND ', $where_conditions);
        
        $query = "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC LIMIT 100";
        
        if (!empty($where_values)) {
            $transactions = $wpdb->get_results($wpdb->prepare($query, $where_values));
        } else {
            $transactions = $wpdb->get_results($query);
        }

        wp_send_json_success($transactions);
    }

    /**
     * AJAX: Sync payment status
     */
    public function ajax_sync_payment() {
        check_ajax_referer('spaceremit_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions.', 'spaceremit-woocommerce'));
        }

        $payment_id = sanitize_text_field($_POST['payment_id']);
        $transaction_id = intval($_POST['transaction_id']);
        $test_mode = isset($_POST['test_mode']) && $_POST['test_mode'] === '1';

        if (empty($payment_id) || empty($transaction_id)) {
            wp_send_json_error(__('Invalid payment data.', 'spaceremit-woocommerce'));
        }

        // Get gateway settings and create API instance with appropriate keys
        $gateway = new WC_Gateway_SpaceRemit();
        
        if ($test_mode) {
            $api = new SpaceRemit_API($gateway->test_secret_key, $gateway->test_public_key, true);
        } else {
            $api = new SpaceRemit_API($gateway->live_secret_key, $gateway->live_public_key, false);
        }

        // Check payment status
        $response = $api->check_payment($payment_id, array());
        
        if ($response) {
            $payment_details = $api->data_return;
            
            // Update transaction in database
            global $wpdb;
            $table_name = $wpdb->prefix . 'spaceremit_transactions';
            
            $updated = $wpdb->update(
                $table_name,
                array(
                    'status' => $this->map_status_tag_to_wc_status($payment_details['status_tag']),
                    'status_tag' => $payment_details['status_tag'],
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $transaction_id),
                array('%s', '%s', '%s'),
                array('%d')
            );

            if ($updated !== false) {
                wp_send_json_success(array(
                    'status' => $payment_details['status_tag'],
                    'status_label' => $api->get_status_label($payment_details['status_tag'])
                ));
            } else {
                wp_send_json_error(__('Failed to update transaction.', 'spaceremit-woocommerce'));
            }
        } else {
            wp_send_json_error($api->data_return['message'] ?? __('Payment verification failed.', 'spaceremit-woocommerce'));
        }
    }

    /**
     * AJAX: Verify API keys
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

        wp_send_json_success($results);
    }

    /**
     * AJAX: Test connection
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
     * Map status tag to WC status
     */
    private function map_status_tag_to_wc_status($status_tag) {
        $mapping = array(
            'A' => 'completed',
            'B' => 'pending',
            'C' => 'cancelled',
            'D' => 'processing',
            'E' => 'cancelled',
            'F' => 'failed',
            'T' => 'completed'
        );

        return isset($mapping[$status_tag]) ? $mapping[$status_tag] : 'pending';
    }
}