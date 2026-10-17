<?php

namespace App\Services;

use Illuminate\Support\Str;

class OpenAiCreditNotifier
{
    public function __construct(private PlatformHealthIncidentService $incidents) {}

    public function report(\Throwable $exception): void
    {
        $message = $exception->getMessage();
        if (! Str::contains(Str::lower($message), ['no credits remaining', 'credit_balance_exhausted'])) {
            return;
        }

        $this->incidents->syncCategory('openai_credit', [[
            'key' => 'openai.credit_exhausted',
            'severity' => 'critical',
            'title' => 'OpenAI API credit is exhausted',
            'summary' => 'OpenAI rejected a Legatus request because the API account has no credits remaining.',
            'context' => ['provider_error_fingerprint' => hash('sha256', Str::limit($message, 300))],
        ]]);
    }

    public function resolved(): void
    {
        $this->incidents->syncCategory('openai_credit', []);
    }
}
