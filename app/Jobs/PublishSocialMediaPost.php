<?php

namespace App\Jobs;

use App\Models\SocialMediaPost;
use App\Services\MetaGraphClient;
use App\Services\SocialMediaTemplateRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class PublishSocialMediaPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $postId) {}

    public function handle(MetaGraphClient $meta, SocialMediaTemplateRenderer $renderer): void
    {
        $post = SocialMediaPost::query()->with(['schedule', 'agent.organization', 'product'])->find($this->postId);
        if (! $post || $post->status !== 'queued' || $post->schedule?->status !== 'active') {
            return;
        }

        $product = $post->product;
        $currentUrl = (string) data_get($product?->metadata, 'product_url');
        $currentImage = (string) $product?->publicImageUrl();
        $productIsPublishable = $product
            && $product->agent_id === $post->agent_id
            && $product->is_active
            && $product->stock > 0
            && $this->publicHttpUrl($currentUrl)
            && ($post->provider !== 'instagram' || $this->publicHttpUrl($currentImage));
        if (! $productIsPublishable) {
            $post->update([
                'status' => 'skipped',
                'failure_reason' => 'The public product is no longer active, in stock, or publishable on this channel.',
            ]);

            return;
        }

        $template = data_get($post->schedule->template_snapshots, $post->provider);
        if (is_array($template)) {
            try {
                $description = Str::limit(preg_replace('/\s+/u', ' ', trim(strip_tags((string) $product->description))) ?? '', 700, '…');
                $post->update([
                    'title' => $product->name,
                    'description' => $description ?: null,
                    'product_url' => $currentUrl,
                    'image_url' => $this->publicHttpUrl($currentImage) ? $currentImage : null,
                    'caption' => $renderer->render($post->provider, $template, $post->agent, $product),
                ]);
                $post->refresh();
            } catch (\Throwable $exception) {
                // Template snapshots are local immutable configuration, so a
                // validation failure cannot become healthy on a queue retry.
                $post->update([
                    'status' => 'failed',
                    'failure_reason' => Str::limit($exception->getMessage(), 2000, ''),
                ]);

                return;
            }
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

    private function publicHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
