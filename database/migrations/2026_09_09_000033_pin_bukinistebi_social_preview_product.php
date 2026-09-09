<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PREVIEW_URL = 'https://bukinistebi.ge/books/ar-damitserot-friadi/2492';

    public function up(): void
    {
        $pinnedAgents = [];
        DB::table('products')->orderBy('id')->chunkById(500, function ($products) use (&$pinnedAgents): void {
            foreach ($products as $product) {
                $agentId = (int) $product->agent_id;
                if (! isset($pinnedAgents[$agentId]) && $this->metadataContainsPreviewUrl($product->metadata)) {
                    $this->setPreviewUrl($agentId, self::PREVIEW_URL);
                    $pinnedAgents[$agentId] = true;
                }
            }
        }, 'id', 'id');
    }

    public function down(): void
    {
        DB::table('agents')->orderBy('id')->get(['id', 'settings'])->each(function ($agent): void {
            $settings = $this->settings($agent->settings);
            if (($settings['social_preview_product_url'] ?? null) !== self::PREVIEW_URL) {
                return;
            }
            unset($settings['social_preview_product_url']);
            DB::table('agents')->where('id', $agent->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        });
    }

    private function setPreviewUrl(int $agentId, string $url): void
    {
        $agent = DB::table('agents')->where('id', $agentId)->first(['settings']);
        if (! $agent) {
            return;
        }
        $settings = $this->settings($agent->settings);
        $settings['social_preview_product_url'] = $url;
        DB::table('agents')->where('id', $agentId)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    private function metadataContainsPreviewUrl(mixed $metadata): bool
    {
        $data = $this->settings($metadata);
        $urls = [data_get($data, 'product_url')];
        foreach ((array) data_get($data, 'localized', []) as $localized) {
            $urls[] = data_get($localized, 'product_url');
        }

        return collect($urls)->contains(
            fn ($url): bool => is_string($url) && $this->canonicalUrl($url) === $this->canonicalUrl(self::PREVIEW_URL),
        );
    }

    /** @return array<string, mixed> */
    private function settings(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function canonicalUrl(string $url): ?string
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
        $path = '/'.ltrim(rtrim((string) ($parts['path'] ?? '/'), '/'), '/');

        return $scheme.'://'.$host.$path.($query === [] ? '' : '?'.http_build_query($query));
    }
};
