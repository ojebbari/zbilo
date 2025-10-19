jQuery(document).ready(function($) {
    'use strict';

    // SpaceRemit checkout integration
    var spaceremitCheckout = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            // Handle payment method selection
            $(document.body).on('change', 'input[name="payment_method"]', this.handlePaymentMethodChange);
            
            // Handle SpaceRemit form submission
            $(document.body).on('submit', '#spaceremit-form', this.handleFormSubmission);
        },

        handlePaymentMethodChange: function() {
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            
            if (selectedMethod === 'spaceremit') {
                // Load SpaceRemit form if not already loaded
                spaceremitCheckout.loadSpaceRemitForm();
            }
        },

        loadSpaceRemitForm: function() {
            // This function can be used to dynamically load SpaceRemit form
            // if needed in the checkout process
        },

        handleFormSubmission: function(e) {
            var $form = $(this);
            var $submitButton = $form.find('#spaceremit-pay-button');
            
            // Disable submit button to prevent double submission
            $submitButton.prop('disabled', true).text('Processing...');
            
            // Let SpaceRemit JS handle the form submission
            // The form will be submitted after getting the payment code
        },

        showError: function(message) {
            $('.woocommerce-notices-wrapper').html(
                '<div class="woocommerce-error">' + message + '</div>'
            );
            
            $('html, body').animate({
                scrollTop: $('.woocommerce-error').offset().top - 100
            }, 1000);
        },

        showSuccess: function(message) {
            $('.woocommerce-notices-wrapper').html(
                '<div class="woocommerce-message">' + message + '</div>'
            );
        }
    };

    // Initialize
    spaceremitCheckout.init();

    // Global SpaceRemit callback functions
    window.SP_SUCCESSFUL_PAYMENT = function(spaceremit_code) {
        console.log('SpaceRemit payment successful:', spaceremit_code);
        
        // Add payment code to form and submit
        $('#spaceremit-form').append(
            '<input type="hidden" name="SP_payment_code" value="' + spaceremit_code + '">'
        );
        
        // Submit the form
        $('#spaceremit-form')[0].submit();
    };

    window.SP_FAILD_PAYMENT = function() {
        console.log('SpaceRemit payment failed');
        
        spaceremitCheckout.showError('Payment failed. Please try again.');
        
        // Re-enable submit button
        $('#spaceremit-pay-button').prop('disabled', false).text('Pay Now');
    };

    window.SP_RECIVED_MESSAGE = function(message) {
        console.log('SpaceRemit message:', message);
        
        // Show message to user
        if (message) {
            alert(message);
        }
    };

    if (typeof window.SPNEEDAUTH !== 'function') {
  window.SPNEEDAUTH = function(/* target_auth_link */) {
    // no-op; the SpaceRemit library shows its own in-page popup
  };
}
});