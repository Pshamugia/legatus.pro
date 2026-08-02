<?php

return [
    'billing_enforced' => (bool) env('PADDLE_BILLING_ENFORCED', false),
    'environment' => env('PADDLE_ENV', 'sandbox'),
    'client_token' => env('PADDLE_CLIENT_TOKEN'),
    'api_key' => env('PADDLE_API_KEY'),
    'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
    'webhook_tolerance' => (int) env('PADDLE_WEBHOOK_TOLERANCE', 300),
    'prices' => [
        'monthly' => env('PADDLE_PRICE_MONTHLY'),
        'six_months' => env('PADDLE_PRICE_SIX_MONTHS'),
        'yearly' => env('PADDLE_PRICE_YEARLY'),
    ],
];
