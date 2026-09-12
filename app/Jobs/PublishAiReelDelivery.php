<?php

namespace App\Jobs;

use App\Models\AiReelDelivery;
use App\Services\MetaGraphClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PublishAiReelDelivery implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 25;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $deliveryId) {}

    public function uniqueId(): string
    {
        return (string) $this->deliveryId;
    }

    public function handle(MetaGraphClient $meta): void
    {
        $delivery = AiReelDelivery::query()->with('reel.agent')->find($this->deliveryId);
        if (! $delivery || in_array($delivery->status, ['published', 'delivery_unknown'], true)) {
            return;
        }
        $reel = $delivery->reel;
        $videoUrl = $reel->publicVideoUrl();
        if (! $videoUrl || ! in_array($reel->status, ['ready', 'publishing', 'partially_failed'], true)) {
            return;
        }
        $connection = $reel->agent->channelConnections()->where('provider', $delivery->provider)->where('status', 'active')->first();
        if (! $connection) {
            $this->failDelivery($delivery, 'The selected social account is no longer connected.');

            return;
        }
        $reel->update(['status' => 'publishing']);

        if ($delivery->provider === 'instagram') {
            $this->publishInstagram($delivery, $connection, $meta, $videoUrl);
        } else {
            $this->publishFacebook($delivery, $connection, $meta, $videoUrl);
        }
    }

    private function publishInstagram(AiReelDelivery $delivery, $connection, MetaGraphClient $meta, string $videoUrl): void
    {
        try {
            if (! $delivery->provider_container_id) {
                $delivery->update([
                    'provider_container_id' => $meta->createInstagramReelContainer($connection, $videoUrl, (string) $delivery->reel->caption),
                    'status' => 'processing',
                ]);
                $this->release(15);

                return;
            }
            $status = strtoupper($meta->instagramReelContainerStatus($connection, $delivery->provider_container_id));
            if (in_array($status, ['IN_PROGRESS', 'PROCESSING'], true)) {
                $this->release(15);

                return;
            }
            if ($status !== 'FINISHED') {
                $this->failDelivery($delivery, 'Instagram could not process the Reel video.');

                return;
            }
            $delivery->update(['status' => 'publishing']);
            try {
                $result = $meta->publishInstagramReelContainer($connection, $delivery->provider_container_id);
            } catch (\Throwable $exception) {
                $delivery->update(['status' => 'delivery_unknown', 'last_error' => Str::limit($exception->getMessage(), 1000)]);
                $this->refreshReelStatus($delivery);

                return;
            }
            $this->published($delivery, (string) ($result['id'] ?? $delivery->provider_container_id));
        } catch (\Throwable $exception) {
            $this->failDelivery($delivery, $exception->getMessage());
        }
    }

    private function publishFacebook(AiReelDelivery $delivery, $connection, MetaGraphClient $meta, string $videoUrl): void
    {
        try {
            if (! $delivery->provider_container_id) {
                $videoId = $meta->startFacebookReel($connection);
                $meta->uploadFacebookReel($connection, $videoId, $videoUrl);
                $delivery->update(['provider_container_id' => $videoId, 'status' => 'publishing']);
            }
            try {
                $result = $meta->finishFacebookReel($connection, $delivery->provider_container_id, (string) $delivery->reel->caption);
            } catch (\Throwable $exception) {
                $delivery->update(['status' => 'delivery_unknown', 'last_error' => Str::limit($exception->getMessage(), 1000)]);
                $this->refreshReelStatus($delivery);

                return;
            }
            $this->published($delivery, (string) ($result['id'] ?? $delivery->provider_container_id));
        } catch (\Throwable $exception) {
            $this->failDelivery($delivery, $exception->getMessage());
        }
    }

    private function published(AiReelDelivery $delivery, string $id): void
    {
        $delivery->update(['status' => 'published', 'provider_post_id' => $id, 'published_at' => now(), 'last_error' => null]);
        $this->refreshReelStatus($delivery);
    }

    private function failDelivery(AiReelDelivery $delivery, string $reason): void
    {
        $delivery->update(['status' => 'failed', 'last_error' => Str::limit($reason, 1000)]);
        $this->refreshReelStatus($delivery);
    }

    private function refreshReelStatus(AiReelDelivery $delivery): void
    {
        $reel = $delivery->reel()->with('deliveries')->firstOrFail();
        $statuses = $reel->deliveries->pluck('status');
        if ($statuses->every(fn ($status) => $status === 'published')) {
            $reel->update(['status' => 'published']);
        } elseif ($statuses->every(fn ($status) => in_array($status, ['published', 'failed', 'delivery_unknown'], true))) {
            $reel->update(['status' => $statuses->contains('published') ? 'partially_failed' : 'failed']);
        }
    }
}
