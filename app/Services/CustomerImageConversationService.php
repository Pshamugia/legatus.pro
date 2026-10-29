<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\PrivacyRedactor;

class CustomerImageConversationService
{
    public function __construct(private readonly CustomerImageCatalogMatcher $matcher) {}

    /** @return array<string, mixed> */
    public function handle(
        Agent $agent,
        string $bytes,
        string $customerText,
        string $channel,
        string $visitorId,
        ?string $requestId = null,
    ): array {
        $existing = $requestId ? Message::query()
            ->where('role', 'customer')
            ->where('request_id', $requestId)
            ->whereHas('conversation', fn ($query) => $query
                ->where('agent_id', $agent->id)
                ->where('visitor_id', $visitorId))
            ->first() : null;
        if (is_array(data_get($existing?->metadata, 'response_payload'))) {
            return data_get($existing->metadata, 'response_payload');
        }

        $conversation = $existing?->conversation ?? $agent->conversations()
            ->where('visitor_id', $visitorId)
            ->where('channel', $channel)
            ->whereIn('status', ['ai', 'open', 'human'])
            ->latest('id')
            ->first() ?? $agent->conversations()->create([
                'visitor_id' => $visitorId,
                'customer_name' => 'Website visitor',
                'channel' => $channel,
                'status' => 'ai',
            ]);
        $caption = trim($customerText);
        $customer = $existing ?? $conversation->messages()->create([
            'role' => 'customer',
            'content' => PrivacyRedactor::text($caption !== '' ? $caption : '[Customer sent an image.]'),
            'request_id' => $requestId,
            'metadata' => ['attachment_received' => true, 'image_received' => true],
        ]);
        $conversation->update(['last_message_at' => now()]);

        if ($conversation->status === 'human') {
            return $this->remember($customer, [
                'text' => null,
                'intent' => 'handoff',
                'confidence' => 1,
                'handoff' => true,
                'products' => [],
                'sources' => [],
                'tools_used' => ['human_queue'],
                'customer_message_id' => $customer->public_id,
                'cursor' => $customer->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $started = microtime(true);
        try {
            $result = $this->matcher->resolveBytes($agent, $conversation, $bytes, $caption);
        } catch (\Throwable $exception) {
            report($exception);
            $georgian = preg_match('/[\x{10A0}-\x{10FF}]/u', $caption) === 1;
            $result = [
                'status' => 'failed',
                'text' => $georgian
                    ? 'ფოტოს დამუშავება ამჯერად ვერ მოხერხდა. შეგიძლიათ მოგვიანებით ხელახლა სცადოთ ან პროდუქტის დასახელება ტექსტად მოგვწეროთ.'
                    : 'I could not process the photo reliably this time. Please try again later or send the product name as text.',
                'products' => [],
                'product_ids' => [],
                'tools_used' => [],
                'model' => (string) config('services.openai.image_recognition_model', 'gpt-5.6-sol'),
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
                'error_code' => CustomerImageCatalogMatcher::failureCode($exception),
            ];
        }

        $conversation->refresh();
        if ($conversation->status === 'human') {
            return $this->remember($customer, [
                'text' => null,
                'intent' => 'handoff',
                'confidence' => 1,
                'handoff' => true,
                'products' => [],
                'sources' => [],
                'tools_used' => ['human_queue'],
                'customer_message_id' => $customer->public_id,
                'cursor' => $customer->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $status = (string) ($result['status'] ?? 'failed');
        $confidence = in_array($status, ['available', 'unavailable', 'not_found'], true) ? 1.0 : .8;
        $toolNames = collect($result['tools_used'] ?? [])->pluck('name')->filter()->unique()->values()->all();
        $assistant = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => PrivacyRedactor::text((string) $result['text']),
            'confidence' => $confidence,
            'metadata' => [
                'intent' => 'image_product_lookup',
                'products' => $result['products'] ?? [],
                'tools_used' => $toolNames,
                'image_recognition_status' => $status,
                'image_recognition_model' => $result['model'] ?? null,
                'image_recognition_failed' => $status === 'failed',
                'request_id' => $requestId,
            ],
        ]);
        $productIds = collect($result['product_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $context = (array) $conversation->context;
        if ($productIds->isNotEmpty()) {
            $context['last_catalog_product_ids'] = $productIds->all();
        }
        $conversation->update([
            'context' => $context,
            'intent' => 'image_product_lookup',
            'last_message_at' => now(),
        ]);

        $usage = (array) ($result['usage'] ?? []);
        AgentRun::create([
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'model' => (string) ($result['model'] ?? config('services.openai.image_recognition_model')),
            'route' => 'image_catalog_match',
            'status' => $status === 'failed' ? 'failed' : 'completed',
            'tools_used' => $result['tools_used'] ?? [],
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            'error' => $status === 'failed' ? ($result['error_code'] ?? 'image_recognition_unknown_failure') : null,
        ]);

        return $this->remember($customer, [
            'text' => $assistant->content,
            'intent' => 'image_product_lookup',
            'confidence' => $confidence,
            'handoff' => false,
            'products' => $result['products'] ?? [],
            'sources' => [],
            'tools_used' => $toolNames,
            'message_id' => $assistant->public_id,
            'customer_message_id' => $customer->public_id,
            'cursor' => $assistant->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function remember(Message $customer, array $payload): array
    {
        $metadata = (array) $customer->metadata;
        $metadata['response_payload'] = $payload;
        $customer->update(['metadata' => $metadata]);

        return $payload;
    }
}
