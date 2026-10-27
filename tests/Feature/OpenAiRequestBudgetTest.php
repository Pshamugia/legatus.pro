<?php

namespace Tests\Feature;

use App\Services\OpenAiSalesOrchestrator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiRequestBudgetTest extends TestCase
{
    public function test_moderation_preflight_does_not_retry(): void
    {
        $this->assertSingleAttempt('moderation');
    }

    public function test_catalog_context_preflight_does_not_retry(): void
    {
        $this->assertSingleAttempt('responses.catalog_context');
    }

    private function assertSingleAttempt(string $stage): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.retries' => 2]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['error' => ['message' => 'preflight unavailable']], 503);
        });

        try {
            $method = new \ReflectionMethod(OpenAiSalesOrchestrator::class, 'postJson');
            $method->invoke(app(OpenAiSalesOrchestrator::class), '/responses', ['model' => 'test-model'], $stage, microtime(true) + 10, 5);
            $this->fail("{$stage} should reject a failed request.");
        } catch (RequestException) {
            $this->assertSame(1, $calls, "{$stage} must leave time for the answer.");
        }
    }
}
