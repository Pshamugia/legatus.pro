<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\EvaluationRun;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RecommendationEvent;
use App\Services\TenantContext;

class AnalyticsController extends Controller
{
    public function index(TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $customerConversations = $agent->conversations()->where('channel', '!=', 'eval');
        $total = (clone $customerConversations)->count();
        $handoffs = (clone $customerConversations)->whereNotNull('handoff_reason')->count();
        $assistantMessages = Message::whereHas('conversation', fn ($query) => $query
            ->where('agent_id', $agent->id)
            ->where('channel', '!=', 'eval'))->where('role', 'assistant');
        $qualifiedLeads = Lead::where('agent_id', $agent->id)
            ->whereIn('status', ['qualified', 'won'])
            ->where(function ($query) use ($customerConversations) {
                $query->whereNull('conversation_id')
                    ->orWhereIn('conversation_id', (clone $customerConversations)->select('conversations.id'));
            });
        $customerRuns = AgentRun::where('agent_id', $agent->id)
            ->whereIn('conversation_id', (clone $customerConversations)->select('conversations.id'));
        $rated = (clone $assistantMessages)->whereNotNull('feedback')->count();
        $helpful = (clone $assistantMessages)->where('feedback', 'helpful')->count();
        $metrics = [
            'conversations' => $total,
            'automation_rate' => $total ? round((($total - $handoffs) / $total) * 100) : 0,
            'handoffs' => $handoffs,
            'qualified_leads' => $qualifiedLeads->count(),
            'recommendations' => RecommendationEvent::whereHas('conversation', fn ($query) => $query
                ->where('agent_id', $agent->id)
                ->where('channel', '!=', 'eval'))->count(),
            'input_tokens' => (clone $customerRuns)->sum('input_tokens'),
            'output_tokens' => (clone $customerRuns)->sum('output_tokens'),
            'avg_latency' => round((float) (clone $customerRuns)->where('status', 'completed')->avg('latency_ms')),
            'helpfulness_rate' => $rated ? round($helpful / $rated * 100) : null,
            'revenue_influenced' => (float) (clone $customerConversations)->sum('outcome_value'),
            'simulated_runs' => (clone $customerRuns)->where('response_id', 'like', 'demo-trace-%')->count(),
        ];
        $runs = (clone $customerRuns)->latest()->take(20)->get();
        $eval = EvaluationRun::where('agent_id', $agent->id)->latest()->first();

        return view('analytics', compact('agent', 'metrics', 'runs', 'eval'));
    }
}
