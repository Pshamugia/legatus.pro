<?php

return [
    'app_id' => env('WHATSAPP_APP_ID', env('META_APP_ID')),
    'app_secret' => env('WHATSAPP_APP_SECRET', env('META_APP_SECRET')),
    'configuration_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
    'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'graph_url' => rtrim((string) env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'), '/'),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', env('META_GRAPH_VERSION', 'v25.0')),
    'redirect_uri' => env('WHATSAPP_REDIRECT_URI'),
    'timeout' => (int) env('WHATSAPP_TIMEOUT', 15),
    'connect_timeout' => (int) env('WHATSAPP_CONNECT_TIMEOUT', 5),
    'max_webhook_bytes' => (int) env('WHATSAPP_MAX_WEBHOOK_BYTES', 1048576),
];
