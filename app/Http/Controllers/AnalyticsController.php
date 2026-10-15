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
        $unhelpful = (clone $assistantMessages)->where('feedback', 'unhelpful')->count();
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
            'rated_responses' => $rated,
            'helpful_responses' => $helpful,
            'unhelpful_responses' => $unhelpful,
            'revenue_influenced' => (float) (clone $customerConversations)->sum('outcome_value'),
            'simulated_runs' => (clone $customerRuns)->where('response_id', 'like', 'demo-trace-%')->count(),
        ];
        $runs = (clone $customerRuns)->latest()->take(20)->get();
        $recentFeedback = (clone $assistantMessages)
            ->whereNotNull('feedback')
            ->with('conversation:id,agent_id,customer_name,channel')
            ->latest('messages.updated_at')
            ->take(20)
            ->get();
        $eval = EvaluationRun::where('agent_id', $agent->id)->latest()->first();

        $knowledgeSources = $agent->knowledgeSources()
            ->select(['id', 'name', 'source_scope', 'status', 'progress', 'error', 'last_synced_at', 'updated_at'])
            ->latest('updated_at')
            ->get();
        $knowledgeIssues = $knowledgeSources->filter(fn ($source): bool =>
            $source->status === 'failed'
            || filled($source->error)
            || ($source->status === 'processing' && $source->updated_at?->isBefore(now()->subMinutes(30)))
            || ($source->status === 'ready' && (int) $source->progress < 100)
        )->take(10)->values();
        $degradedRuns = (clone $customerRuns)
            ->whereIn('status', ['failed', 'fallback', 'moderated'])
            ->latest('id')
            ->take(10)
            ->get();
        $quality = [
            'knowledge_sources' => $knowledgeSources->count(),
            'knowledge_issues' => $knowledgeIssues->count(),
            'degraded_runs' => (clone $customerRuns)->whereIn('status', ['failed', 'fallback', 'moderated'])->count(),
            'low_confidence_responses' => (clone $assistantMessages)->whereNotNull('confidence')->where('confidence', '<', .6)->count(),
            'products_missing_description' => $agent->products()->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count(),
            'products_missing_image' => $agent->products()->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('image')->orWhere('image', ''))->count(),
        ];

        return view('analytics', compact(
            'agent',
            'metrics',
            'runs',
            'recentFeedback',
            'eval',
            'quality',
            'knowledgeIssues',
            'degradedRuns',
        ));
    }
}
