<?php
/**
 * SpaceRemit Logger Class
 * Handles logging for debugging and monitoring
 */

if (!defined('ABSPATH')) {
    exit;
}

class SpaceRemit_Logger {
    
    /**
     * Log levels
     */
    const LEVEL_ERROR = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_INFO = 'info';
    const LEVEL_DEBUG = 'debug';
    
    /**
     * Log directory
     */
    private static $log_dir;
    
    /**
     * Initialize logger
     */
    public static function init() {
        self::$log_dir = WP_CONTENT_DIR . '/uploads/spaceremit-logs/';
        
        if (!file_exists(self::$log_dir)) {
            wp_mkdir_p(self::$log_dir);
        }
    }
    
    /**
     * Log a message
     */
    public static function log($message, $level = self::LEVEL_INFO, $context = array()) {
        // Check if logging is enabled
        $gateway_settings = get_option('woocommerce_spaceremit_settings', array());
        if (empty($gateway_settings['debug']) || 'yes' !== $gateway_settings['debug']) {
            return;
        }
        
        self::init();
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = array(
            'timestamp' => $timestamp,
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'CLI'
        );
        
        $log_line = sprintf(
            "[%s] %s: %s %s\n",
            $log_entry['timestamp'],
            $log_entry['level'],
            $log_entry['message'],
            !empty($log_entry['context']) ? json_encode($log_entry['context']) : ''
        );
        
        $log_file = self::$log_dir . 'spaceremit-' . date('Y-m-d') . '.log';
        
        // Rotate logs if file is too large (> 10MB)
        if (file_exists($log_file) && filesize($log_file) > 10 * 1024 * 1024) {
            self::rotate_logs($log_file);
        }
        
        error_log($log_line, 3, $log_file);
        
        // Also log to WooCommerce logger if available
        if (function_exists('wc_get_logger')) {
            $wc_logger = wc_get_logger();
            $wc_logger->log($level, $message, array('source' => 'spaceremit'));
        }
    }
    
    /**
     * Log error
     */
    public static function error($message, $context = array()) {
        self::log($message, self::LEVEL_ERROR, $context);
    }
    
    /**
     * Log warning
     */
    public static function warning($message, $context = array()) {
        self::log($message, self::LEVEL_WARNING, $context);
    }
    
    /**
     * Log info
     */
    public static function info($message, $context = array()) {
        self::log($message, self::LEVEL_INFO, $context);
    }
    
    /**
     * Log debug
     */
    public static function debug($message, $context = array()) {
        self::log($message, self::LEVEL_DEBUG, $context);
    }
    
    /**
     * Log API request
     */
    public static function log_api_request($url, $request_data, $response, $http_status) {
        $context = array(
            'url' => $url,
            'request' => $request_data,
            'response' => $response,
            'http_status' => $http_status
        );
        
        if ($http_status === 200) {
            self::info('SpaceRemit API request successful', $context);
        } else {
            self::error('SpaceRemit API request failed', $context);
        }
    }
    
    /**
     * Log payment processing
     */
    public static function log_payment($order_id, $payment_id, $status, $message = '') {
        $context = array(
            'order_id' => $order_id,
            'payment_id' => $payment_id,
            'status' => $status,
            'message' => $message
        );
        
        self::info('Payment processing', $context);
    }
    
    /**
     * Rotate log files
     */
    private static function rotate_logs($log_file) {
        $backup_file = $log_file . '.backup';
        
        if (file_exists($backup_file)) {
            unlink($backup_file);
        }
        
        rename($log_file, $backup_file);
    }
    
    /**
     * Get recent logs
     */
    public static function get_recent_logs($lines = 100) {
        self::init();
        
        $log_file = self::$log_dir . 'spaceremit-' . date('Y-m-d') . '.log';
        
        if (!file_exists($log_file)) {
            return array();
        }
        
        $logs = array();
        $file = new SplFileObject($log_file);
        $file->seek(PHP_INT_MAX);
        $total_lines = $file->key();
        
        $start_line = max(0, $total_lines - $lines);
        $file->seek($start_line);
        
        while (!$file->eof()) {
            $line = trim($file->current());
            if (!empty($line)) {
                $logs[] = $line;
            }
            $file->next();
        }
        
        return $logs;
    }
    
    /**
     * Clear logs
     */
    public static function clear_logs() {
        self::init();
        
        $log_files = glob(self::$log_dir . 'spaceremit-*.log*');
        
        foreach ($log_files as $log_file) {
            unlink($log_file);
        }
        
        return count($log_files);
    }
    
    /**
     * Get log file size
     */
    public static function get_log_size() {
        self::init();
        
        $total_size = 0;
        $log_files = glob(self::$log_dir . 'spaceremit-*.log*');
        
        foreach ($log_files as $log_file) {
            $total_size += filesize($log_file);
        }
        
        return $total_size;
    }
}