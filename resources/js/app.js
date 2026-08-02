import './bootstrap';
import { initializePaddle } from '@paddle/paddle-js';

const paddleConfig = document.getElementById('paddle-config');
if (paddleConfig?.dataset.token) {
    const message = document.getElementById('paddle-message');
    initializePaddle({
        token: paddleConfig.dataset.token,
        environment: paddleConfig.dataset.environment === 'production' ? 'production' : 'sandbox',
        eventCallback(event) {
            if (event.name === 'checkout.completed' && message) message.textContent = 'Payment details received. Activating your workspace…';
            if ((event.name === 'checkout.error' || event.name === 'checkout.payment.error') && message) message.textContent = 'Checkout could not be completed. Please try again.';
        },
    }).then((paddle) => {
        document.querySelectorAll('.paddle-checkout').forEach((button) => button.addEventListener('click', () => paddle?.Checkout.open({
            items: [{ priceId: button.dataset.priceId, quantity: 1 }],
            customer: { email: paddleConfig.dataset.email },
            customData: { billing_reference: paddleConfig.dataset.billingReference },
            settings: { variant: 'one-page', successUrl: paddleConfig.dataset.successUrl },
        })));
    }).catch(() => { if (message) message.textContent = 'Paddle checkout failed to load. Please refresh and try again.'; });
}
