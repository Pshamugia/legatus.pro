<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * Final channel-neutral contract enforced after every response producer.
 *
 * Model prompts and provider-specific guards improve quality, but they are not
 * an authorization boundary. This class is the last server-owned checkpoint
 * before a reply is persisted or dispatched to a customer.
 */
final class ResponseReliabilityGuard
{
    private const INTENTS = [
        'conversation', 'clarification', 'discovery', 'price', 'stock',
        'delivery', 'recommendation', 'wholesale', 'lead', 'reservation',
        'offer', 'handoff',
    ];

    private const PRODUCT_EVIDENCE_TOOLS = [
        'search_products', 'recommend_products', 'check_stock',
        'remember_recent_products', 'guided_recommendation',
    ];

    public function enforce(
        Agent $agent,
        Conversation $conversation,
        string $customerMessage,
        array $reply,
        bool $synthetic = false,
    ): array {
        if (($reply['silent'] ?? false) === true) {
            return $reply;
        }

        $issues = [];
        $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $customerMessage) === 1;
        $text = trim((string) ($reply['text'] ?? ''));
        $intent = (string) ($reply['intent'] ?? '');

        if (! in_array($intent, self::INTENTS, true)) {
            $issues[] = 'invalid_intent';
            $intent = 'discovery';
        }

        if ($text === '') {
            $issues[] = 'empty_text';
            $text = $georgian
                ? 'რომელი პროდუქტი ან საჭიროება გაქვთ მხედველობაში?'
                : 'Which product or need do you have in mind?';
            $intent = 'clarification';
        }

        $handoff = (bool) ($reply['handoff'] ?? false);
        if ($handoff && $intent !== 'handoff') {
            $issues[] = 'handoff_intent_mismatch';
            $intent = 'handoff';
        } elseif (! $handoff && $intent === 'handoff') {
            $issues[] = 'orphan_handoff_intent';
            $intent = 'discovery';
        }

        $tools = collect($reply['tools_used'] ?? [])
            ->map(fn ($tool): string => is_array($tool) ? (string) ($tool['name'] ?? '') : (string) $tool)
            ->filter()
            ->unique()
            ->values();
        $products = collect($reply['products'] ?? [])->values();

        if ($products->isNotEmpty()) {
            $requestedIds = $products->map(fn ($product): int => (int) data_get($product, 'id'))
                ->filter()->unique()->values();
            $allowedIds = $agent->customerProducts()->where('is_active', true)->whereIn('id', $requestedIds)->pluck('id')
                ->map(fn ($id): int => (int) $id);
            $filtered = $products->filter(fn ($product): bool => $allowedIds->contains((int) data_get($product, 'id')))
                ->values();

            if ($filtered->count() !== $products->count()) {
                $issues[] = 'cross_tenant_or_inactive_product';
            }
            $products = $filtered;

            if (! $tools->intersect(self::PRODUCT_EVIDENCE_TOOLS)->count()) {
                $issues[] = 'product_without_tool_evidence';
                $products = collect();
            }
        }

        if ($intent === 'clarification') {
            if ($products->isNotEmpty()) {
                $issues[] = 'clarification_with_products';
                $products = collect();
            }

            $questionCount = preg_match_all('/[?？؟]/u', $text);
            if ($questionCount > 1) {
                $issues[] = 'invalid_clarification_question_count';
                $firstQuestion = trim((string) preg_split('/[?？؟]/u', $text, 2)[0]);
                $text = $firstQuestion !== ''
                    ? $firstQuestion.'?'
                    : ($georgian
                        ? 'რომელი პროდუქტი ან საჭიროება გაქვთ მხედველობაში?'
                        : 'Which product or need do you have in mind?');
            }
        }

        if ($issues !== []) {
            $tools->push('reliability_guard');
            if (! $synthetic) {
                Log::warning('Customer reply corrected by the reliability guard.', [
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                    'channel' => $conversation->channel,
                    'issues' => $issues,
                ]);
            }
        }

        return array_replace($reply, [
            'text' => $text,
            'intent' => $intent,
            'confidence' => max(0.0, min(1.0, (float) ($reply['confidence'] ?? 0))),
            'handoff' => $handoff,
            'escalation_reason' => $handoff ? ($reply['escalation_reason'] ?? 'Human review requested.') : null,
            'products' => $products->all(),
            'sources' => is_array($reply['sources'] ?? null) ? $reply['sources'] : [],
            'tools_used' => $tools->unique()->values()->all(),
            'reliability_issues' => $issues,
        ]);
    }
}
