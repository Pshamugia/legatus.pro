<?php

return [
    /*
    | Estimated Standard-tier text-token prices in USD per 1M tokens.
    | Keep these configurable because provider pricing can change independently
    | from an application release.
    */
    'models' => [
        'gpt-5.6-luna' => [
            'input' => (float) env('OPENAI_LUNA_INPUT_USD_PER_MILLION', 0.20),
            'cached_input' => (float) env('OPENAI_LUNA_CACHED_INPUT_USD_PER_MILLION', 0.02),
            'cache_write' => (float) env('OPENAI_LUNA_CACHE_WRITE_USD_PER_MILLION', 0.25),
            'output' => (float) env('OPENAI_LUNA_OUTPUT_USD_PER_MILLION', 1.20),
        ],
        'gpt-5.6-sol' => [
            'input' => (float) env('OPENAI_SOL_INPUT_USD_PER_MILLION', 5.00),
            'cached_input' => (float) env('OPENAI_SOL_CACHED_INPUT_USD_PER_MILLION', 0.50),
            'cache_write' => (float) env('OPENAI_SOL_CACHE_WRITE_USD_PER_MILLION', 6.25),
            'output' => (float) env('OPENAI_SOL_OUTPUT_USD_PER_MILLION', 30.00),
        ],
    ],
    'monthly_target_usd' => (float) env('LEGATUS_AI_MONTHLY_COST_TARGET_USD', 5.00),
    'warning_percent' => (int) env('LEGATUS_AI_COST_WARNING_PERCENT', 70),
];
