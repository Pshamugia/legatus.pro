<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMetaInboundMessage;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\Conversation;
use App\Services\MetaWebhookNormalizer;
use App\Support\PrivacyRedactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

        foreach ($normalizer->normalize($payload) as $event) {
            $connection = ChannelConnection::query()
                ->where('provider', $event['provider'])
                ->where(function ($query) use ($event): void {
                    $query->where('external_account_id', $event['external_account_id'])
                        ->orWhere('metadata->facebook_page_id', $event['external_account_id']);
                })
                ->first();
            if (! $connection) {
                continue;
            }

            $connection->forceFill(['last_webhook_at' => now()])->save();

            match ($event['kind']) {
                'inbound' => $this->acceptInbound($connection, $event),
                'echo' => $this->acceptEcho($connection, $event),
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
                        'provider_timestamp' => $event['timestamp'],
                    ],
                    'received_at' => $this->date($event['timestamp']) ?? now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($message->status === 'received') {
            ProcessMetaInboundMessage::dispatch($message->id)->afterCommit();
        }
    }

    private function acceptEcho(ChannelConnection $connection, array $event): void
    {
        $existing = ChannelMessage::query()
            ->where('channel_connection_id', $connection->id)
            ->where('direction', 'outbound')
            ->where('provider_message_id', $event['provider_message_id'])
            ->first();
        if ($existing) {
            $existing->update([
                'status' => in_array($existing->status, ['delivered', 'read'], true) ? $existing->status : 'sent',
                'failure_reason' => null,
                'sent_at' => $this->date($event['timestamp']) ?? $existing->sent_at ?? now(),
            ]);

            return;
        }

        $appId = (string) ($event['app_id'] ?? '');
        if ($appId !== '' && config('meta.app_id') && hash_equals((string) config('meta.app_id'), $appId)) {
            // Our Graph response will attach this MID to the queued outbox row.
            // Never misclassify a racey app echo as a native human response.
            return;
        }

        $customerId = (string) ($event['recipient_id'] ?? '');
        $text = trim((string) ($event['text'] ?? ''));
        if ($customerId === '' || $text === '') {
            return;
        }

        try {
            $delivery = ChannelMessage::query()->firstOrCreate(
                [
                    'channel_connection_id' => $connection->id,
                    'direction' => 'outbound',
                    'provider_message_id' => $event['provider_message_id'],
                ],
                [
                    'provider_sender_id' => $event['sender_id'],
                    'provider_recipient_id' => $customerId,
                    'message_type' => $event['message_type'],
                    'status' => 'sent',
                    'payload' => ['text' => $text, 'role' => 'native_human'],
                    'sent_at' => $this->date($event['timestamp']) ?? now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $delivery = ChannelMessage::query()
                ->where('channel_connection_id', $connection->id)
                ->where('direction', 'outbound')
                ->where('provider_message_id', $event['provider_message_id'])
                ->first();
        }
        if (! $delivery) {
            return;
        }

        DB::transaction(function () use ($delivery, $connection, $customerId, $text): void {
            $delivery = ChannelMessage::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($delivery->message_id) {
                return;
            }

            $conversation = Conversation::query()
                ->where('channel_connection_id', $connection->id)
                ->where('external_thread_id', $customerId)
                ->latest('id')
                ->first();
            $conversation ??= $connection->agent->conversations()->create([
                'channel_connection_id' => $connection->id,
                'visitor_id' => "meta:{$connection->provider}:{$connection->id}:{$customerId}",
                'external_thread_id' => $customerId,
                'channel' => $connection->provider,
                'status' => 'human',
                'customer_name' => ucfirst($connection->provider).' customer',
            ]);

            $safeText = PrivacyRedactor::text($text);
            $metadata = [
                'operator' => 'Meta inbox',
                'native_meta_echo' => true,
                'provider' => $connection->provider,
            ];
            if ($safeText !== $text) {
                $metadata['pii_redacted'] = true;
            }
            $message = $conversation->messages()->create([
                'role' => 'human',
                'content' => $safeText,
                'metadata' => $metadata,
            ]);
            $conversation->update([
                'status' => 'human',
                'assigned_to' => 'Meta inbox',
                'resolved_at' => null,
                'last_message_at' => now(),
            ]);
            $delivery->update([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'status' => 'sent',
                'payload' => ['role' => 'native_human', 'content_removed' => true],
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
