<?php
/**
 * SpaceRemit WooCommerce Blocks Integration
 * Simplified version - no external SpaceRemit library loading
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * SpaceRemit payment method integration for WooCommerce Blocks
 */
final class WC_Gateway_SpaceRemit_Blocks extends AbstractPaymentMethodType {

    /**
     * Payment method name/id/slug.
     */
    protected $name = 'spaceremit';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_spaceremit_settings', []);
        
        SpaceRemit_Logger::debug('SpaceRemit Blocks initialized', array(
            'settings_loaded' => !empty($this->settings),
            'enabled' => $this->settings['enabled'] ?? 'no'
        ));
    }

    /**
     * Returns if this payment method should be active.
     */
    public function is_active() {
        $gateway = $this->get_gateway();
        $is_active = $gateway && $gateway->is_available();
        
        SpaceRemit_Logger::debug('SpaceRemit Blocks availability check', array(
            'is_active' => $is_active,
            'gateway_exists' => !empty($gateway),
            'gateway_enabled' => $gateway ? $gateway->enabled : 'unknown'
        ));
        
        return $is_active;
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     * Simplified version - no external SpaceRemit library
     */
    public function get_payment_method_script_handles() {
        $script_path = 'assets/js/spaceremit-blocks.js';
        $script_asset_path = SPACEREMIT_WC_PLUGIN_PATH . 'assets/js/spaceremit-blocks.asset.php';
        
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array(
                    'wc-blocks-registry',
                    'wp-element',
                    'wp-i18n',
                    'wp-html-entities'
                ),
                'version' => SPACEREMIT_WC_VERSION
            );

        $script_url = SPACEREMIT_WC_PLUGIN_URL . $script_path;

        // Register our blocks script WITHOUT SpaceRemit library dependency
        wp_register_script(
            'wc-spaceremit-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'wc-spaceremit-blocks',
                'spaceremit-woocommerce',
                SPACEREMIT_WC_PLUGIN_PATH . 'languages'
            );
        }

        return ['wc-spaceremit-blocks'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     */
    public function get_payment_method_data() {
        $gateway = $this->get_gateway();
        
        if (!$gateway) {
            return array();
        }

        $data = array(
            'title' => $gateway->get_option('title'),
            'description' => $gateway->get_option('description'),
            'supports' => array_filter($gateway->supports, array($gateway, 'supports')),
            'public_key' => $gateway->get_option('public_key'),
            'testmode' => 'yes' === $gateway->get_option('testmode'),
            'enable_local_methods' => 'yes' === $gateway->get_option('enable_local_methods'),
            'enable_card_payment' => 'yes' === $gateway->get_option('enable_card_payment'),
            'logo_url' => SPACEREMIT_WC_PLUGIN_URL . 'assets/images/spaceremit-logo.png',
            'callback_url' => home_url('/?spaceremit_callback=1'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('spaceremit_blocks_nonce'),
            'currency' => get_woocommerce_currency(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'strings' => array(
                'redirect_notice' => __('You will be redirected to SpaceRemit to complete your payment securely.', 'spaceremit-woocommerce'),
                'local_methods' => __('Local Payment Methods', 'spaceremit-woocommerce'),
                'card_payment' => __('Credit/Debit Card', 'spaceremit-woocommerce'),
                'processing' => __('Processing...', 'spaceremit-woocommerce'),
                'pay_now' => __('Pay Now', 'spaceremit-woocommerce'),
                'payment_failed' => __('Payment failed. Please try again.', 'spaceremit-woocommerce'),
                'connection_error' => __('Connection error. Please check your internet connection and try again.', 'spaceremit-woocommerce')
            )
        );

        SpaceRemit_Logger::debug('SpaceRemit Blocks data prepared', array(
            'has_public_key' => !empty($data['public_key']),
            'testmode' => $data['testmode'],
            'local_methods' => $data['enable_local_methods'],
            'card_payment' => $data['enable_card_payment']
        ));

        return $data;
    }

    /**
     * Returns the gateway instance.
     */
    private function get_gateway() {
        static $gateway = null;
        
        if ($gateway === null) {
            $payment_gateways_class = WC()->payment_gateways();
            $payment_gateways = $payment_gateways_class->payment_gateways();
            $gateway = isset($payment_gateways['spaceremit']) ? $payment_gateways['spaceremit'] : false;
        }

        return $gateway;
    }
}