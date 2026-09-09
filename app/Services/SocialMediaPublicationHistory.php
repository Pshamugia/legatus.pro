<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Product;
use App\Models\SocialMediaPost;
use App\Models\SocialMediaPublicationIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialMediaPublicationHistory
{
    public function backfill(Agent $agent): void
    {
        $agent->socialMediaPosts()
            ->where('status', 'published')
            ->with('product')
            ->orderBy('id')
            ->chunkById(100, function ($posts): void {
                foreach ($posts as $post) {
                    $this->rememberPublished($post);
                }
            });
    }

    /** @param list<string> $providers */
    public function wasUsedOnAny(Agent $agent, Product $product, array $providers): bool
    {
        $keys = $this->identityKeys(
            $product,
            (string) data_get($product->metadata, 'product_url'),
            $product->catalogDesignImageUrl() ?: $product->publicImageUrl(),
            $product->name,
        );

        return $keys !== [] && SocialMediaPublicationIdentity::query()
            ->where('agent_id', $agent->id)
            ->whereIn('provider', array_values(array_unique($providers)))
            ->whereIn('identity_key', $keys)
            ->exists();
    }

    public function claim(SocialMediaPost $post): bool
    {
        $product = $post->product;
        if (! $product) {
            return false;
        }
        $keys = $this->identityKeys($product, $post->product_url, null, $post->title);
        if ($keys === []) {
            return false;
        }

        try {
            return DB::transaction(function () use ($post, $keys): bool {
                $existing = SocialMediaPublicationIdentity::query()
                    ->where('agent_id', $post->agent_id)
                    ->where('provider', $post->provider)
                    ->whereIn('identity_key', $keys)
                    ->lockForUpdate()
                    ->get();

                if ($existing->isNotEmpty()) {
                    return false;
                }

                foreach ($keys as $key) {
                    SocialMediaPublicationIdentity::query()->firstOrCreate([
                        'agent_id' => $post->agent_id,
                        'provider' => $post->provider,
                        'identity_key' => $key,
                    ], [
                        'social_media_post_id' => $post->id,
                        'product_id' => $post->product_id,
                        'status' => 'claimed',
                    ]);
                }

                return true;
            });
        } catch (QueryException) {
            // A concurrent worker won the unique identity claim.
            return false;
        }
    }

    public function rememberPublished(SocialMediaPost $post): void
    {
        $product = $post->product;
        foreach ($this->identityKeys($product, $post->product_url, $post->image_url, $post->title) as $key) {
            SocialMediaPublicationIdentity::query()->updateOrCreate([
                'agent_id' => $post->agent_id,
                'provider' => $post->provider,
                'identity_key' => $key,
            ], [
                'social_media_post_id' => $post->id,
                'product_id' => $post->product_id,
                'status' => 'published',
                'published_at' => $post->published_at ?: now(),
                'provider_post_id' => $post->provider_post_id,
            ]);
        }
    }

    /** @return list<string> */
    private function identityKeys(?Product $product, ?string $productUrl, ?string $imageUrl, ?string $title): array
    {
        $externalId = $product?->external_product_id
            ?: data_get($product?->metadata, 'external_product_id')
            ?: data_get($product?->metadata, 'external_id');
        $normalizedTitle = Str::lower(preg_replace('/\s+/u', ' ', trim((string) $title)) ?? '');
        $normalizedImage = $this->normalizedUrl($imageUrl ?: $product?->catalogDesignImageUrl() ?: $product?->publicImageUrl());
        $values = [
            'external' => is_scalar($externalId) ? trim((string) $externalId) : '',
            'url' => $this->normalizedUrl($productUrl ?: data_get($product?->metadata, 'product_url')),
            'sku' => trim((string) $product?->sku),
            // A shared placeholder image is not an identity. The visual key
            // is safe only together with the normalized product title.
            'visual' => $normalizedImage !== '' && $normalizedTitle !== ''
                ? $normalizedImage.'|'.$normalizedTitle
                : '',
        ];
        if (collect($values)->filter()->isEmpty()) {
            $values['title'] = $normalizedTitle;
        }

        return collect($values)
            ->filter(fn (string $value): bool => $value !== '')
            ->map(fn (string $value, string $type): string => hash('sha256', $type.':'.Str::lower($value)))
            ->unique()
            ->values()
            ->all();
    }

    private function normalizedUrl(mixed $url): string
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $parts = parse_url($url);
        $host = Str::lower((string) ($parts['host'] ?? ''));
        $path = rtrim((string) ($parts['path'] ?? ''), '/');
        if ($host === '' || $path === '') {
            return '';
        }

        return $host.(isset($parts['port']) ? ':'.(int) $parts['port'] : '').$path;
    }
}
