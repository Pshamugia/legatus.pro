<?php

namespace App\Jobs;

use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChannelMessageDispatcher;
use App\Services\ConversationEngine;
use App\Support\PrivacyRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessMetaInboundMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 75;

    public int $uniqueFor = 600;

    public function __construct(public int $channelMessageId) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function uniqueId(): string
    {
        return (string) $this->channelMessageId;
    }

    public function handle(ConversationEngine $engine, ChannelMessageDispatcher $dispatcher): void
    {
        $record = ChannelMessage::query()->with('connection.agent')->find($this->channelMessageId);
        if (! $record || in_array($record->status, ['processed', 'ignored'], true)) {
            return;
        }

        $connection = $record->connection;
        if (! $connection || ! $connection->isActive()) {
            $reason = 'The Meta channel connection is unavailable.';
            $preserved = $this->preserveForHuman($record, $reason);
            $updates = [
                'status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => now(),
            ];
            if ($preserved) {
                $updates['payload'] = $this->minimalPayload($record);
            }
            $record->update($updates);

            return;
        }

        $senderId = (string) $record->provider_sender_id;
        $text = trim((string) data_get($record->payload, 'text', ''));
        if ($senderId === '' || $text === '') {
            $record->update([
                'status' => 'ignored',
                'payload' => $this->minimalPayload($record),
                'processed_at' => now(),
            ]);

            return;
        }

        if ((bool) data_get($record->payload, 'requires_human', false) || $record->message_type === 'attachment') {
            $reason = 'The customer sent media or an ungrounded postback that requires human review.';
            $preserved = $this->preserveForHuman($record, $reason, transportFailure: false);
            $updates = $preserved
                ? ['status' => 'processed', 'payload' => $this->minimalPayload($record), 'processed_at' => now()]
                : ['status' => 'failed', 'failure_reason' => $reason, 'failed_at' => now()];
            $record->update($updates);

            return;
        }

        Cache::lock('meta-inbound:'.$connection->id.':'.hash('sha256', $senderId), 120)
            ->block(20, function () use ($record, $connection, $senderId, $text, $engine, $dispatcher): void {
                $record->refresh();
                if (in_array($record->status, ['processed', 'ignored'], true)) {
                    return;
                }

                $record->update([
                    'status' => 'processing',
                    'attempts' => $record->attempts + 1,
                    'failure_reason' => null,
                    'failed_at' => null,
                ]);

                $customerId = "meta:{$connection->provider}:{$connection->id}:{$senderId}";
                $result = $engine->handle(
                    $connection->agent,
                    $text,
                    $connection->provider,
                    $customerId,
                    $record->idempotency_key,
                    ucfirst($connection->provider).' customer',
                );

                $conversation = Conversation::query()->findOrFail((int) $result['conversation_id']);
                $conversation->update([
                    'channel_connection_id' => $connection->id,
                    'external_thread_id' => $senderId,
                ]);

                $customerMessage = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('public_id', $result['customer_message_id'] ?? null)
                    ->first();
                $record->update([
                    'conversation_id' => $conversation->id,
                    'message_id' => $customerMessage?->id,
                ]);

                if (is_string($result['message_id'] ?? null)) {
                    $assistant = Message::query()
                        ->where('conversation_id', $conversation->id)
                        ->where('public_id', $result['message_id'])
                        ->first();
                    if ($assistant) {
                        $dispatcher->dispatch($assistant);
                    }
                }

                $record->update([
                    'status' => 'processed',
                    'payload' => $this->minimalPayload($record),
                    'processed_at' => now(),
                ]);
            });
    }

    public function failed(?\Throwable $exception): void
    {
        $reason = 'Inbound Meta message processing failed after safe retries.';

        $record = ChannelMessage::query()->find($this->channelMessageId);
        if ($record) {
            $preserved = $this->preserveForHuman($record, $reason);
            $updates = [
                'status' => 'failed',
                'failure_reason' => $reason,
                'failed_at' => now(),
            ];
            if ($preserved) {
                $updates['payload'] = $this->minimalPayload($record);
            }
            $record->update($updates);
        }
    }

    /**
     * Fail closed without losing the customer's request. The operator gets a
     * redacted inbox message before the encrypted transport payload is erased.
     */
    private function preserveForHuman(ChannelMessage $record, string $reason, bool $transportFailure = true): bool
    {
        try {
            return DB::transaction(function () use ($record, $reason, $transportFailure): bool {
                $locked = ChannelMessage::query()->lockForUpdate()->find($record->id);
                if (! $locked) {
                    return false;
                }

                $connection = ChannelConnection::query()->with('agent')->lockForUpdate()->find($locked->channel_connection_id);
                $senderId = trim((string) $locked->provider_sender_id);
                $text = trim((string) data_get($locked->payload, 'text', ''));
                if (! $connection || ! $connection->agent || $senderId === '' || $text === '') {
                    return false;
                }

                $customerId = "meta:{$connection->provider}:{$connection->id}:{$senderId}";
                $conversation = $connection->agent->conversations()
                    ->where('visitor_id', $customerId)
                    ->where('channel', $connection->provider)
                    ->whereIn('status', ['ai', 'open', 'human'])
                    ->latest('id')
                    ->first() ?? $connection->agent->conversations()->create([
                        'visitor_id' => $customerId,
                        'customer_name' => ucfirst($connection->provider).' customer',
                        'channel' => $connection->provider,
                        'status' => 'human',
                    ]);

                $safeText = PrivacyRedactor::text($text);
                $metadata = [
                    'contact_evidence' => PrivacyRedactor::contactEvidence($text),
                    'human_handoff' => true,
                    'handoff_reason' => $reason,
                    'channel_message_id' => $locked->id,
                ];
                if ($transportFailure) {
                    $metadata['transport_failure'] = true;
                }
                if ($safeText !== $text) {
                    $metadata['pii_redacted'] = true;
                }

                $customerMessage = $conversation->messages()->firstOrCreate(
                    ['request_id' => $locked->idempotency_key],
                    ['role' => 'customer', 'content' => $safeText, 'metadata' => $metadata],
                );
                $conversation->update([
                    'channel_connection_id' => $connection->id,
                    'external_thread_id' => $senderId,
                    'status' => 'human',
                    'assigned_to' => 'Meta inbox',
                    'priority' => 'high',
                    'intent' => 'handoff',
                    'handoff_reason' => $reason,
                    'handoff_summary' => $transportFailure
                        ? 'A customer request needs human attention because automated Meta processing could not complete safely.'
                        : 'The customer sent media or a postback that Legatus intentionally did not interpret without grounded text.',
                    'suggested_reply' => 'Please review the customer request and reply from the operator inbox.',
                    'outcome' => 'human_handoff',
                    'last_message_at' => now(),
                ]);
                $locked->update([
                    'conversation_id' => $conversation->id,
                    'message_id' => $customerMessage->id,
                ]);

                return true;
            }, 3);
        } catch (\Throwable) {
            // Retain the encrypted payload for a later/manual recovery rather
            // than erasing the only copy of the customer's request.
            return false;
        }
    }

    private function minimalPayload(ChannelMessage $record): array
    {
        return array_filter([
            'provider_timestamp' => data_get($record->payload, 'provider_timestamp'),
            'attachment_types' => collect(data_get($record->payload, 'attachments', []))
                ->pluck('type')
                ->filter()
                ->unique()
                ->take(5)
                ->values()
                ->all(),
            'content_removed' => true,
        ], fn ($value) => $value !== null && $value !== []);
    }
}
