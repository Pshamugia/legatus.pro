<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\SalesAgentService;
use Illuminate\Console\Command;

class VerifyOpenAi extends Command
{
    protected $signature = 'legatus:verify-openai {--shopping : Run the personal-shopping scenario} {--agent= : Agent ID or slug}';

    protected $description = 'Run a safe end-to-end Legatus OpenAI health check';

    public function handle(SalesAgentService $service): int
    {
        if (! config('services.openai.key')) {
            $this->error('OPENAI_API_KEY is not configured.');

            return self::FAILURE;
        }

        $agent = $this->resolveAgent();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'live-api-check-'.now()->timestamp,
            'customer_name' => 'Build Week QA',
            'channel' => 'eval',
            'status' => 'ai',
            'last_message_at' => now(),
        ]);
        $prompt = $this->option('shopping')
            ? '30 ლარამდე ვეძებ მეგობრისთვის საჩუქარს. უყვარს იდუმალი, მაგიური და თანამედროვე რომანები. შემირჩიე საუკეთესო ვარიანტი, ამიხსენი რატომ და პასუხამდე მისი ზუსტი მიმდინარე მარაგიც გადაამოწმე.'
            : 'Piranesi მარაგშია და რამდენი ღირს?';
        $conversation->messages()->create(['role' => 'customer', 'content' => $prompt]);

        $reply = $service->reply($agent, $prompt, $conversation);
        $run = $conversation->hasOne(AgentRun::class)->latestOfMany()->first();

        $this->table(['Status', 'Model', 'Intent', 'Tools', 'Tokens', 'Reply'], [[
            $run?->status ?? 'unknown',
            $run?->model ?? 'unknown',
            $reply['intent'],
            collect($reply['tools_used'] ?? [])->implode(', ') ?: 'none',
            ($run?->input_tokens ?? 0).'/'.($run?->output_tokens ?? 0),
            $reply['text'],
        ]]);

        $requiredTools = $this->option('shopping')
            ? ['recommend_products', 'check_stock']
            : ['check_stock'];
        $usedTools = collect($run?->tools_used ?? [])->pluck('name');
        $healthy = $run?->status === 'completed'
            && ! ($reply['handoff'] ?? true)
            && ($reply['intent'] ?? 'handoff') !== 'handoff'
            && ! $usedTools->contains('server_guardrail')
            && collect($requiredTools)->every(fn (string $tool): bool => $usedTools->contains($tool));

        if (! $healthy) {
            $this->error('OpenAI responded, but the verified golden path did not complete safely. Review the trace above.');
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
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
