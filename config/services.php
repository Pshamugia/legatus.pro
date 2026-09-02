<?php

return [

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        // Keep the established model as the default until the hybrid canary is
        // explicitly enabled. This makes rollback a configuration change, not
        // a code deployment.
        'model' => env('OPENAI_MODEL', 'gpt-5.6-sol'),
        'primary_model' => env('OPENAI_PRIMARY_MODEL', 'gpt-5.6-luna'),
        'social_media_model' => env('OPENAI_SOCIAL_MEDIA_MODEL', 'gpt-5.6-luna'),
        'fallback_model' => env('OPENAI_FALLBACK_MODEL', 'gpt-5.6-sol'),
        'hybrid_enabled' => filter_var(env('OPENAI_HYBRID_ENABLED', false), FILTER_VALIDATE_BOOL),
        'hybrid_rollout_percent' => min(100, max(0, (int) env('OPENAI_HYBRID_ROLLOUT_PERCENT', 5))),
        'fallback_enabled' => filter_var(env('OPENAI_FALLBACK_ENABLED', true), FILTER_VALIDATE_BOOL),
        'fallback_reasoning_effort' => env('OPENAI_FALLBACK_REASONING_EFFORT', 'low'),
        'moderation_model' => env('OPENAI_MODERATION_MODEL', 'omni-moderation-latest'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'timeout' => max(25, (int) env('OPENAI_TIMEOUT', 30)),
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 5),
        'retries' => max(1, (int) env('OPENAI_RETRIES', 2)),
        'max_tool_rounds' => (int) env('OPENAI_MAX_TOOL_ROUNDS', 4),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 900),
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'none'),
        'total_timeout' => max(60, (int) env('OPENAI_TOTAL_TIMEOUT', 75)),
        'moderation_timeout' => (int) env('OPENAI_MODERATION_TIMEOUT', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
