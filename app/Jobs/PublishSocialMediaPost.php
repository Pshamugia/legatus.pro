<?php

namespace App\Jobs;

use App\Models\SocialMediaPost;
use App\Services\MetaGraphClient;
use App\Services\SocialMediaAiCopywriter;
use App\Services\SocialMediaTemplateRenderer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

class PublishSocialMediaPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $postId) {}

    public function handle(MetaGraphClient $meta, SocialMediaTemplateRenderer $renderer, ?SocialMediaAiCopywriter $copywriter = null): void
    {
        $copywriter ??= app(SocialMediaAiCopywriter::class);
        $post = SocialMediaPost::query()->with(['schedule', 'agent.organization', 'product'])->find($this->postId);
        if (! $post || $post->status !== 'queued' || $post->schedule?->status !== 'active') {
            return;
        }

        $product = $post->product;
        $localizedMap = (array) data_get($product?->metadata, 'localized', []);
        $localized = $post->language ? (array) ($localizedMap[$post->language] ?? []) : [];
        $currentUrl = (string) ($localized['product_url'] ?? data_get($product?->metadata, 'product_url'));
        $currentImage = (string) ($product?->catalogDesignImageUrl() ?: $product?->publicImageUrl() ?: ($localized['image'] ?? null));
        $publishImage = $this->publicHttpUrl((string) $post->image_url) ? (string) $post->image_url : $currentImage;
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
        $copyMode = $post->copy_mode ?: $post->schedule->copy_mode;
        if ($copyMode === 'ai') {
            if ($post->ai_generated_at === null && $post->ai_generation_attempted_at === null) {
                $title = (string) ($localized['name'] ?? $product->name);
                $descriptionValue = $product->socialDescription($post->language, $post->description);
                $description = Str::limit(preg_replace('/\s+/u', ' ', trim(strip_tags((string) $descriptionValue))) ?? '', 700, '…');
                $post->update([
                    'title' => $title,
                    'description' => $description ?: null,
                    'product_url' => $currentUrl,
                    'image_url' => $this->publicHttpUrl($publishImage) ? $publishImage : null,
                ]);
                $post->refresh();
                $post->update(['ai_generation_attempted_at' => now()]);
                try {
                    $post->update([
                        'caption' => $copywriter->generate($post),
                        'ai_generated_at' => now(),
                        'ai_model' => (string) config('services.openai.social_media_model', 'gpt-5.6-luna'),
                        'failure_reason' => null,
                    ]);
                    $post->refresh();
                } catch (\Throwable $exception) {
                    $post->update([
                        'failure_reason' => Str::limit('AI Copywriter unavailable; Original content used. '.$exception->getMessage(), 2000, ''),
                    ]);
                }
            }
        }
        if ($post->ai_generated_at === null && is_array($template)) {
            try {
                $title = (string) ($localized['name'] ?? $product->name);
                // Never erase the immutable description prepared for this post
                // when a later language sync leaves its localized field blank.
                $descriptionValue = $product->socialDescription($post->language, $post->description);
                $description = Str::limit(preg_replace('/\s+/u', ' ', trim(strip_tags((string) $descriptionValue))) ?? '', 700, '…');
                $renderProduct = clone $product;
                $renderProduct->name = $title;
                $renderProduct->category = $localized['category'] ?? $product->category;
                $renderProduct->description = $description;
                $renderProduct->metadata = array_replace($product->metadata ?? [], ['product_url' => $currentUrl]);
                $renderProduct->image = $publishImage;
                $post->update([
                    'title' => $title,
                    'description' => $description ?: null,
                    'product_url' => $currentUrl,
                    'image_url' => $this->publicHttpUrl($publishImage) ? $publishImage : null,
                    'caption' => $renderer->render($post->provider, $template, $post->agent, $renderProduct),
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
                : $meta->publishFacebookPost($connection, $post->caption, $post->product_url, (string) $post->image_url);
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
