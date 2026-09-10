<?php

namespace App\Services;

use App\Models\SocialMediaPost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocialMediaAiCopywriter
{
    public function generate(SocialMediaPost $post): string
    {
        $model = (string) config('services.openai.social_media_model', 'gpt-5.6-luna');
        $facts = $this->verifiedFacts($post);
        $generation = $this->requestCaption($post, $facts, $model);
        $verification = $this->sanitizeCaption($post, $generation['caption'], $facts, $model);

        if (! $this->hasMeaningfulCopy($verification['caption'])) {
            $retry = $this->requestCaption(
                $post,
                $facts,
                $model,
                'The previous draft lost its meaningful copy during factual review. Write a completely new, product-specific caption using only the verified evidence and omit every uncertain attribute.',
            );
            $retryVerification = $this->sanitizeCaption($post, $retry['caption'], $facts, $model);
            $generation['input_tokens'] += $retry['input_tokens'];
            $generation['output_tokens'] += $retry['output_tokens'];
            $verification['input_tokens'] += $retryVerification['input_tokens'];
            $verification['output_tokens'] += $retryVerification['output_tokens'];
            $verification['caption'] = $retryVerification['caption'];
        }

        if (! $this->hasMeaningfulCopy($verification['caption'])) {
            throw new \RuntimeException('AI Copywriter could not produce grounded product-specific copy.');
        }

        $caption = $this->renderCaption($post, $verification['caption']);

        Log::info('Social media AI caption generated.', [
            'agent_id' => $post->agent_id,
            'post_id' => $post->id,
            'provider' => $post->provider,
            'model' => $model,
            'input_tokens' => $generation['input_tokens'] + $verification['input_tokens'],
            'output_tokens' => $generation['output_tokens'] + $verification['output_tokens'],
        ]);

        return $caption;
    }

    /**
     * @param  array<string, array{label: string, value: string}>  $facts
     * @return array{caption: string, input_tokens: int, output_tokens: int}
     */
    private function requestCaption(SocialMediaPost $post, array $facts, string $model, ?string $retryInstruction = null): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => $this->prompt($post, $facts, $retryInstruction),
        ]];
        if ($this->publicHttpUrl($post->image_url)) {
            $content[] = ['type' => 'input_image', 'image_url' => $post->image_url, 'detail' => 'low'];
        }

        $response = $this->client()->post('/responses', [
            'model' => $model,
            'reasoning' => ['effort' => 'none'],
            'max_output_tokens' => 420,
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'social_media_caption',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['caption' => ['type' => 'string', 'maxLength' => 1600]],
                    'required' => ['caption'],
                ],
            ]],
        ])->throw()->json();

        $raw = collect($response['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])
            ->firstWhere('type', 'output_text')['text'] ?? null;
        $caption = is_string($raw) ? trim((string) data_get(json_decode($raw, true), 'caption')) : '';
        if ($caption === '' || mb_strlen($caption) > 1600) {
            throw new \RuntimeException('AI Copywriter returned an invalid caption.');
        }

        return [
            'caption' => $caption,
            'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0),
        ];
    }

    /** @param array<string, array{label: string, value: string}> $facts */
    private function prompt(SocialMediaPost $post, array $facts, ?string $retryInstruction = null): string
    {
        $tone = match ($post->schedule->ai_tone) {
            'creative' => 'Creative: vivid and memorable, with tasteful wordplay.',
            'academic' => 'Informative: precise, composed, and educational without sounding dry.',
            default => 'Simple: clear, warm, concise, and easy to understand.',
        };
        $platform = match ($post->provider) {
            'instagram' => 'Instagram caption with natural line breaks and 3-6 relevant hashtags.',
            'linkedin' => 'LinkedIn company Page post with a professional opening, readable short paragraphs, and 2-4 relevant hashtags.',
            default => 'Facebook post with a conversational opening and no hashtag stuffing.',
        };
        $language = $post->language ?: 'the same language used by the product title and description';
        $encodedFacts = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Write one {$platform}
Tone: {$tone}
Language: {$language}.
Write the complete caption as natural, original marketing copy inspired by this specific product. Capture the distinctive subject, idea, use, or mood supported by the verified description instead of announcing a generic catalog item. When a description exists, ground at least two natural sentences in its meaning without copying it verbatim. Use a few relevant emojis naturally and finish with a clear invitation. Put any hashtags on a separate final line.
Never use stock phrases such as "a new choice from our catalog", "new from our catalog", or "discover this product". Never output database-style labels or a specification list such as "Category:", "Price:", or a generic taxonomy value such as "Books" merely to fill space.
Use only the verified evidence below. Do not invent or substitute a person, author, brand, maker, origin, material, size, weight, date, price, benefit, award, availability, delivery term, review, or other product detail. Treat the image only as visual inspiration and never infer factual claims from it. Do not include a URL; the server appends the exact verified product link.

Business: {$post->agent->business_name}
Product title: {$post->title}
Verified evidence: {$encodedFacts}
{$retryInstruction}
PROMPT;
    }

    /** @return array<string, array{label: string, value: string}> */
    private function verifiedFacts(SocialMediaPost $post): array
    {
        $post->loadMissing('product');
        $product = $post->product;
        if (! $product) {
            return [];
        }
        $localized = $post->language
            ? (array) data_get($product->metadata, 'localized.'.$post->language, [])
            : [];
        $metadata = array_replace($product->metadata ?? [], $localized);
        $facts = [];
        $this->addFact($facts, 'title', 'Product title', $post->title);
        $this->addFact($facts, 'description', 'Description', $post->description ?: $product->description);
        $this->addFact($facts, 'category', 'Category', $localized['category'] ?? $product->category);
        $currency = strtoupper((string) data_get($metadata, 'currency', 'GEL'));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'GEL';
        $this->addFact($facts, 'price', 'Price', number_format((float) $product->price, 2, '.', ' ').' '.$currency);
        foreach (['author', 'creator', 'brand', 'manufacturer', 'model', 'sku', 'isbn'] as $key) {
            $value = $key === 'sku' ? $product->sku : data_get($metadata, $key);
            $this->addFact($facts, $key, Str::headline($key), $value);
        }
        foreach ((array) data_get($metadata, 'attributes', []) as $index => $attribute) {
            $this->addFact($facts, 'attribute_'.$index, 'Attribute', $attribute);
        }

        return array_slice($facts, 0, 30, true);
    }

    /** @param array<string, array{label: string, value: string}> $facts */
    private function addFact(array &$facts, string $key, string $label, mixed $value): void
    {
        if (! is_scalar($value) || is_bool($value)) {
            return;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($clean !== '') {
            $facts[$key] = ['label' => $label, 'value' => Str::limit($clean, 700, '…')];
        }
    }

    /**
     * @param  array<string, array{label: string, value: string}>  $facts
     * @return array{caption: string, input_tokens: int, output_tokens: int}
     */
    private function sanitizeCaption(SocialMediaPost $post, string $caption, array $facts, string $model): array
    {
        $response = $this->client()->post('/responses', [
            'model' => $model,
            'reasoning' => ['effort' => 'none'],
            'max_output_tokens' => 260,
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Audit the proposed social caption only for concrete, checkable product claims. Faithful paraphrase of the verified description, expressive framing, mood, rhetorical questions, invitations, and relevant hashtags are allowed and must not be rejected merely because they are not verbatim. Reject a full sentence or line only when it states a concrete product fact unsupported by or contradictory to the verified evidence, including an invented identity, creator, brand, manufacturer, price, material, dimensions, origin, date, award, availability, benefit, or specification. Return each rejected sentence or line character-for-character in unsupported_fragments; never return partial words and never rewrite safe creative copy. Verified evidence: '.json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Proposed caption: '.json_encode($caption, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]],
            ]],
            'text' => ['format' => [
                'type' => 'json_schema',
                'name' => 'social_media_fact_audit',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'supported' => ['type' => 'boolean'],
                        'unsupported_fragments' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
                    ],
                    'required' => ['supported', 'unsupported_fragments'],
                ],
            ]],
        ])->throw()->json();
        $raw = collect($response['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])
            ->firstWhere('type', 'output_text')['text'] ?? null;
        $audit = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($audit) || ! is_bool($audit['supported'] ?? null) || ! is_array($audit['unsupported_fragments'] ?? null)) {
            throw new \RuntimeException('AI Copywriter returned an invalid factual audit.');
        }
        $safeCaption = $caption;
        foreach ($audit['unsupported_fragments'] as $fragment) {
            if (! is_string($fragment) || trim($fragment) === '' || ! str_contains($safeCaption, $fragment)) {
                throw new \RuntimeException('AI Copywriter returned an invalid factual audit fragment.');
            }
            $safeCaption = str_replace($fragment, '', $safeCaption);
        }
        if (($audit['supported'] === true) !== ($audit['unsupported_fragments'] === [])) {
            throw new \RuntimeException('AI Copywriter returned an inconsistent factual audit.');
        }

        return [
            'caption' => trim(preg_replace('/\R{3,}/u', "\n\n", $safeCaption) ?? ''),
            'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0),
        ];
    }

    private function hasMeaningfulCopy(string $caption): bool
    {
        $copy = preg_replace('~https?://\S+|#[\pL\pN_]+~u', '', strip_tags($caption)) ?? '';
        $copy = preg_replace('/[^\pL\pN]+/u', '', $copy) ?? '';

        return mb_strlen($copy) >= 20;
    }

    private function renderCaption(SocialMediaPost $post, string $caption): string
    {
        $caption = preg_replace('~https?://\S+~u', '', strip_tags($caption)) ?? '';
        $lines = collect(preg_split('/\R/u', $caption) ?: [])->map(fn ($line): string => trim($line));
        $hashtags = [];
        while ($lines->isNotEmpty() && ($lines->last() === '' || str_starts_with($lines->last(), '#'))) {
            $line = $lines->pop();
            if ($line !== '') {
                array_unshift($hashtags, $line);
            }
        }
        $body = trim($lines->implode("\n"));
        $parts = [$body, $post->product_url];
        if ($hashtags !== []) {
            $parts[] = implode("\n", $hashtags);
        }
        $rendered = trim(implode("\n\n", $parts));
        $limit = SocialMediaTemplateRenderer::CHARACTER_LIMITS[$post->provider] ?? 2200;
        if (mb_strlen($rendered) > $limit) {
            throw new \RuntimeException('Verified social media caption exceeds the provider limit.');
        }

        return $rendered;
    }

    private function client(): PendingRequest
    {
        $timeout = (int) config('services.openai.timeout', 30);

        return Http::baseUrl('https://api.openai.com/v1')
            ->withToken(config('services.openai.key'))
            ->acceptJson()
            ->connectTimeout((int) config('services.openai.connect_timeout', 5))
            ->timeout($timeout);
    }

    private function publicHttpUrl(mixed $url): bool
    {
        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
