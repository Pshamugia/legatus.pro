<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationEngine;
use App\Services\TenantContext;
use App\Support\SignedVisitorToken;
use Illuminate\Cache\LockTimeoutException;
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

    public function message(Request $request, Agent $agent, ConversationEngine $engine, SignedVisitorToken $tokens)
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
                $engine,
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
        ConversationEngine $engine,
        string $text,
        string $channel,
        string $visitorId,
        string $visitorToken,
        ?string $requestId,
    ): array {
        $payload = $engine->handle($agent, $text, $channel, $visitorId, $requestId);
        unset($payload['conversation_id']);

        return $this->ownedResponse($payload, $visitorToken, $requestId);
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
