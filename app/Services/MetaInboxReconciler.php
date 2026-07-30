<?php

namespace App\Services;

use App\Jobs\ProcessMetaInboundMessage;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class MetaInboxReconciler
{
    public function __construct(private readonly MetaGraphClient $graph) {}

    public function reconcile(ChannelConnection $connection, int $lookbackMinutes = 5): int
    {
        if (! $connection->isActive() || $connection->provider !== 'facebook') {
            return 0;
        }

        $metadata = (array) $connection->metadata;
        $cursor = $this->cursor($metadata['inbox_reconciled_at'] ?? null)
            ?? now()->subMinutes(max(1, min(60, $lookbackMinutes)));
        $imported = 0;

        try {
            $messages = collect($this->graph->recentFacebookMessages($connection))
                ->sortBy(fn (array $message): string => (string) ($message['created_time'] ?? ''));

            foreach ($messages as $message) {
                $createdAt = $this->cursor($message['created_time'] ?? null);
                $messageId = trim((string) ($message['id'] ?? ''));
                $senderId = trim((string) data_get($message, 'from.id', ''));
                $text = trim((string) ($message['message'] ?? ''));
                if (! $createdAt || $createdAt->lte($cursor) || $messageId === '' || $senderId === ''
                    || $senderId === $connection->external_account_id || $text === '') {
                    continue;
                }

                $record = ChannelMessage::query()->firstOrCreate(
                    [
                        'channel_connection_id' => $connection->id,
                        'direction' => 'inbound',
                        'provider_message_id' => $messageId,
                    ],
                    [
                        'provider_sender_id' => $senderId,
                        'provider_recipient_id' => $connection->external_account_id,
                        'message_type' => 'text',
                        'status' => 'received',
                        'payload' => [
                            'text' => mb_substr($text, 0, 4000),
                            'attachments' => [],
                            'requires_human' => false,
                            'provider_timestamp' => $createdAt->toIso8601String(),
                            'reconciled_from_graph' => true,
                        ],
                        'received_at' => $createdAt,
                    ],
                );

                if ($record->wasRecentlyCreated) {
                    ProcessMetaInboundMessage::dispatch($record->id);
                    $imported++;
                }
            }

            $metadata['inbox_reconciled_at'] = now()->toIso8601String();
            $connection->forceFill(['metadata' => $metadata, 'last_error' => null])->save();
        } catch (\Throwable $exception) {
            Log::warning('Meta inbox reconciliation failed.', [
                'connection_id' => $connection->id,
                'status' => $connection->status,
                'error' => $exception->getMessage(),
            ]);
        }

        return $imported;
    }

    private function cursor(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
