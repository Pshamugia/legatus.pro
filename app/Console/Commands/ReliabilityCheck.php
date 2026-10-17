<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\ConversationEngine;
use App\Services\ResponseReliabilityGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ReliabilityCheck extends Command
{
    protected $signature = 'legatus:reliability-check
        {--agent= : Agent ID or slug}
        {--live : Also run the configured live model evaluation suite}';

    protected $description = 'Run safe channel-contract and optional live-model release checks';

    public function handle(ConversationEngine $engine, ResponseReliabilityGuard $guard): int
    {
        try {
            $agent = $this->resolveAgent();
        } catch (Throwable $exception) {
            $this->error('No active tenant is available for the reliability check.');

            return self::FAILURE;
        }
        $checks = [];

        try {
            DB::beginTransaction();
            foreach ([
                ['ka', 'დამეხმარე არჩევაში. ჯერ ერთი საჭირო დამაზუსტებელი კითხვა დამისვი, შემდეგ კი პროდუქტები მხოლოდ თქვენი კატალოგიდან მირჩიე.'],
                ['en', 'Help me choose. Ask one necessary clarifying question first, then recommend products only from your catalog.'],
            ] as [$locale, $prompt]) {
                $reply = $engine->handle(
                    $agent,
                    $prompt,
                    'widget',
                    'reliability-'.Str::uuid(),
                    (string) Str::uuid(),
                    'Synthetic reliability check',
                    'guided_recommendation',
                );
                $checks[] = [
                    "Guided recommendation ({$locale})",
                    ($reply['intent'] ?? null) === 'clarification'
                        && substr_count((string) ($reply['text'] ?? ''), '?') === 1
                        && ($reply['products'] ?? []) === []
                        && in_array('ask_recommendation_clarification', $reply['tools_used'] ?? [], true),
                    (string) ($reply['text'] ?? 'empty'),
                ];
            }

            $conversation = $agent->conversations()->create([
                'visitor_id' => 'reliability-contract-'.Str::uuid(),
                'channel' => 'eval',
                'status' => 'ai',
            ]);
            $repaired = $guard->enforce($agent, $conversation, 'დამეხმარეთ', [
                'text' => '', 'intent' => 'unknown', 'confidence' => 5,
                'handoff' => false, 'products' => [], 'sources' => [], 'tools_used' => [],
            ], true);
            $checks[] = [
                'Final response contract',
                ($repaired['intent'] ?? null) === 'clarification'
                    && substr_count((string) ($repaired['text'] ?? ''), '?') === 1
                    && in_array('reliability_guard', $repaired['tools_used'] ?? [], true)
                    && ($repaired['confidence'] ?? null) === 1.0,
                implode(', ', $repaired['reliability_issues'] ?? []),
            ];
        } catch (Throwable $exception) {
            $checks[] = ['Runtime contract', false, Str::limit($exception->getMessage(), 180)];
        } finally {
            DB::rollBack();
        }

        if ($this->option('live')) {
            $exit = Artisan::call('legatus:eval', ['--live' => true, '--agent' => (string) $agent->id]);
            $checks[] = ['Live model evaluation', $exit === self::SUCCESS, trim(Artisan::output())];
        }

        $this->table(
            ['Check', 'Result', 'Evidence'],
            collect($checks)->map(fn (array $check): array => [$check[0], $check[1] ? 'PASS' : 'FAIL', $check[2]])->all(),
        );

        $healthy = collect($checks)->every(fn (array $check): bool => (bool) $check[1]);
        Cache::put('legatus:reliability:last', [
            'healthy' => $healthy,
            'checked_at' => now()->toIso8601String(),
            'agent_id' => $agent->id,
            'checks' => collect($checks)->map(fn (array $check): array => [
                'name' => $check[0],
                'passed' => (bool) $check[1],
            ])->all(),
        ], now()->addDay());
        if (! $healthy) {
            Log::critical('Legatus reliability check failed.', [
                'agent_id' => $agent->id,
                'failed_checks' => collect($checks)->reject(fn (array $check): bool => (bool) $check[1])->pluck(0)->all(),
            ]);
            $this->error('Reliability gate failed. Do not deploy this release.');

            return self::FAILURE;
        }

        $this->info('Reliability gate passed.');

        return self::SUCCESS;
    }

    private function resolveAgent(): Agent
    {
        $selector = trim((string) $this->option('agent'));
        if ($selector === '') {
            return Agent::where('slug', 'legatus-demo')->first() ?? Agent::where('is_active', true)->firstOrFail();
        }

        return ctype_digit($selector)
            ? Agent::findOrFail((int) $selector)
            : Agent::where('slug', $selector)->firstOrFail();
    }
}
