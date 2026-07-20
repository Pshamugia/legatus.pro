<?php

return [
    'privacy_email' => env('LEGATUS_PRIVACY_EMAIL', 'privacy@legatus.pro'),
    'registration_enabled' => (bool) env('LEGATUS_REGISTRATION_ENABLED', env('APP_ENV', 'production') !== 'production'),
    'demo_login_enabled' => (bool) env('LEGATUS_DEMO_LOGIN_ENABLED', env('APP_ENV', 'production') !== 'production'),
    'demo_password' => env('LEGATUS_DEMO_PASSWORD'),
    'offline_fallback_enabled' => (bool) env('LEGATUS_OFFLINE_FALLBACK', env('APP_ENV', 'production') !== 'production'),
    'daily_ai_run_limit' => (int) env('LEGATUS_DAILY_AI_RUN_LIMIT', 200),
    'daily_ai_token_limit' => (int) env('LEGATUS_DAILY_AI_TOKEN_LIMIT', 250000),
    'semantic_similarity_threshold' => (float) env('LEGATUS_SEMANTIC_SIMILARITY_THRESHOLD', 0.35),
    'semantic_candidate_limit' => (int) env('LEGATUS_SEMANTIC_CANDIDATE_LIMIT', 2000),
    'commerce_max_catalog_products' => (int) env('LEGATUS_COMMERCE_MAX_CATALOG_PRODUCTS', 10000),
    'commerce_max_response_bytes' => (int) env('LEGATUS_COMMERCE_MAX_RESPONSE_BYTES', 5242880),
    'commerce_sync_lock_seconds' => (int) env('LEGATUS_COMMERCE_SYNC_LOCK_SECONDS', 600),
    'widget_frame_ancestors' => env('LEGATUS_WIDGET_FRAME_ANCESTORS', '*'),
];
