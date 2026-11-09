<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaInboundMessage;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Services\MetaNativeReplyRecorder;
use App\Services\MetaWebhookNormalizer;
use App\Support\PrivacyRedactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MetaWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $configuredToken = (string) config('meta.verify_token');
        abort_if($configuredToken === '', 503, 'Meta webhook verification is not configured.');

        $mode = (string) ($request->query('hub.mode') ?? $request->query('hub_mode', ''));
        $token = (string) ($request->query('hub.verify_token') ?? $request->query('hub_verify_token', ''));
        $challenge = (string) ($request->query('hub.challenge') ?? $request->query('hub_challenge', ''));

        abort_unless($mode === 'subscribe' && $challenge !== '' && hash_equals($configuredToken, $token), 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, MetaWebhookNormalizer $normalizer): Response
    {
        $body = $request->getContent();
        abort_if(strlen($body) > max(1024, (int) config('meta.max_webhook_bytes')), 413);
        abort_unless($this->validSignature($body, (string) $request->header('X-Hub-Signature-256')), 401);

        try {
            $payload = json_decode($body, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400, 'Invalid webhook payload.');
        }
        abort_unless(is_array($payload), 400, 'Invalid webhook payload.');

        $events = $normalizer->normalize($payload);
        Log::info('Meta webhook received.', [
            'object' => (string) ($payload['object'] ?? ''),
            'entry_ids' => collect((array) ($payload['entry'] ?? []))
                ->pluck('id')
                ->filter(fn ($id): bool => is_string($id) || is_int($id))
                ->map(fn ($id): string => (string) $id)
                ->take(10)
                ->values()
                ->all(),
            'event_count' => count($events),
            'event_kinds' => collect($events)->pluck('kind')->filter()->unique()->values()->all(),
            // Keep rejected live payloads diagnosable without retaining comment
            // text, account identifiers, or any other customer content.
            'unmatched_change_shapes' => $events === []
                ? collect((array) ($payload['entry'] ?? []))
                    ->flatMap(fn ($entry): array => (array) data_get($entry, 'changes', []))
                    ->map(function ($change): array {
                        $value = (array) data_get($change, 'value', []);

                        return [
                            'field' => (string) data_get($change, 'field', ''),
                            'item' => is_scalar($value['item'] ?? null) ? (string) $value['item'] : null,
                            'verb' => is_scalar($value['verb'] ?? null) ? (string) $value['verb'] : null,
                            'value_keys' => array_values(array_map('strval', array_keys($value))),
                            'has_comment_id' => filled($value['comment_id'] ?? $value['id'] ?? null),
                            'has_text' => filled($value['message'] ?? $value['text'] ?? null),
                            'has_sender_id' => filled(data_get($value, 'from.id')),
                        ];
                    })
                    ->take(10)
                    ->values()
                    ->all()
                : [],
        ]);

        foreach ($events as $event) {
            $connection = ChannelConnection::query()
                ->where('provider', $event['provider'])
                ->where(function ($query) use ($event): void {
                    $query->where('external_account_id', $event['external_account_id'])
                        ->orWhere('metadata->facebook_page_id', $event['external_account_id']);
                })
                ->first();
            if (! $connection) {
                Log::warning('Meta webhook account did not match an active connection.', [
                    'provider' => $event['provider'],
                    'external_account_id' => $event['external_account_id'],
                    'kind' => $event['kind'],
                ]);

                continue;
            }

            $connection->forceFill(['last_webhook_at' => now()])->save();

            match ($event['kind']) {
                'inbound' => $this->acceptInbound($connection, $event),
                'comment' => $this->acceptComment($connection, $event),
                'echo' => app(MetaNativeReplyRecorder::class)->record($connection, $event),
                'delivery' => $this->markDelivery($connection, $event, 'delivered'),
                'sent' => $this->markDelivery($connection, $event, 'sent'),
                'read' => $this->markRead($connection, $event),
                default => null,
            };
        }

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }

    private function acceptInbound(ChannelConnection $connection, array $event): void
    {
        try {
            $message = ChannelMessage::query()->firstOrCreate(
                [
                    'channel_connection_id' => $connection->id,
                    'direction' => 'inbound',
                    'provider_message_id' => $event['provider_message_id'],
                ],
                [
                    'provider_sender_id' => $event['sender_id'],
                    'provider_recipient_id' => $event['recipient_id'],
                    'message_type' => $event['message_type'],
                    'status' => 'received',
                    'payload' => [
                        'text' => $event['text'],
                        'attachments' => $event['attachments'],
                        'requires_human' => (bool) ($event['requires_human'] ?? false),
                        'suppress_reply' => (bool) ($event['suppress_reply'] ?? false),
                        'provider_timestamp' => $event['timestamp'],
                    ],
                    'received_at' => $this->date($event['timestamp']) ?? now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($message->status === 'received') {
            // Meta can split one customer action into adjacent text and image
            // webhooks. Give both records a short coalescing window before a
            // worker decides that the text is an independent request.
            ProcessMetaInboundMessage::dispatch($message->id)
                ->onQueue('channels')
                ->delay(now()->addSeconds(3))
                ->afterCommit();
        }
    }

    private function acceptComment(ChannelConnection $connection, array $event): void
    {
        $text = trim((string) ($event['text'] ?? ''));
        $senderId = (string) ($event['sender_id'] ?? '');
        if ($senderId === '') {
            return;
        }

        try {
            $record = ChannelMessage::query()->firstOrCreate(
                [
                    'channel_connection_id' => $connection->id,
                    'direction' => 'inbound',
                    'provider_message_id' => $event['provider_message_id'],
                ],
                [
                    'provider_sender_id' => $senderId,
                    'provider_recipient_id' => $event['external_account_id'],
                    'message_type' => 'comment',
                    'status' => 'received',
                    'payload' => [
                        'text' => PrivacyRedactor::text($text),
                        'post_id' => (string) ($event['post_id'] ?? ''),
                        'source' => 'social_comment',
                    ],
                    'received_at' => $this->date($event['timestamp'] ?? null) ?? now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return;
        }
        if ($record->conversation_id || $record->status !== 'received') {
            return;
        }

        if ((bool) config('meta.comment_to_sale_enabled') && $connection->agent->automaticCommentRepliesEnabled()) {
            ProcessMetaInboundMessage::dispatch($record->id)
                ->onQueue('channels')
                ->afterCommit();

            return;
        }

        DB::transaction(function () use ($record, $connection, $event, $senderId, $text): void {
            $locked = ChannelMessage::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if ($locked->conversation_id) {
                return;
            }
            $provider = (string) $connection->provider;
            $conversation = $connection->agent->conversations()->create([
                'channel_connection_id' => $connection->id,
                'visitor_id' => "meta-comment:{$provider}:{$connection->id}:".hash('sha256', $senderId),
                'external_thread_id' => (string) $event['provider_message_id'],
                'customer_name' => filled($event['sender_name'] ?? null)
                    ? mb_substr((string) $event['sender_name'], 0, 120)
                    : ucfirst($provider).' commenter',
                'channel' => $provider.'_comment',
                'status' => 'human',
                'intent' => 'social_comment',
                'priority' => 'high',
                'handoff_reason' => 'A customer commented on a social post. Review it before deciding whether to reply.',
                'context' => [
                    'source_type' => 'social_comment',
                    'provider' => $provider,
                    'post_id' => (string) ($event['post_id'] ?? ''),
                ],
                'last_message_at' => $this->date($event['timestamp'] ?? null) ?? now(),
            ]);
            $safeText = PrivacyRedactor::text($text);
            $message = $conversation->messages()->create([
                'role' => 'customer',
                'content' => $safeText,
                'metadata' => ['source_type' => 'social_comment', 'provider' => $provider],
            ]);
            $locked->update([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'status' => 'processed',
                'payload' => ['source' => 'social_comment', 'content_removed' => true],
                'processed_at' => now(),
            ]);
        });
    }

    private function markDelivery(ChannelConnection $connection, array $event, string $status): void
    {
        $updates = ['status' => $status];
        if ($status === 'delivered') {
            $updates['delivered_at'] = $this->date($event['timestamp']) ?? now();
        } elseif ($status === 'sent') {
            $updates['sent_at'] = $this->date($event['timestamp']) ?? now();
        }

        $query = ChannelMessage::query()
            ->where('channel_connection_id', $connection->id)
            ->where('direction', 'outbound')
            ->where('provider_message_id', $event['provider_message_id']);
        if ($status === 'sent') {
            $query->whereNotIn('status', ['delivered', 'read']);
        } elseif ($status === 'delivered') {
            $query->where('status', '!=', 'read');
        }
        $query->update($updates);
    }

    private function markRead(ChannelConnection $connection, array $event): void
    {
        $query = ChannelMessage::query()
            ->where('channel_connection_id', $connection->id)
            ->where('direction', 'outbound')
            ->where('provider_recipient_id', $event['sender_id'])
            ->whereIn('status', ['sent', 'delivered']);

        $watermark = $this->date($event['watermark']);
        if (! $watermark) {
            return;
        }

        $query->whereNotNull('sent_at')->where('sent_at', '<=', $watermark);

        $query->update(['status' => 'read', 'delivered_at' => now()]);
    }

    private function validSignature(string $body, string $provided): bool
    {
        $secret = (string) config('meta.app_secret');
        if ($secret === '' || ! str_starts_with($provided, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $provided);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
