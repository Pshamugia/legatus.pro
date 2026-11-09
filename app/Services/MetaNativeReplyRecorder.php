<?php

namespace App\Services;

use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\Conversation;
use App\Support\PrivacyRedactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MetaNativeReplyRecorder
{
    /** Record a Page/Instagram operator reply observed by webhook or Graph reconciliation. */
    public function record(ChannelConnection $connection, array $event): bool
    {
        $messageId = trim((string) ($event['provider_message_id'] ?? ''));
        if ($messageId === '') {
            return false;
        }

        $existing = ChannelMessage::query()
            ->where('channel_connection_id', $connection->id)
            ->where('direction', 'outbound')
            ->where('provider_message_id', $messageId)
            ->first();
        if ($existing) {
            $existing->update([
                'status' => in_array($existing->status, ['delivered', 'read'], true) ? $existing->status : 'sent',
                'failure_reason' => null,
                'sent_at' => $this->date($event['timestamp'] ?? null) ?? $existing->sent_at ?? now(),
            ]);

            return false;
        }

        $appId = (string) ($event['app_id'] ?? '');
        if ($appId !== '' && config('meta.app_id') && hash_equals((string) config('meta.app_id'), $appId)) {
            return false;
        }

        $customerId = trim((string) ($event['recipient_id'] ?? ''));
        $text = trim((string) ($event['text'] ?? ''));
        if ($customerId === '' || $text === '') {
            return false;
        }

        try {
            $delivery = ChannelMessage::query()->firstOrCreate(
                [
                    'channel_connection_id' => $connection->id,
                    'direction' => 'outbound',
                    'provider_message_id' => $messageId,
                ],
                [
                    'provider_sender_id' => $event['sender_id'] ?? $connection->external_account_id,
                    'provider_recipient_id' => $customerId,
                    'message_type' => $event['message_type'] ?? 'text',
                    'status' => 'sent',
                    'payload' => ['text' => $text, 'role' => 'native_human'],
                    'sent_at' => $this->date($event['timestamp'] ?? null) ?? now(),
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $delivery = ChannelMessage::query()
                ->where('channel_connection_id', $connection->id)
                ->where('direction', 'outbound')
                ->where('provider_message_id', $messageId)
                ->first();
        }
        if (! $delivery) {
            return false;
        }

        return DB::transaction(function () use ($delivery, $connection, $customerId, $text): bool {
            $delivery = ChannelMessage::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($delivery->message_id) {
                return false;
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
            $conversation->channelMessages()
                ->where('direction', 'outbound')
                ->whereIn('status', ['queued', 'retrying'])
                ->whereHas('message', fn ($query) => $query->where('role', 'assistant'))
                ->get()
                ->each(fn (ChannelMessage $pending) => $pending->update([
                    'status' => 'ignored',
                    'failure_reason' => 'AI reply suppressed because a human operator controls the conversation.',
                    'payload' => ['role' => 'assistant', 'content_removed' => true],
                    'processed_at' => now(),
                ]));
            $delivery->update([
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'status' => 'sent',
                'payload' => ['role' => 'native_human', 'content_removed' => true],
                'processed_at' => now(),
            ]);

            return true;
        });
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
