const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { createElement, useState, useEffect } = window.wp.element;
const { __ } = window.wp.i18n;
const { decodeEntities } = window.wp.htmlEntities;
const { getSetting } = window.wc.wcSettings;

// Get SpaceRemit settings
const spaceremitData = getSetting('spaceremit_data', {});

/**
 * SpaceRemit payment method component for WooCommerce Blocks
 * Simplified version - no external SpaceRemit library loading
 */
const SpaceRemitPaymentMethod = (props) => {
    const { eventRegistration, emitResponse, billing } = props;
    const { onPaymentSetup, onCheckoutValidation } = eventRegistration;
    const { responseTypes } = emitResponse;

    const [isProcessing, setIsProcessing] = useState(false);
    const [paymentMethod, setPaymentMethod] = useState('local');

    // Setup payment processing
    useEffect(() => {
        const unsubscribePaymentSetup = onPaymentSetup(async () => {
            setIsProcessing(true);

            try {
                // Validate required data
                if (!spaceremitData.public_key) {
                    throw new Error('SpaceRemit is not properly configured.');
                }

                if (!billing.billingData.email) {
                    throw new Error('Billing email is required.');
                }

                // Store payment method selection - this will redirect to pay-for-order page
                const paymentData = {
                    payment_method: 'spaceremit',
                    spaceremit_payment_type: paymentMethod,
                    spaceremit_blocks: true
                };

                return {
                    type: responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: paymentData
                    }
                };
            } catch (error) {
                console.error('SpaceRemit payment setup error:', error);
                return {
                    type: responseTypes.ERROR,
                    message: error.message,
                };
            } finally {
                setIsProcessing(false);
            }
        });

        const unsubscribeCheckoutValidation = onCheckoutValidation(() => {
            // Validate SpaceRemit specific requirements
            if (!spaceremitData.public_key) {
                return {
                    type: responseTypes.ERROR,
                    message: 'SpaceRemit payment method is not properly configured.',
                };
            }

            return true;
        });

        return () => {
            unsubscribePaymentSetup();
            unsubscribeCheckoutValidation();
        };
    }, [onPaymentSetup, onCheckoutValidation, responseTypes, paymentMethod, billing]);

    // Render payment method selection
    const renderPaymentOptions = () => {
        const options = [];

        if (spaceremitData.enable_local_methods) {
            options.push(
                createElement('label', {
                    key: 'local-methods',
                    className: 'spaceremit-payment-option',
                    style: { display: 'block', marginBottom: '10px', cursor: 'pointer' }
                }, [
                    createElement('input', {
                        key: 'local-radio',
                        type: 'radio',
                        name: 'spaceremit_payment_type',
                        value: 'local',
                        checked: paymentMethod === 'local',
                        onChange: (e) => setPaymentMethod(e.target.value),
                        style: { marginRight: '8px' }
                    }),
                    createElement('span', {
                        key: 'local-label'
                    }, spaceremitData.strings?.local_methods || 'Local Payment Methods')
                ])
            );
        }

        if (spaceremitData.enable_card_payment) {
            options.push(
                createElement('label', {
                    key: 'card-payment',
                    className: 'spaceremit-payment-option',
                    style: { display: 'block', marginBottom: '10px', cursor: 'pointer' }
                }, [
                    createElement('input', {
                        key: 'card-radio',
                        type: 'radio',
                        name: 'spaceremit_payment_type',
                        value: 'card',
                        checked: paymentMethod === 'card',
                        onChange: (e) => setPaymentMethod(e.target.value),
                        style: { marginRight: '8px' }
                    }),
                    createElement('span', {
                        key: 'card-label'
                    }, spaceremitData.strings?.card_payment || 'Credit/Debit Card')
                ])
            );
        }

        return options;
    };

    // Main component render
    return createElement('div', {
        className: 'wc-block-components-payment-method-content wc-block-components-payment-method-content--spaceremit',
        style: { padding: '16px 0' }
    }, [
        // Description
        createElement('div', {
            key: 'description',
            className: 'spaceremit-description',
            style: { marginBottom: '16px', color: '#666' }
        }, decodeEntities(spaceremitData.description || '')),

        // Payment method options (if both are enabled)
        (spaceremitData.enable_local_methods && spaceremitData.enable_card_payment) ? 
            createElement('div', {
                key: 'payment-options',
                className: 'spaceremit-payment-options',
                style: { marginBottom: '16px' }
            }, [
                createElement('p', {
                    key: 'options-title',
                    style: { marginBottom: '12px', fontWeight: '500' }
                }, __('Choose your preferred payment method:', 'spaceremit-woocommerce')),
                ...renderPaymentOptions()
            ]) : null,

        // Redirect notice
        createElement('div', {
            key: 'redirect-notice',
            className: 'spaceremit-redirect-notice',
            style: { 
                padding: '12px', 
                backgroundColor: '#f8f9fa', 
                border: '1px solid #e9ecef',
                borderRadius: '4px',
                fontSize: '14px'
            }
        }, [
            createElement('span', {
                key: 'notice-icon',
                style: { marginRight: '8px' }
            }, '🔒'),
            createElement('span', {
                key: 'notice-text'
            }, spaceremitData.strings?.redirect_notice || 'You will be redirected to SpaceRemit to complete your payment securely.')
        ]),

        // Processing indicator
        isProcessing ? createElement('div', {
            key: 'processing',
            className: 'spaceremit-processing',
            style: { 
                textAlign: 'center', 
                padding: '16px',
                color: '#666'
            }
        }, spaceremitData.strings?.processing || 'Processing...') : null
    ]);
};

/**
 * SpaceRemit payment method configuration for blocks
 */
const SpaceRemitPaymentMethodConfig = {
    name: 'spaceremit',
    label: createElement('div', {
        style: { display: 'flex', alignItems: 'center' }
    }, [
        spaceremitData.logo_url ? createElement('img', {
            key: 'logo',
            src: spaceremitData.logo_url,
            alt: 'SpaceRemit',
            style: { 
                marginRight: '8px', 
                height: '24px',
                maxWidth: '100px',
                objectFit: 'contain'
            },
            onError: (e) => {
                e.target.style.display = 'none';
            }
        }) : null,
        createElement('span', {
            key: 'title'
        }, decodeEntities(spaceremitData.title || __('SpaceRemit', 'spaceremit-woocommerce')))
    ]),
    content: createElement(SpaceRemitPaymentMethod),
    edit: createElement(SpaceRemitPaymentMethod),
    canMakePayment: () => {
        // Check if SpaceRemit is properly configured
        return !!(spaceremitData.public_key && (spaceremitData.enable_local_methods || spaceremitData.enable_card_payment));
    },
    ariaLabel: decodeEntities(spaceremitData.title || __('SpaceRemit Payment Gateway', 'spaceremit-woocommerce')),
    supports: {
        features: spaceremitData.supports || ['products'],
        showSavedCards: false,
        showSaveOption: false
    }
};

// Register the payment method
registerPaymentMethod(SpaceRemitPaymentMethodConfig);

// Add some basic styling
if (document.head) {
    const style = document.createElement('style');
    style.textContent = `
        .spaceremit-payment-option input[type="radio"] {
            margin-right: 8px;
        }
        
        .spaceremit-payment-option:hover {
            background-color: #f8f9fa;
            padding: 4px;
            border-radius: 4px;
        }
        
        .spaceremit-processing {
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
    `;
    document.head.appendChild(style);
}