<?php

namespace App\Jobs;

use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Services\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendMetaMessage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 600;

    public function __construct(public int $channelMessageId) {}

    public function backoff(): array
    {
        return [5, 30, 180];
    }

    public function uniqueId(): string
    {
        return (string) $this->channelMessageId;
    }

    public function handle(MetaGraphClient $meta): void
    {
        Cache::lock('meta-outbound:'.$this->channelMessageId, 60)->block(10, function () use ($meta): void {
            $record = ChannelMessage::query()->with('connection')->find($this->channelMessageId);
            if (! $record || in_array($record->status, ['sent', 'delivered', 'read', 'delivery_unknown', 'failed', 'ignored'], true)) {
                return;
            }

            $connection = $record->connection;
            if (! $connection || ! $connection->isActive()) {
                throw new \RuntimeException('The Meta channel connection is unavailable.');
            }

            $text = trim((string) data_get($record->payload, 'text', ''));
            $recipientId = (string) $record->provider_recipient_id;
            if ($text === '' || $recipientId === '') {
                $record->update([
                    'status' => 'ignored',
                    'payload' => $this->minimalPayload($record),
                    'processed_at' => now(),
                ]);

                return;
            }

            $record->update([
                'status' => 'sending',
                'attempts' => $record->attempts + 1,
                'failure_reason' => null,
                'failed_at' => null,
            ]);

            try {
                $response = $meta->sendText($connection, $recipientId, $text);
                $providerMessageId = (string) ($response['message_id'] ?? '');
                if ($providerMessageId === '') {
                    $this->markDeliveryUnknown($record, $connection);

                    return;
                }

                $record->refresh();
                $record->update([
                    'provider_message_id' => $providerMessageId,
                    'status' => in_array($record->status, ['delivered', 'read'], true) ? $record->status : 'sent',
                    'payload' => $this->minimalPayload($record),
                    'sent_at' => now(),
                    'processed_at' => now(),
                ]);
                $connection->update(['last_error' => null]);
            } catch (ConnectionException) {
                $this->markDeliveryUnknown($record, $connection);
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                if ($status === 408 || $status >= 500) {
                    $this->markDeliveryUnknown($record, $connection);

                    return;
                }

                if ($status >= 400 && $status < 500 && $status !== 429) {
                    $this->markPermanentFailure($record, $connection, $status);

                    return;
                }

                $reason = $this->safeError($exception);
                $record->update(['status' => 'retrying', 'failure_reason' => $reason]);
                $connection->update(['last_error' => $reason]);

                throw $exception;
            } catch (\Throwable $exception) {
                $reason = $this->safeError($exception);
                $record->update(['status' => 'retrying', 'failure_reason' => $reason]);
                $connection->update(['last_error' => $reason]);

                throw $exception;
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $reason = $exception ? $this->safeError($exception) : 'Meta message delivery failed.';
        $record = ChannelMessage::query()->with('connection')->find($this->channelMessageId);
        $record?->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'payload' => $record ? $this->minimalPayload($record) : null,
            'failed_at' => now(),
        ]);
        $record?->connection?->update(['last_error' => $reason]);
    }

    private function safeError(\Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            return 'Meta rejected message delivery (HTTP '.$exception->response->status().'). Reconnect the channel or verify messaging permissions.';
        }

        return 'Meta message delivery failed safely. Reconnect the channel or verify messaging permissions.';
    }

    private function markDeliveryUnknown(ChannelMessage $record, ChannelConnection $connection): void
    {
        $reason = 'Meta delivery outcome is unknown after a network timeout. Verify in the Meta inbox before replying again.';
        $record->update([
            'status' => 'delivery_unknown',
            'failure_reason' => $reason,
            'payload' => $this->minimalPayload($record),
            'processed_at' => now(),
        ]);
        $connection->update(['last_error' => $reason]);
    }

    private function markPermanentFailure(ChannelMessage $record, ChannelConnection $connection, int $status): void
    {
        $reason = "Meta rejected message delivery (HTTP {$status}). Reconnect the channel or verify messaging permissions.";
        $record->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'payload' => $this->minimalPayload($record),
            'failed_at' => now(),
            'processed_at' => now(),
        ]);
        $connection->update(['last_error' => $reason]);
    }

    private function minimalPayload(ChannelMessage $record): array
    {
        return array_filter([
            'role' => data_get($record->payload, 'role'),
            'content_removed' => true,
        ], fn ($value) => $value !== null);
    }
}
