<?php

namespace Tests\Unit;

use App\Services\OpenAiCostEstimator;
use Tests\TestCase;

class OpenAiCostEstimatorTest extends TestCase
{
    public function test_it_prices_cached_uncached_cache_write_and_output_tokens_per_model(): void
    {
        config(['openai_costs.models' => [
            'gpt-5.6-luna' => ['input' => 0.20, 'cached_input' => 0.02, 'cache_write' => 0.25, 'output' => 1.20],
            'gpt-5.6-sol' => ['input' => 5.00, 'cached_input' => 0.50, 'cache_write' => 6.25, 'output' => 30.00],
        ]]);

        $estimate = app(OpenAiCostEstimator::class)->estimate([
            'model' => 'gpt-5.6-sol',
            'model_usage' => [
                [
                    'model' => 'gpt-5.6-luna', 'requests' => 2,
                    'input_tokens' => 1_000_000, 'cached_input_tokens' => 200_000,
                    'cache_write_tokens' => 100_000, 'output_tokens' => 100_000,
                ],
                [
                    'model' => 'gpt-5.6-sol', 'requests' => 1,
                    'input_tokens' => 100_000, 'cached_input_tokens' => 0,
                    'cache_write_tokens' => 0, 'output_tokens' => 10_000,
                ],
            ],
        ]);

        $this->assertEqualsWithDelta(1.089, $estimate['usd'], 0.0000001);
        $this->assertSame(3, $estimate['requests']);
        $this->assertSame(2, $estimate['luna_requests']);
        $this->assertSame(1, $estimate['sol_requests']);
        $this->assertSame([], $estimate['unpriced_models']);
    }

    public function test_it_prices_legacy_runs_and_reports_unknown_models_instead_of_treating_them_as_free(): void
    {
        config(['openai_costs.models' => [
            'gpt-5.6-luna' => ['input' => 0.20, 'cached_input' => 0.02, 'cache_write' => 0.25, 'output' => 1.20],
        ]]);

        $legacy = app(OpenAiCostEstimator::class)->estimate([
            'model' => 'gpt-5.6-luna', 'input_tokens' => 1_000_000,
            'output_tokens' => 100_000, 'model_usage' => null,
        ]);
        $unknown = app(OpenAiCostEstimator::class)->estimate([
            'model' => 'future-model', 'input_tokens' => 100,
            'output_tokens' => 20, 'model_usage' => null,
        ]);

        $this->assertEqualsWithDelta(0.32, $legacy['usd'], 0.0000001);
        $this->assertSame(['future-model'], $unknown['unpriced_models']);
        $this->assertSame(0.0, $unknown['usd']);
    }
}
