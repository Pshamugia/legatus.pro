<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use App\Models\CommerceAttribution;
use App\Models\CommerceAttributionOrder;
use App\Models\EvaluationRun;
use App\Models\Lead;
use App\Models\Message;
use App\Models\RecommendationEvent;
use App\Services\TenantContext;
use Illuminate\Support\Str;

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
        $businessCurrency = strtoupper((string) data_get(
            $agent->commerceConnection()->first()?->settings,
            'currency',
            data_get($agent->organization?->settings, 'currency', 'GEL'),
        ));
        $attributedOrders = CommerceAttributionOrder::query()
            ->where('agent_id', $agent->id)
            ->where('currency', $businessCurrency)
            ->where('status', '!=', 'cancelled')
            ->with([
                'attribution:id,source_type,source_id,channel,product_id,conversation_id,click_count',
                'attribution.product:id,name',
                'attribution.conversation:id,customer_name',
            ])
            ->get();
        $attributionGroups = $attributedOrders
            ->groupBy(fn ($order): string => ($order->attribution?->source_type ?: 'unknown').'|'.($order->attribution?->channel ?: ''))
            ->map(function ($orders, string $key): array {
                [$source, $channel] = array_pad(explode('|', $key, 2), 2, '');

                return [
                    'source' => $source,
                    'channel' => $channel,
                    'orders' => $orders->count(),
                    'revenue' => $orders->sum(fn ($order): float => max(0, (float) $order->gross_amount - (float) $order->refunded_amount)),
                ];
            })->sortByDesc('revenue')->values();
        $attributionCampaigns = $attributedOrders
            ->groupBy('commerce_attribution_id')
            ->map(function ($orders): array {
                $attribution = $orders->first()->attribution;
                $source = $attribution?->source_type ?: 'unknown';
                $sourceId = (int) ($attribution?->source_id ?: 0);
                $name = $attribution?->product?->name;

                return [
                    'source' => $source,
                    'source_id' => $sourceId,
                    'channel' => $attribution?->channel,
                    'name' => $name ?: ($source === 'chat'
                        ? ($attribution?->conversation?->customer_name ?: 'Customer conversation')
                        : null),
                    'orders' => $orders->count(),
                    'revenue' => $orders->sum(fn ($order): float => max(0, (float) $order->gross_amount - (float) $order->refunded_amount)),
                    'clicks' => (int) ($attribution?->click_count ?: 0),
                    'url' => match ($source) {
                        'chat' => $attribution?->conversation_id ? route('inbox.index', ['conversation' => $attribution->conversation_id]) : null,
                        'social_post' => route('social-media.index'),
                        'reel' => route('ai-reels.index'),
                        default => null,
                    },
                ];
            })
            ->sortByDesc('revenue')
            ->take(20)
            ->values();
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
            'attributed_orders' => $attributedOrders->count(),
            'attributed_revenue' => $attributedOrders->sum(fn ($order): float => max(0, (float) $order->gross_amount - (float) $order->refunded_amount)),
            'attribution_clicks' => (int) CommerceAttribution::query()->where('agent_id', $agent->id)->sum('click_count'),
            'attribution_currency' => $businessCurrency,
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
        $knowledgeIssues = $knowledgeSources->filter(fn ($source): bool => $source->status === 'failed'
            || filled($source->error)
            || ($source->status === 'processing' && $source->updated_at?->isBefore(now()->subMinutes(30)))
            || ($source->status === 'ready' && (int) $source->progress < 100)
        )->take(10)->values();
        $degradedRuns = (clone $customerRuns)
            ->whereIn('status', ['failed', 'fallback', 'moderated'])
            ->latest('id')
            ->take(10)
            ->get();
        $qualityIssues = collect();
        foreach ($recentFeedback->where('feedback', 'unhelpful')->take(10) as $message) {
            $question = Message::query()
                ->where('conversation_id', $message->conversation_id)
                ->where('role', 'customer')
                ->where('id', '<', $message->id)
                ->latest('id')
                ->value('content');
            $qualityIssues->push([
                'type' => 'incorrect_answer',
                'label' => 'Needs review',
                'title' => 'A customer marked this answer as unhelpful',
                'detail' => Str::limit($question ?: $message->content, 180),
                'suggestion' => $question
                    ? 'Review the answer and add or correct verified business information that directly answers this question.'
                    : 'Review the answer in context and add the missing verified fact to Knowledge if needed.',
                'conversation_id' => $message->conversation_id,
            ]);
        }
        $reliabilityInterventions = (clone $assistantMessages)
            ->latest('id')
            ->take(100)
            ->get()
            ->filter(fn (Message $message): bool => (array) data_get($message->metadata, 'reliability_issues', []) !== []);
        foreach ($reliabilityInterventions->take(10) as $message) {
            $issues = (array) data_get($message->metadata, 'reliability_issues', []);
            $qualityIssues->push([
                'type' => 'reliability_intervention',
                'label' => 'Prevented',
                'title' => 'An unsafe or malformed answer was corrected before delivery',
                'detail' => Str::limit(implode(', ', $issues), 180),
                'suggestion' => 'Review the conversation and trace. Add a regression case if this input is not already covered.',
                'conversation_id' => $message->conversation_id,
            ]);
        }
        $recentConversations = $agent->conversations()
            ->where('channel', '!=', 'eval')
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', '!=', 'no_reply_needed'))
            ->with(['messages' => fn ($query) => $query->select(['id', 'conversation_id', 'role', 'content', 'metadata', 'created_at'])->orderBy('id')])
            ->latest('last_message_at')
            ->take(100)
            ->get();
        foreach ($recentConversations as $conversation) {
            $last = $conversation->messages->last();
            if (! $last
                || $last->role !== 'customer'
                || $last->created_at?->isAfter(now()->subMinutes(5))
                || data_get($last->metadata, 'automated_reply_suppressed') === true
                || data_get($last->metadata, 'public_comment_gate') === 'silent') {
                continue;
            }
            $qualityIssues->push([
                'type' => 'unanswered_question',
                'label' => 'Unanswered',
                'title' => 'A customer message has no later reply',
                'detail' => Str::limit($last->content, 180),
                'suggestion' => data_get($last->metadata, 'automatic_processing_failure')
                    ? 'Open the conversation, answer the customer, and review the recorded processing failure.'
                    : 'Open the conversation and add a verified answer to Knowledge if the information is missing.',
                'conversation_id' => $conversation->id,
            ]);
            if ($qualityIssues->count() >= 20) {
                break;
            }
        }
        $qualityIssues = $qualityIssues->take(20)->values();
        $quality = [
            'knowledge_sources' => $knowledgeSources->count(),
            'knowledge_issues' => $knowledgeIssues->count(),
            'degraded_runs' => (clone $customerRuns)->whereIn('status', ['failed', 'fallback', 'moderated'])->count(),
            'low_confidence_responses' => (clone $assistantMessages)->whereNotNull('confidence')->where('confidence', '<', .6)->count(),
            'products_missing_description' => $agent->products()->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('description')->orWhere('description', ''))->count(),
            'products_missing_image' => $agent->products()->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('image')->orWhere('image', ''))->count(),
            'actionable_issues' => $qualityIssues->count(),
            'reliability_interventions' => $reliabilityInterventions->count(),
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
            'qualityIssues',
            'attributionGroups',
            'attributionCampaigns',
        ));
    }
}
