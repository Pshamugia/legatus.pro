<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $guarded = [];

    protected $casts = ['price' => 'decimal:2', 'metadata' => 'array', 'is_active' => 'boolean'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function publicImageUrl(): ?string
    {
        $url = $this->image ?: data_get($this->metadata, 'image');

        return $this->validatedPublicImageUrl($url);
    }

    /** Curated catalogue artwork, falling back to the primary product photo. */
    public function catalogDesignImageUrl(): ?string
    {
        $url = data_get($this->metadata, 'image') ?: $this->image;

        return $this->validatedPublicImageUrl($url);
    }

    public function matchesPublicProductUrl(string $expectedUrl): bool
    {
        $expected = $this->canonicalProductUrl($expectedUrl);
        if ($expected === null) {
            return false;
        }

        $urls = [data_get($this->metadata, 'product_url')];
        foreach ((array) data_get($this->metadata, 'localized', []) as $localized) {
            $urls[] = data_get($localized, 'product_url');
        }

        return collect($urls)->contains(
            fn ($url): bool => is_string($url) && $this->canonicalProductUrl($url) === $expected,
        );
    }

    public function socialDescription(?string $language = null, ?string $preparedFallback = null): ?string
    {
        $localized = (array) data_get($this->metadata, 'localized', []);
        $candidates = [];
        if (filled($language)) {
            $candidates[] = data_get($localized, $language.'.description');
        }
        $candidates[] = $preparedFallback;
        $candidates[] = $this->description;
        if (! filled($language)) {
            foreach ($localized as $variant) {
                $candidates[] = data_get($variant, 'description');
            }
        }

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && filled(trim(strip_tags((string) $candidate)))) {
                return trim((string) $candidate);
            }
        }

        return null;
    }

    private function validatedPublicImageUrl(mixed $url): ?string
    {

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
                ? $url
                : null;
    }

    private function canonicalProductUrl(string $url): ?string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['lang']);
        ksort($query);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = '/'.ltrim(rtrim((string) ($parts['path'] ?? '/'), '/'), '/');

        return $scheme.'://'.$host.$port.$path.($query === [] ? '' : '?'.http_build_query($query));
    }
}
