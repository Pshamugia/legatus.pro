<?php

return [
    'app_id' => env('THREADS_APP_ID'),
    'app_secret' => env('THREADS_APP_SECRET'),
    'redirect_uri' => env('THREADS_REDIRECT_URI'),
    'authorization_url' => rtrim((string) env('THREADS_AUTHORIZATION_URL', 'https://threads.net'), '/'),
    'api_url' => rtrim((string) env('THREADS_API_URL', 'https://graph.threads.net'), '/'),
    'scopes' => array_values(array_filter(explode(',', (string) env(
        'THREADS_SCOPES',
        'threads_basic,threads_content_publish'
    )))),
];
