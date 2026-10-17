<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EvalCase;
use App\Models\EvaluationRun;
use App\Services\ReliabilityFixtureManager;
use App\Services\SalesAgentService;
use Database\Seeders\EvalCaseSeeder;
use Illuminate\Console\Command;

class RunLegatusEvals extends Command
{
    protected $signature = 'legatus:eval {--live : Use the configured OpenAI model with the isolated platform fixture} {--agent= : Agent ID or slug for offline evaluation}';

    protected $description = 'Run repeatable Legatus sales-agent quality evaluations';

    public function handle(SalesAgentService $service, ReliabilityFixtureManager $fixtureManager): int
    {
        app(EvalCaseSeeder::class)->run();
        $cases = EvalCase::where('active', true)->get();

        if ($this->option('live')) {
            $agent = $fixtureManager->provision();
            $this->components->info('Using isolated platform reliability fixture; no customer tenant data will be changed.');
        } else {
            $agent = $this->resolveAgent();
            config(['services.openai.key' => null]);
            config(['legatus.offline_fallback_enabled' => true]);
        }

        if ($this->option('live') && $fixtureError = $this->liveFixtureError($agent, $cases)) {
            $this->error($fixtureError);

            return self::FAILURE;
        }

        AgentRun::where('agent_id', $agent->id)
            ->whereNull('conversation_id')
            ->where('provider', 'local')
            ->where('model', 'deterministic-fallback')
            ->delete();

        $staleEvaluations = $agent->conversations()
            ->where('channel', 'eval')
            ->where('visitor_id', 'like', 'eval-%');
        AgentRun::where('agent_id', $agent->id)
            ->whereIn('conversation_id', (clone $staleEvaluations)->select('conversations.id'))
            ->delete();
        $staleEvaluations->delete();

        $results = [];
        $passed = 0;
        $failed = 0;

        foreach ($cases as $case) {
            $conversation = $agent->conversations()->create([
                'visitor_id' => 'eval-'.$case->id.'-'.now()->timestamp,
                'status' => 'ai',
                'channel' => 'eval',
            ]);
            $conversation->messages()->create(['role' => 'customer', 'content' => $case->prompt]);

            $reply = $service->reply($agent, $case->prompt, $conversation);
            $assertions = $case->assertions ?? [];
            $allowedIntents = collect($assertions['allowed_intents'] ?? [$case->expected_intent]);
            $intentMatches = $allowedIntents->containsStrict($reply['intent']);
            $handoffMatches = (bool) $reply['handoff'] === $case->expected_handoff;
            $toolsMatch = ! $this->option('live')
                || collect($case->expected_tools ?? [])->every(
                    function ($tool) use ($reply, $assertions): bool {
                        $accepted = collect([$tool])->merge(data_get($assertions, "tool_aliases.{$tool}", []));

                        return $accepted->intersect($reply['tools_used'] ?? [])->isNotEmpty();
                    }
                );
            $products = collect($reply['products'] ?? []);
            $constraintsMatch = true;
            if (isset($assertions['max_price'])) {
                $constraintsMatch = $constraintsMatch && $products->isNotEmpty() && $products->every(fn ($product) => (float) data_get($product, 'price') <= (float) $assertions['max_price']);
            }
            if ($assertions['in_stock'] ?? false) {
                $constraintsMatch = $constraintsMatch && $products->isNotEmpty() && $products->every(fn ($product) => (int) data_get($product, 'stock') > 0);
            }
            foreach ($assertions['not_contains'] ?? [] as $forbidden) {
                $constraintsMatch = $constraintsMatch && ! str_contains(strtolower($reply['text']), strtolower($forbidden));
            }
            $passedCase = $intentMatches && $handoffMatches && $toolsMatch && $constraintsMatch;
            $failures = [];
            if (! $intentMatches) {
                $failures[] = 'intent expected='.$allowedIntents->implode('|').", actual={$reply['intent']}";
            }
            if (! $handoffMatches) {
                $failures[] = 'handoff expected='.($case->expected_handoff ? 'true' : 'false')
                    .', actual='.($reply['handoff'] ? 'true' : 'false');
            }
            if (! $toolsMatch) {
                $missingTools = collect($case->expected_tools ?? [])
                    ->diff($reply['tools_used'] ?? [])
                    ->implode(', ');
                $failures[] = 'missing tools='.$missingTools;
            }
            if (! $constraintsMatch) {
                $failures[] = 'verified constraints failed (products='.$products->count().')';
            }

            $passedCase ? $passed++ : $failed++;
            $results[] = [
                'case' => $case->name,
                'passed' => $passedCase,
                'actual_intent' => $reply['intent'],
                'actual_handoff' => $reply['handoff'],
                'tools' => $reply['tools_used'] ?? [],
                'product_ids' => $products->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'constraints_passed' => $constraintsMatch,
                'failures' => $failures,
            ];

            $details = $passedCase ? '' : ' - '.implode('; ', $failures);
            $this->line(($passedCase ? 'PASS' : 'FAIL')." - {$case->name}{$details}");
        }

        EvaluationRun::create([
            'agent_id' => $agent->id,
            'mode' => $this->option('live') ? 'live' : 'offline',
            'passed' => $passed,
            'failed' => $failed,
            'results' => $results,
        ]);
        $this->info("{$passed} passed, {$failed} failed");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function liveFixtureError(Agent $agent, $cases): ?string
    {
        $requiredSkus = $cases
            ->flatMap(fn (EvalCase $case) => data_get($case->assertions, 'required_skus', []))
            ->unique()
            ->values();
        $visibleProducts = $agent->customerProducts()->where('is_active', true);
        $missingSkus = $requiredSkus
            ->reject(fn (string $sku): bool => (clone $visibleProducts)->where('sku', $sku)->exists())
            ->values();

        if ($missingSkus->isNotEmpty()) {
            return 'Live evaluation fixture is invalid: customer-visible products are missing: '
                .$missingSkus->implode(', ').'. Do not run model evaluations against a real business tenant.';
        }

        $minimumProducts = (int) $cases->max(
            fn (EvalCase $case): int => (int) data_get($case->assertions, 'minimum_products', 0)
        );
        if ($minimumProducts > 0 && (clone $visibleProducts)->where('stock', '>', 0)->count() < $minimumProducts) {
            return "Live evaluation fixture is invalid: at least {$minimumProducts} active in-stock products are required.";
        }

        return null;
    }

    private function resolveAgent(): Agent
    {
        $selector = (string) $this->option('agent');
        if ($selector === '') {
            return Agent::where('slug', 'legatus-demo')->first() ?? Agent::firstOrFail();
        }

        return ctype_digit($selector)
            ? Agent::findOrFail((int) $selector)
            : Agent::where('slug', $selector)->firstOrFail();
    }
}
