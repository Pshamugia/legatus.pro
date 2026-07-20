<?php

namespace App\Services;

use App\Jobs\ProcessMetaInboundMessage;
use App\Jobs\SendMetaMessage;
use App\Models\ChannelMessage;
use App\Models\MetaOAuthSelection;
use App\Support\ChannelOutboxSweepResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChannelOutboxService
{
    public function sweep(): ChannelOutboxSweepResult
    {
        $lock = Cache::lock('legatus:channel-outbox-sweep', 55);

        return $lock->get(function (): ChannelOutboxSweepResult {
            MetaOAuthSelection::query()->where('expires_at', '<=', now())->delete();
            $cutoff = now()->subSeconds(max(60, (int) config('meta.outbox_stale_seconds', 60)));
            $batch = max(1, min(500, (int) config('meta.outbox_batch_size', 100)));

            // A worker that disappeared while a Meta send was in-flight leaves
            // an ambiguous outcome. Never resend it automatically: Meta has no
            // idempotency key for the send endpoint.
            ChannelMessage::query()
                ->where('direction', 'outbound')
                ->where('status', 'sending')
                ->where('updated_at', '<=', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->get()
                ->each(function (ChannelMessage $message): void {
                    $message->update([
                        'status' => 'delivery_unknown',
                        'failure_reason' => 'Meta delivery outcome is unknown because the delivery worker stopped unexpectedly. Verify in the native Meta inbox before replying again.',
                        'payload' => array_filter([
                            'role' => data_get($message->payload, 'role'),
                            'content_removed' => true,
                        ], fn ($value) => $value !== null),
                        'processed_at' => now(),
                    ]);
                });

            $messages = ChannelMessage::query()
                ->where('updated_at', '<=', $cutoff)
                ->where(function ($query): void {
                    $query->where(function ($inbound): void {
                        $inbound->where('direction', 'inbound')->whereIn('status', ['received', 'processing']);
                    })->orWhere(function ($outbound): void {
                        $outbound->where('direction', 'outbound')->whereIn('status', ['queued', 'retrying']);
                    });
                })
                ->orderBy('id')
                ->limit($batch)
                ->get(['id', 'direction']);

            $dispatched = 0;
            $failedMessageIds = [];
            foreach ($messages as $message) {
                try {
                    if ($message->direction === 'inbound') {
                        ProcessMetaInboundMessage::dispatch($message->id);
                    } else {
                        SendMetaMessage::dispatch($message->id);
                    }
                    $message->touch();
                    $dispatched++;
                } catch (\Throwable $exception) {
                    $failedMessageIds[] = $message->id;

                    Log::error('Failed to enqueue a durable channel outbox message.', [
                        'channel_message_id' => $message->id,
                        'direction' => $message->direction,
                        'exception_class' => $exception::class,
                    ]);

                    // Leave updated_at and delivery state untouched. The durable
                    // row stays authoritative and a later sweep can retry the
                    // queue insert. Both jobs are idempotent by message ID, so an
                    // ambiguous queue response cannot produce a duplicate send.
                }
            }

            return new ChannelOutboxSweepResult($dispatched, $failedMessageIds);
        }) ?? new ChannelOutboxSweepResult(0);
    }
}
