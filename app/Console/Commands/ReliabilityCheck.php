<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\EvalCase;
use App\Notifications\PlatformReliabilityAlert;
use App\Services\ConversationEngine;
use App\Services\ResponseReliabilityGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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
            if (blank(config('services.openai.key'))) {
                $checks[] = ['Live model evaluation', false, 'OPENAI_API_KEY is not configured.'];
            } else {
                $caseCount = EvalCase::where('active', true)->count();
                $this->newLine();
                $this->components->info("Running {$caseCount} live evaluation cases. Progress will appear below.");

                $exit = $this->call('legatus:eval', [
                    '--live' => true,
                ]);
                $checks[] = [
                    'Live model evaluation',
                    $exit === self::SUCCESS,
                    $exit === self::SUCCESS
                        ? "All {$caseCount} live cases passed."
                        : 'One or more live cases failed. See the results above.',
                ];
            }
        }

        $this->table(
            ['Check', 'Result', 'Evidence'],
            collect($checks)->map(fn (array $check): array => [$check[0], $check[1] ? 'PASS' : 'FAIL', $check[2]])->all(),
        );

        $healthy = collect($checks)->every(fn (array $check): bool => (bool) $check[1]);
        $mode = $this->option('live') ? 'live' : 'contract';
        $cacheKey = 'legatus:reliability:last:'.$mode;
        $previousStatus = Cache::get($cacheKey);
        $currentStatus = [
            'mode' => $mode,
            'healthy' => $healthy,
            'checked_at' => now()->toIso8601String(),
            'agent_id' => $agent->id,
            'checks' => collect($checks)->map(fn (array $check): array => [
                'name' => $check[0],
                'passed' => (bool) $check[1],
            ])->all(),
        ];
        Cache::put($cacheKey, $currentStatus, now()->addDays(2));
        $this->notifyHealthTransition(is_array($previousStatus) ? $previousStatus : null, $currentStatus);
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

    /**
     * @param  array{healthy?: bool}|null  $previous
     * @param  array{mode: string, healthy: bool, checked_at: string, checks: array<int, array{name: string, passed: bool}>}  $current
     */
    private function notifyHealthTransition(?array $previous, array $current): void
    {
        $wasHealthy = $previous['healthy'] ?? null;
        $isHealthy = $current['healthy'];
        if (($wasHealthy === null && $isHealthy) || $wasHealthy === $isHealthy) {
            return;
        }

        $email = trim((string) config('legatus.super_admin_email'));
        if ($email === '') {
            return;
        }

        $failedChecks = collect($current['checks'])
            ->where('passed', false)
            ->pluck('name')
            ->values()
            ->all();

        try {
            Notification::route('mail', $email)->notify(new PlatformReliabilityAlert(
                $current['mode'],
                $isHealthy,
                $failedChecks,
                $current['checked_at'],
            ));
        } catch (Throwable $exception) {
            Log::error('Could not send the platform reliability alert.', [
                'exception' => $exception::class,
                'healthy' => $isHealthy,
            ]);
        }
    }
}
