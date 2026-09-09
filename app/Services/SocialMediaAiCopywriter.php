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
        $selectableKeys = array_values(array_diff(array_keys($facts), ['description']));
        $schemaKeys = $selectableKeys !== [] ? $selectableKeys : ['none'];
        $content = [[
            'type' => 'input_text',
            'text' => $this->prompt($post, $facts),
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
                'name' => 'social_media_layout',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'creative_copy' => ['type' => 'string', 'maxLength' => 800],
                        'selected_facts' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => $schemaKeys],
                            'maxItems' => 4,
                        ],
                        'cta' => ['type' => 'string', 'enum' => ['details', 'discover', 'visit']],
                    ],
                    'required' => ['creative_copy', 'selected_facts', 'cta'],
                ],
            ]],
        ])->throw()->json();

        $raw = collect($response['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])
            ->firstWhere('type', 'output_text')['text'] ?? null;
        $layout = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($layout)) {
            throw new \RuntimeException('AI Copywriter returned an invalid caption.');
        }
        $verification = $this->sanitizeCreativeCopy(
            $post,
            trim(strip_tags((string) ($layout['creative_copy'] ?? ''))),
            $facts,
            $model,
        );
        $layout['creative_copy'] = $verification['creative_copy'];
        $caption = $this->renderVerifiedCaption($post, $facts, $layout);

        Log::info('Social media AI caption generated.', [
            'agent_id' => $post->agent_id,
            'post_id' => $post->id,
            'provider' => $post->provider,
            'model' => $model,
            'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0) + $verification['input_tokens'],
            'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0) + $verification['output_tokens'],
        ]);

        return $caption;
    }

    /** @param array<string, array{label: string, value: string, identity: bool}> $facts */
    private function prompt(SocialMediaPost $post, array $facts): string
    {
        $tone = match ($post->schedule->ai_tone) {
            'creative' => 'Creative: vivid and memorable, with tasteful wordplay.',
            'academic' => 'Informative: precise, composed, and educational without sounding dry.',
            default => 'Simple: clear, warm, concise, and easy to understand.',
        };
        $language = $post->language ?: 'the same language used by the product title and description';
        $factPayload = collect($facts)->map(fn (array $fact): array => [
            'label' => $fact['label'],
            'value' => $fact['value'],
        ])->all();
        $encodedFacts = json_encode($factPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Select a safe layout for one {$post->provider} product post.
Tone: {$tone}
Language: {$language}.
Write original, channel-specific creative_copy in the requested tone. It may paraphrase the verified description, but every factual assertion must be fully supported by the verified facts. Never add or substitute a person, brand, maker, origin, material, size, weight, date, price, benefit, award, availability, or other product detail. Select up to four fact keys worth displaying verbatim; their values will be rendered server-side.
Do not copy the verified description verbatim. Use it as writing evidence. Treat the image only as visual inspiration and never infer product facts from it.
Verified product title: {$post->title}
Verified fact keys and values: {$encodedFacts}
PROMPT;
    }

    /** @return array<string, array{label: string, value: string, identity: bool}> */
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
        $this->addFact($facts, 'description', 'Description', $post->description ?: $product->description);
        $this->addFact($facts, 'category', 'Category', $localized['category'] ?? $product->category);
        $currency = strtoupper((string) data_get($metadata, 'currency', 'GEL'));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'GEL';
        $this->addFact($facts, 'price', 'Price', number_format((float) $product->price, 2, '.', ' ').' '.$currency);

        foreach (['author', 'creator', 'brand', 'manufacturer', 'model', 'sku', 'isbn'] as $key) {
            $value = $key === 'sku' ? $product->sku : data_get($metadata, $key);
            $this->addFact($facts, $key, Str::headline($key), $value, in_array($key, ['author', 'creator', 'brand', 'manufacturer', 'model'], true));
        }
        foreach ((array) data_get($metadata, 'attributes', []) as $index => $attribute) {
            $this->addFact($facts, 'attribute_'.$index, '', $attribute);
        }

        return array_slice($facts, 0, 30, true);
    }

    /** @param array<string, array{label: string, value: string, identity: bool}> $facts */
    private function addFact(array &$facts, string $key, string $label, mixed $value, bool $identity = false): void
    {
        if (! is_scalar($value) || is_bool($value)) {
            return;
        }
        $clean = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');
        if ($clean === '') {
            return;
        }
        $facts[$key] = ['label' => $label, 'value' => Str::limit($clean, 700, '…'), 'identity' => $identity];
    }

    /**
     * @param  array<string, array{label: string, value: string, identity: bool}>  $facts
     * @param  array<string, mixed>  $layout
     */
    private function renderVerifiedCaption(SocialMediaPost $post, array $facts, array $layout): string
    {
        $callsToAction = $this->copy($post, 'ctas');
        $creativeCopy = trim(strip_tags((string) ($layout['creative_copy'] ?? '')));
        $cta = $callsToAction[(string) ($layout['cta'] ?? '')] ?? null;
        if ($creativeCopy === '' || mb_strlen($creativeCopy) > 800 || ! is_string($cta)) {
            throw new \RuntimeException('AI Copywriter returned an invalid layout.');
        }

        $selected = collect((array) ($layout['selected_facts'] ?? []))
            ->filter(fn ($key): bool => is_string($key) && isset($facts[$key]))
            ->unique()
            ->take(4)
            ->values();
        $identityKeys = collect($facts)->filter(fn (array $fact): bool => $fact['identity'])->keys();
        $factKeys = $identityKeys->merge($selected)->unique()->values();
        $lines = [$creativeCopy, '', '📦 '.$post->title];
        foreach ($factKeys as $key) {
            $fact = $facts[$key];
            $label = $this->factLabel($post, (string) $key, $fact['label']);
            $lines[] = $label !== '' ? $label.': '.$fact['value'] : $fact['value'];
        }
        $lines[] = '';
        $lines[] = $cta;
        $lines[] = $post->product_url;
        $hashtags = $this->hashtags($post, $facts);
        if ($hashtags !== '') {
            $lines[] = '';
            $lines[] = $hashtags;
        }
        $caption = trim(implode("\n", $lines));
        $limit = SocialMediaTemplateRenderer::CHARACTER_LIMITS[$post->provider] ?? 2200;
        if (mb_strlen($caption) > $limit) {
            throw new \RuntimeException('Verified social media caption exceeds the provider limit.');
        }

        return $caption;
    }

    /**
     * @param  array<string, array{label: string, value: string, identity: bool}>  $facts
     * @return array{creative_copy: string, input_tokens: int, output_tokens: int}
     */
    private function sanitizeCreativeCopy(SocialMediaPost $post, string $creativeCopy, array $facts, string $model): array
    {
        if ($creativeCopy === '' || mb_strlen($creativeCopy) > 800) {
            throw new \RuntimeException('AI Copywriter returned invalid creative copy.');
        }
        $evidence = collect($facts)->map(fn (array $fact): array => [
            'label' => $fact['label'],
            'value' => $fact['value'],
        ])->all();
        $response = $this->client()->post('/responses', [
            'model' => $model,
            'reasoning' => ['effort' => 'none'],
            'max_output_tokens' => 220,
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Audit only the proposed creative copy against the verified product evidence. Check every explicit and implied factual claim, including identity, creator, brand, manufacturer, category, composition, origin, material, dimensions, size, weight, date, award, price, availability, benefit, and product description. Marketing opinion is allowed only when it does not assert an unsupported product fact. A changed, translated, inferred, or better-known identity is unsupported. If a sentence or line contains any unsupported claim, return that entire sentence or line copied character-for-character in unsupported_fragments. Do not rewrite it and do not return partial words. Verified title: '.json_encode($post->title, JSON_UNESCAPED_UNICODE).'. Verified facts: '.json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Proposed creative copy: '.json_encode($creativeCopy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        $safeCopy = $creativeCopy;
        foreach ($audit['unsupported_fragments'] as $fragment) {
            if (! is_string($fragment) || trim($fragment) === '' || ! str_contains($safeCopy, $fragment)) {
                throw new \RuntimeException('AI Copywriter returned an invalid factual audit fragment.');
            }
            $safeCopy = str_replace($fragment, '', $safeCopy);
        }
        if (($audit['supported'] === true) !== ($audit['unsupported_fragments'] === [])) {
            throw new \RuntimeException('AI Copywriter returned an inconsistent factual audit.');
        }
        $safeCopy = trim(preg_replace("/[ \t]+\n/u", "\n", preg_replace('/[ \t]{2,}/u', ' ', $safeCopy) ?? '') ?? '');
        if ($safeCopy === '') {
            $safeCopy = $this->safeCreativeOpening($post);
        }

        return [
            'creative_copy' => $safeCopy,
            'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0),
        ];
    }

    private function safeCreativeOpening(SocialMediaPost $post): string
    {
        $georgian = str_contains(Str::lower((string) $post->language), 'georgian')
            || str_contains((string) $post->language, 'ქართული')
            || Str::lower((string) $post->language) === 'ka';

        return $georgian
            ? '✨ ახალი არჩევანი ჩვენი კატალოგიდან.'
            : '✨ A fresh choice from our catalog.';
    }

    /** @return array<string, string> */
    private function copy(SocialMediaPost $post, string $section): array
    {
        $georgian = str_contains(Str::lower((string) $post->language), 'georgian')
            || str_contains((string) $post->language, 'ქართული')
            || Str::lower((string) $post->language) === 'ka';
        $copy = $georgian ? [
            'openings' => [
                'direct' => '✨ გაიცანით ეს პროდუქტი.',
                'spotlight' => '✨ ყურადღების ღირსი არჩევანი ჩვენი კატალოგიდან.',
                'discovery' => '✨ აღმოაჩინეთ ჩვენი კატალოგის ეს შეთავაზება.',
            ],
            'ctas' => [
                'details' => 'დეტალური ინფორმაცია იხილეთ პროდუქტის გვერდზე:',
                'discover' => 'გაეცანით პროდუქტს სრულად:',
                'visit' => 'ეწვიეთ პროდუქტის გვერდს:',
            ],
        ] : [
            'openings' => [
                'direct' => '✨ Meet this product.',
                'spotlight' => '✨ A noteworthy choice from our catalog.',
                'discovery' => '✨ Discover this offer from our catalog.',
            ],
            'ctas' => [
                'details' => 'See the product page for full details:',
                'discover' => 'Discover the product:',
                'visit' => 'Visit the product page:',
            ],
        ];

        return $copy[$section];
    }

    private function factLabel(SocialMediaPost $post, string $key, string $fallback): string
    {
        $georgian = str_contains(Str::lower((string) $post->language), 'georgian')
            || str_contains((string) $post->language, 'ქართული')
            || Str::lower((string) $post->language) === 'ka';
        if (! $georgian) {
            return $fallback;
        }

        return [
            'description' => 'აღწერა',
            'category' => 'კატეგორია',
            'price' => 'ფასი',
            'author' => 'ავტორი',
            'creator' => 'შემქმნელი',
            'brand' => 'ბრენდი',
            'manufacturer' => 'მწარმოებელი',
            'model' => 'მოდელი',
            'sku' => 'SKU',
            'isbn' => 'ISBN',
        ][$key] ?? $fallback;
    }

    /** @param array<string, array{label: string, value: string, identity: bool}> $facts */
    private function hashtags(SocialMediaPost $post, array $facts): string
    {
        if (! in_array($post->provider, ['instagram', 'linkedin'], true)) {
            return '';
        }
        $values = [$post->title, data_get($facts, 'category.value'), $post->agent->business_name];

        return collect($values)
            ->filter(fn ($value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(function ($value): string {
                $tag = preg_replace('/[^\pL\pN]+/u', '', (string) $value) ?? '';

                return $tag === '' ? '' : '#'.$tag;
            })
            ->filter()
            ->unique()
            ->take(3)
            ->implode(' ');
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
