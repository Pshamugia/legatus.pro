<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\EvalCase;
use App\Models\EvaluationRun;
use App\Services\SalesAgentService;
use Illuminate\Console\Command;

class RunLegatusEvals extends Command
{
    protected $signature = 'legatus:eval {--live : Use the configured OpenAI model instead of deterministic fallback} {--agent= : Agent ID or slug}';

    protected $description = 'Run repeatable Legatus sales-agent quality evaluations';

    public function handle(SalesAgentService $service): int
    {
        $agent = $this->resolveAgent();

        if (! $this->option('live')) {
            config(['services.openai.key' => null]);
            config(['legatus.offline_fallback_enabled' => true]);
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

        foreach (EvalCase::where('active', true)->get() as $case) {
            $conversation = $agent->conversations()->create([
                'visitor_id' => 'eval-'.$case->id.'-'.now()->timestamp,
                'status' => 'ai',
                'channel' => 'eval',
            ]);
            $conversation->messages()->create(['role' => 'customer', 'content' => $case->prompt]);

            $reply = $service->reply($agent, $case->prompt, $conversation);
            $intentMatches = $reply['intent'] === $case->expected_intent;
            $handoffMatches = (bool) $reply['handoff'] === $case->expected_handoff;
            $toolsMatch = ! $this->option('live')
                || collect($case->expected_tools ?? [])->every(
                    fn ($tool) => collect($reply['tools_used'] ?? [])->contains($tool)
                );
            $assertions = $case->assertions ?? [];
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

            $passedCase ? $passed++ : $failed++;
            $results[] = [
                'case' => $case->name,
                'passed' => $passedCase,
                'actual_intent' => $reply['intent'],
                'actual_handoff' => $reply['handoff'],
                'tools' => $reply['tools_used'] ?? [],
                'constraints_passed' => $constraintsMatch,
            ];

            $this->line(($passedCase ? 'PASS' : 'FAIL')." · {$case->name}");
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
