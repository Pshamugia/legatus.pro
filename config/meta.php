<?php

return [
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
    'graph_url' => rtrim((string) env('META_GRAPH_URL', 'https://graph.facebook.com'), '/'),
    'dialog_url' => rtrim((string) env('META_DIALOG_URL', 'https://www.facebook.com'), '/'),
    'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
    'redirect_uri' => env('META_REDIRECT_URI'),
    'timeout' => (int) env('META_TIMEOUT', 15),
    'connect_timeout' => (int) env('META_CONNECT_TIMEOUT', 5),
    'retries' => (int) env('META_RETRIES', 2),
    'max_webhook_bytes' => (int) env('META_MAX_WEBHOOK_BYTES', 1048576),
    'outbox_stale_seconds' => (int) env('META_OUTBOX_STALE_SECONDS', 60),
    'outbox_batch_size' => (int) env('META_OUTBOX_BATCH_SIZE', 100),
    'scopes' => [
        'facebook' => array_values(array_filter(explode(',', (string) env(
            'META_FACEBOOK_SCOPES',
            'pages_show_list,pages_manage_metadata,pages_messaging,pages_read_engagement'
        )))),
        'instagram' => array_values(array_filter(explode(',', (string) env(
            'META_INSTAGRAM_SCOPES',
            'pages_show_list,pages_manage_metadata,pages_read_engagement,instagram_basic,instagram_manage_messages'
        )))),
    ],
];
