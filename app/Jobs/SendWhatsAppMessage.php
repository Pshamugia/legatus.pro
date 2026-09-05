<?php

namespace App\Jobs;

use App\Models\ChannelMessage;
use App\Services\WhatsAppCloudClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendWhatsAppMessage implements ShouldBeUnique, ShouldQueue
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

    public function handle(WhatsAppCloudClient $client): void
    {
        Cache::lock('whatsapp-outbound:'.$this->channelMessageId, 60)->block(10, function () use ($client): void {
            $record = ChannelMessage::query()->with('connection')->find($this->channelMessageId);
            if (! $record || in_array($record->status, ['sent', 'delivered', 'read', 'delivery_unknown', 'failed', 'ignored'], true)) {
                return;
            }
            $connection = $record->connection;
            throw_unless($connection?->provider === 'whatsapp' && $connection->isActive(), new \RuntimeException('The WhatsApp connection is unavailable.'));

            $windowOpen = ChannelMessage::query()->where('channel_connection_id', $connection->id)
                ->where('direction', 'inbound')->where('provider_sender_id', $record->provider_recipient_id)
                ->where('received_at', '>=', now()->subHours(24))->exists();
            if (! $windowOpen) {
                $record->update(['status' => 'failed', 'failure_reason' => 'The WhatsApp 24-hour customer service window is closed; an approved template is required.', 'failed_at' => now(), 'processed_at' => now()]);

                return;
            }

            $record->update(['status' => 'sending', 'attempts' => $record->attempts + 1, 'failure_reason' => null]);
            try {
                $response = $client->sendText($connection, (string) $record->provider_recipient_id, trim((string) data_get($record->payload, 'text', '')));
                $id = trim((string) data_get($response, 'messages.0.id', ''));
                if ($id === '') {
                    $record->update(['status' => 'delivery_unknown', 'failure_reason' => 'WhatsApp accepted the request without a message ID. Verify before replying again.', 'payload' => ['content_removed' => true], 'processed_at' => now()]);

                    return;
                }
                $record->update(['provider_message_id' => $id, 'status' => 'sent', 'payload' => ['role' => data_get($record->payload, 'role'), 'content_removed' => true], 'sent_at' => now(), 'processed_at' => now()]);
                $connection->update(['last_error' => null]);
            } catch (ConnectionException) {
                $record->update(['status' => 'delivery_unknown', 'failure_reason' => 'WhatsApp delivery outcome is unknown after a network timeout. Verify before replying again.', 'payload' => ['content_removed' => true], 'processed_at' => now()]);
            } catch (RequestException $exception) {
                $status = $exception->response->status();
                if ($status === 408 || $status >= 500) {
                    $record->update(['status' => 'delivery_unknown', 'failure_reason' => 'WhatsApp delivery outcome is unknown after a provider error. Verify before replying again.', 'payload' => ['content_removed' => true], 'processed_at' => now()]);

                    return;
                }
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    $record->update([
                        'status' => 'failed',
                        'failure_reason' => "WhatsApp rejected message delivery (HTTP {$status}). Reconnect the channel or verify the service window.",
                        'payload' => ['content_removed' => true],
                        'failed_at' => now(),
                        'processed_at' => now(),
                    ]);

                    return;
                }

                throw $exception;
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        ChannelMessage::query()->whereKey($this->channelMessageId)->update(['status' => 'failed', 'failure_reason' => 'WhatsApp message delivery failed safely.', 'failed_at' => now()]);
    }
}
