<?php

namespace App\Jobs;

use App\Models\SocialMediaPost;
use App\Services\MetaGraphClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class PublishSocialMediaPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $postId) {}

    public function handle(MetaGraphClient $meta): void
    {
        $post = SocialMediaPost::query()->with(['schedule', 'agent'])->find($this->postId);
        if (! $post || $post->status !== 'queued' || $post->schedule?->status !== 'active') {
            return;
        }

        $connection = $post->agent->channelConnections()
            ->where('provider', $post->provider)
            ->where('status', 'active')
            ->first();
        if (! $connection || ! $connection->isActive()) {
            throw new \RuntimeException("The {$post->provider} publishing connection is not active.");
        }

        $post->increment('attempts');
        try {
            $result = $post->provider === 'instagram'
                ? $meta->publishInstagramPost($connection, $post->caption, (string) $post->image_url)
                : $meta->publishFacebookPost($connection, $post->caption, $post->product_url);
            $post->update([
                'status' => 'published',
                'provider_post_id' => (string) ($result['id'] ?? ''),
                'published_at' => now(),
                'failure_reason' => null,
            ]);
        } catch (\Throwable $exception) {
            $post->update([
                // Keep the row claimed while Laravel retries this same job;
                // otherwise the minute scheduler could enqueue duplicates.
                'status' => $this->attempts() >= $this->tries ? 'failed' : 'queued',
                'failure_reason' => Str::limit($exception->getMessage(), 2000, ''),
            ]);
            throw $exception;
        }
    }
}
