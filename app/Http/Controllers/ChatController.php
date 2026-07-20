<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reservation;
use App\Services\SalesAgentService;
use App\Services\TenantContext;
use App\Support\PrivacyRedactor;
use App\Support\SignedVisitorToken;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatController extends Controller
{
    public function show(Agent $agent)
    {
        $this->ensureActive($agent);

        return view('chat', compact('agent'));
    }

    public function message(Request $request, Agent $agent, SalesAgentService $service, SignedVisitorToken $tokens)
    {
        $this->ensureActive($agent);
        $data = $request->validate([
            'message' => 'required|string|max:1500',
            'visitor_token' => 'nullable|string|max:512',
            'request_id' => 'nullable|uuid',
            'channel' => 'nullable|in:web,widget',
        ]);

        $providedToken = $this->providedToken($request);
        if ($providedToken !== null) {
            $visitorId = $tokens->resolve($agent, $providedToken);
            abort_unless($visitorId !== null, 401, 'Invalid or expired visitor token.');
            $visitorToken = $providedToken;
        } else {
            $identity = $tokens->issue($agent);
            $visitorId = $identity['visitor_id'];
            $visitorToken = $identity['token'];
        }

        $respond = fn (): array => Cache::lock('public-chat-conversation:'.$agent->id.':'.hash('sha256', $visitorId), 60)
            ->block(10, fn (): array => $this->processMessage(
                $agent,
                $service,
                $data['message'],
                $data['channel'] ?? 'web',
                $visitorId,
                $visitorToken,
                $data['request_id'] ?? null,
            ));

        if (! isset($data['request_id'])) {
            try {
                return $this->privateJson($respond());
            } catch (LockTimeoutException) {
                return $this->privateJson(['message' => 'Another message in this conversation is still being processed.'], 409);
            }
        }

        $cacheKey = 'public-chat-response:'.$agent->getKey().':'.hash('sha256', $visitorId).':'.$data['request_id'];
        if ($cached = Cache::get($cacheKey)) {
            return $this->privateJson($this->ownedResponse($cached, $visitorToken, $data['request_id']));
        }

        try {
            $payload = Cache::lock($cacheKey.':lock', 60)->block(10, function () use ($cacheKey, $respond, $visitorToken, $data): array {
                if ($cached = Cache::get($cacheKey)) {
                    return $this->ownedResponse($cached, $visitorToken, $data['request_id']);
                }

                $payload = $respond();
                Cache::put($cacheKey, $this->responseSnapshot($payload), now()->addMinutes(15));

                return $payload;
            });
        } catch (LockTimeoutException) {
            return $this->privateJson([
                'message' => 'This request is already being processed. Retry with the same request_id.',
                'request_id' => $data['request_id'],
            ], 409);
        }

        return $this->privateJson($payload);
    }

    public function history(Request $request, Agent $agent, SignedVisitorToken $tokens)
    {
        $this->ensureActive($agent);
        $data = $request->validate([
            'after' => 'nullable|integer|min:0',
        ]);
        $visitorId = $tokens->resolve($agent, $this->providedToken($request));
        abort_unless($visitorId !== null, 401, 'Invalid or expired visitor token.');

        $conversation = $agent->conversations()->where('visitor_id', $visitorId)->latest('id')->first();
        $cursor = (int) ($data['after'] ?? 0);
        if (! $conversation) {
            return $this->privateJson([
                'messages' => [],
                'cursor' => $cursor,
                'status' => null,
            ]);
        }

        $messages = $conversation->messages()
            ->where('id', '>', $cursor)
            ->whereIn('role', ['customer', 'assistant', 'human'])
            ->orderBy('id')
            ->limit(100)
            ->get();

        return $this->privateJson([
            'messages' => $messages->map(fn (Message $message): array => $this->publicMessage($message))->values(),
            'cursor' => (int) ($messages->last()?->id ?? $cursor),
            'status' => $conversation->status,
        ]);
    }

    public function feedback(Request $request, Agent $agent, string $message, SignedVisitorToken $tokens)
    {
        $this->ensureActive($agent);
        $data = $request->validate([
            'feedback' => 'required|in:helpful,unhelpful',
            'visitor_token' => 'nullable|string|max:512',
        ]);
        $visitorId = $tokens->resolve($agent, $this->providedToken($request));
        abort_unless($visitorId !== null, 401, 'Invalid or expired visitor token.');

        $conversation = $agent->conversations()
            ->where('visitor_id', $visitorId)
            ->withWhereHas('messages', fn ($query) => $query->where('public_id', $message))
            ->firstOrFail();
        $record = $conversation->messages->firstWhere('public_id', $message);
        abort_unless($record?->role === 'assistant', 404);
        $record->update(['feedback' => $data['feedback']]);

        return $this->privateJson(['ok' => true]);
    }

    public function handoff(Conversation $conversation, TenantContext $tenant)
    {
        abort_unless($conversation->agent_id === $tenant->agent()->id, 404);
        $tenant->authorize(['owner', 'admin', 'agent']);
        $conversation->update(['status' => 'human', 'handoff_reason' => $conversation->handoff_reason ?: 'Manual operator takeover.', 'outcome' => 'human_handoff', 'last_message_at' => now()]);

        return back();
    }

    private function processMessage(
        Agent $agent,
        SalesAgentService $service,
        string $text,
        string $channel,
        string $visitorId,
        string $visitorToken,
        ?string $requestId,
    ): array {
        $customerMessage = $requestId === null ? null : Message::query()
            ->where('role', 'customer')
            ->where('request_id', $requestId)
            ->whereHas('conversation', fn ($query) => $query
                ->where('agent_id', $agent->id)
                ->where('visitor_id', $visitorId))
            ->with('conversation')
            ->latest('id')
            ->first();

        if ($customerMessage && ($replay = $this->replayResponse($customerMessage, $visitorToken, $requestId))) {
            return $replay;
        }

        $conversation = $customerMessage?->conversation ?? $agent->conversations()
            ->where('visitor_id', $visitorId)
            ->whereIn('status', ['ai', 'open', 'human'])
            ->latest('id')
            ->first() ?? $agent->conversations()->create([
                'visitor_id' => $visitorId,
                'status' => 'ai',
                'channel' => $channel,
                'customer_name' => 'Website visitor',
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

                if ($replay = $this->replayResponse($customerMessage, $visitorToken, $requestId)) {
                    return $replay;
                }
            }
        }

        if ($conversation->status === 'human') {
            $conversation->update(['last_message_at' => now()]);

            $payload = [
                'text' => 'შეტყობინება მიღებულია — ოპერატორი უკვე ჩართულია და ამავე საუბარში გიპასუხებთ.',
                'intent' => 'handoff',
                'confidence' => 1,
                'handoff' => true,
                'escalation_reason' => $conversation->handoff_reason,
                'products' => [],
                'sources' => [],
                'tools_used' => ['human_queue'],
                'visitor_token' => $visitorToken,
                'customer_message_id' => $customerMessage->public_id,
                'cursor' => $customerMessage->id,
                'request_id' => $requestId,
            ];

            return $this->rememberResponse($customerMessage, $payload);
        }

        $reply = $service->reply($agent, $text, $conversation);
        $reply['text'] = PrivacyRedactor::text($reply['text']);
        $reply['products'] = $this->publicProducts($reply['products'] ?? [], $conversation);

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

        $payload = $reply + [
            'visitor_token' => $visitorToken,
            'message_id' => $assistant->public_id,
            'customer_message_id' => $customerMessage->public_id,
            'cursor' => $assistant->id,
            'request_id' => $requestId,
        ];

        return $this->rememberResponse($customerMessage, $payload);
    }

    private function replayResponse(Message $customerMessage, string $visitorToken, string $requestId): ?array
    {
        $snapshot = data_get($customerMessage->metadata, 'response_payload');
        if (is_array($snapshot)) {
            return $this->ownedResponse($snapshot, $visitorToken, $requestId);
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
        $payload = [
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
        ];

        return $this->rememberResponse(
            $customerMessage,
            $this->ownedResponse($payload, $visitorToken, $requestId),
        );
    }

    private function rememberResponse(Message $customerMessage, array $payload): array
    {
        $customerMessage->update([
            'metadata' => array_merge($customerMessage->metadata ?? [], [
                'response_payload' => $this->responseSnapshot($payload),
            ]),
        ]);

        return $payload;
    }

    private function responseSnapshot(array $payload): array
    {
        unset($payload['visitor_token']);

        return $payload;
    }

    private function ownedResponse(array $snapshot, string $visitorToken, ?string $requestId): array
    {
        return array_replace($snapshot, [
            'visitor_token' => $visitorToken,
            'request_id' => $requestId,
        ]);
    }

    private function publicMessage(Message $message): array
    {
        return [
            'id' => $message->public_id,
            'cursor' => $message->id,
            'role' => $message->role,
            'text' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
            'confidence' => $message->confidence,
            'products' => $message->metadata['products'] ?? [],
            'sources' => $message->metadata['sources'] ?? [],
            'tools_used' => $message->metadata['tools_used'] ?? [],
            'escalation_reason' => $message->metadata['escalation_reason'] ?? null,
        ];
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
            ];
        })->values()->all();
    }

    private function providedToken(Request $request): ?string
    {
        $token = $request->header('X-Legatus-Visitor-Token') ?: $request->bearerToken();
        if (! $request->isMethod('GET')) {
            $token ??= $request->input('visitor_token');
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function privateJson(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }

    private function ensureActive(Agent $agent): void
    {
        abort_unless($agent->is_active, 404);
    }
}
