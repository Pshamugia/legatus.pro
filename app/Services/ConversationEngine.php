<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reservation;
use App\Support\PrivacyRedactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Channel-neutral conversation runtime.
 *
 * Website, Messenger, Instagram, and future transports must all enter here so
 * they share the same grounding, guardrails, handoff, and audit trail.
 */
class ConversationEngine
{
    public function __construct(private SalesAgentService $salesAgent) {}

    public function handle(
        Agent $agent,
        string $text,
        string $channel,
        string $customerId,
        ?string $requestId = null,
        ?string $customerName = null,
    ): array {
        $customerMessage = $requestId === null ? null : Message::query()
            ->where('role', 'customer')
            ->where('request_id', $requestId)
            ->whereHas('conversation', fn ($query) => $query
                ->where('agent_id', $agent->id)
                ->where('visitor_id', $customerId))
            ->with('conversation')
            ->latest('id')
            ->first();

        if ($customerMessage && ($replay = $this->replayResponse($customerMessage, $requestId))) {
            return $replay;
        }

        $conversation = $customerMessage?->conversation ?? $agent->conversations()
            ->where('visitor_id', $customerId)
            ->where('channel', $channel)
            ->whereIn('status', ['ai', 'open', 'human'])
            ->latest('id')
            ->first() ?? $agent->conversations()->create([
                'visitor_id' => $customerId,
                'status' => 'ai',
                'channel' => $channel,
                'customer_name' => $customerName ?: $this->defaultCustomerName($channel),
            ]);

        if (! $customerMessage) {
            try {
                $redactedText = PrivacyRedactor::text($text);
                $messageMetadata = [
                    'contact_evidence' => PrivacyRedactor::contactEvidence($text),
                ];
                if ($redactedText !== $text) {
                    $messageMetadata['pii_redacted'] = true;
                }
                $customerMessage = $conversation->messages()->create([
                    'role' => 'customer',
                    'content' => $redactedText,
                    'request_id' => $requestId,
                    'metadata' => $messageMetadata,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($requestId === null) {
                    throw $exception;
                }

                $customerMessage = $conversation->messages()
                    ->where('role', 'customer')
                    ->where('request_id', $requestId)
                    ->firstOrFail();

                if ($replay = $this->replayResponse($customerMessage, $requestId)) {
                    return $replay;
                }
            }
        }

        if ($conversation->status === 'human' && $this->isRecoverableTechnicalHandoff($conversation)) {
            $conversation->update([
                'status' => 'ai',
                'handoff_reason' => null,
                'handoff_summary' => null,
                'suggested_reply' => null,
                'assigned_to' => null,
                'outcome' => null,
            ]);
            $conversation->refresh();
        }

        if ($conversation->status === 'human') {
            $conversation->update(['last_message_at' => now()]);

            return $this->rememberResponse($customerMessage, [
                'text' => 'შეტყობინება მიღებულია — ოპერატორი უკვე ჩართულია და ამავე საუბარში გიპასუხებთ.',
                'intent' => 'handoff',
                'confidence' => 1,
                'handoff' => true,
                'escalation_reason' => $conversation->handoff_reason,
                'products' => [],
                'sources' => [],
                'tools_used' => ['human_queue'],
                'customer_message_id' => $customerMessage->public_id,
                'cursor' => $customerMessage->id,
                'request_id' => $requestId,
                'conversation_id' => $conversation->id,
            ]);
        }

        $reply = $this->salesAgent->reply($agent, $text, $conversation);
        $reply['text'] = PrivacyRedactor::text($reply['text']);
        $reply['products'] = $this->publicProducts($reply['products'] ?? [], $conversation);
        if ($reply['products'] !== []) {
            $context = is_array($conversation->context) ? $conversation->context : [];
            $context['last_catalog_product_ids'] = collect($reply['products'])->pluck('id')->filter()->values()->all();
            $conversation->context = $context;
        }

        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply['text'],
            'confidence' => $reply['confidence'],
            'metadata' => [
                'intent' => $reply['intent'],
                'sources' => $reply['sources'] ?? [],
                'tools_used' => $reply['tools_used'] ?? [],
                'escalation_reason' => $reply['escalation_reason'] ?? null,
                'products' => $reply['products'],
                'handoff' => (bool) $reply['handoff'],
                'request_id' => $requestId,
            ],
        ]);
        $conversation->update([
            'intent' => $reply['intent'],
            'status' => $reply['handoff'] ? 'human' : 'ai',
            'last_message_at' => now(),
        ]);

        return $this->rememberResponse($customerMessage, $reply + [
            'message_id' => $assistant->public_id,
            'customer_message_id' => $customerMessage->public_id,
            'cursor' => $assistant->id,
            'request_id' => $requestId,
            'conversation_id' => $conversation->id,
        ]);
    }

    private function replayResponse(Message $customerMessage, string $requestId): ?array
    {
        $snapshot = data_get($customerMessage->metadata, 'response_payload');
        if (is_array($snapshot)) {
            return array_replace($snapshot, ['request_id' => $requestId]);
        }

        $assistant = $customerMessage->conversation->messages()
            ->where('role', 'assistant')
            ->where('metadata->request_id', $requestId)
            ->latest('id')
            ->first();

        if (! $assistant) {
            return null;
        }

        $metadata = $assistant->metadata ?? [];

        return $this->rememberResponse($customerMessage, [
            'text' => $assistant->content,
            'intent' => $metadata['intent'] ?? $customerMessage->conversation->intent ?? 'answer',
            'confidence' => $assistant->confidence,
            'handoff' => (bool) ($metadata['handoff'] ?? false),
            'escalation_reason' => $metadata['escalation_reason'] ?? null,
            'products' => $metadata['products'] ?? [],
            'sources' => $metadata['sources'] ?? [],
            'tools_used' => $metadata['tools_used'] ?? [],
            'message_id' => $assistant->public_id,
            'customer_message_id' => $customerMessage->public_id,
            'cursor' => $assistant->id,
            'request_id' => $requestId,
            'conversation_id' => $customerMessage->conversation_id,
        ]);
    }

    private function rememberResponse(Message $customerMessage, array $payload): array
    {
        $customerMessage->update([
            'metadata' => array_merge($customerMessage->metadata ?? [], [
                'response_payload' => $payload,
            ]),
        ]);

        return $payload;
    }

    private function publicProducts(iterable $products, Conversation $conversation): array
    {
        $products = collect($products)->values();
        $ids = $products->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique();
        $heldByOthers = Reservation::query()
            ->whereIn('product_id', $ids)
            ->where('conversation_id', '!=', $conversation->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->selectRaw('product_id, SUM(quantity) as held_quantity')
            ->groupBy('product_id')
            ->pluck('held_quantity', 'product_id');

        return $products->map(function ($product) use ($heldByOthers): array {
            $id = (int) data_get($product, 'id');
            $stock = max(0, (int) data_get($product, 'stock', 0) - (int) ($heldByOthers[$id] ?? 0));

            return [
                'id' => $id,
                'name' => data_get($product, 'name'),
                'price' => (float) data_get($product, 'price', 0),
                'stock' => $stock,
                'image' => data_get($product, 'image'),
                'url' => data_get($product, 'url')
                    ?: data_get($product, 'metadata.product_url')
                    ?: data_get($product, 'metadata.url'),
                'original_price' => ($original = data_get($product, 'metadata.original_price')) !== null ? (float) $original : null,
                'discount_percent' => ($discount = data_get($product, 'metadata.discount_percent')) !== null ? (float) $discount : null,
            ];
        })->values()->all();
    }

    private function isRecoverableTechnicalHandoff(Conversation $conversation): bool
    {
        if ($conversation->assigned_to !== null) {
            return false;
        }

        return Str::contains(Str::lower((string) $conversation->handoff_reason), [
            'required verification tool was not called',
            'catalog_search_mismatch',
            'the ai provider or verification workflow failed safely',
            'the verified ai service is unavailable',
        ]);
    }

    private function defaultCustomerName(string $channel): string
    {
        return match ($channel) {
            'facebook' => 'Facebook customer',
            'instagram' => 'Instagram customer',
            'widget' => 'Website visitor',
            default => 'Website visitor',
        };
    }
}
