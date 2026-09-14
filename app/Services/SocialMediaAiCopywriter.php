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
        $recentCaptions = $this->recentCaptions($post);
        $generation = $this->requestCaption($post, $facts, $model, $recentCaptions);
        $verification = $this->sanitizeCaption($post, $generation['caption'], $facts, $recentCaptions, $model);
        $diversityFailure = $this->diversityFailure($verification['caption'], $recentCaptions, $verification);

        if (! $this->hasMeaningfulCopy($verification['caption']) || $diversityFailure !== null) {
            $retry = $this->requestCaption(
                $post,
                $facts,
                $model,
                $recentCaptions,
                $diversityFailure === null
                    ? 'The previous draft lost its meaningful copy during factual review. Write a completely new, product-specific caption using only the verified evidence and omit every uncertain attribute.'
                    : 'The previous draft was too similar to earlier posts: '.$diversityFailure.' Use a fundamentally different opening, narrative angle, sentence construction, progression, and invitation. Do not merely replace words with synonyms.',
            );
            $retryVerification = $this->sanitizeCaption($post, $retry['caption'], $facts, $recentCaptions, $model);
            $generation['input_tokens'] += $retry['input_tokens'];
            $generation['output_tokens'] += $retry['output_tokens'];
            $verification['input_tokens'] += $retryVerification['input_tokens'];
            $verification['output_tokens'] += $retryVerification['output_tokens'];
            $verification['caption'] = $retryVerification['caption'];
            $verification['distinct_from_recent'] = $retryVerification['distinct_from_recent'];
            $verification['similarity_reason'] = $retryVerification['similarity_reason'];
        }

        if (! $this->hasMeaningfulCopy($verification['caption']) || $this->diversityFailure($verification['caption'], $recentCaptions, $verification) !== null) {
            throw new \RuntimeException('AI Copywriter could not produce grounded, structurally distinct product-specific copy.');
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
    private function requestCaption(SocialMediaPost $post, array $facts, string $model, array $recentCaptions, ?string $retryInstruction = null): array
    {
        $content = [[
            'type' => 'input_text',
            'text' => $this->prompt($post, $facts, $recentCaptions, $retryInstruction),
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
    private function prompt(SocialMediaPost $post, array $facts, array $recentCaptions, ?string $retryInstruction = null): string
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
        $encodedRecentCaptions = json_encode(array_slice($recentCaptions, 0, 12), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Write one {$platform}
Tone: {$tone}
Language: {$language}.
Write the complete caption as natural, original marketing copy inspired by this specific product. Capture the distinctive subject, idea, use, or mood supported by the verified description instead of announcing a generic catalog item. When a description exists, ground at least two natural sentences in its meaning without copying it verbatim. Use a few relevant emojis naturally and finish with a clear invitation. Put any hashtags on a separate final line.
Every product must have its own creative concept and composition. The recent captions below are negative references, not style examples: do not reuse their opening words, hook formula, sentence-by-sentence scaffold, rhetorical progression, emoji placement, closing invitation, or hashtag pattern. Do not preserve an earlier structure while merely swapping product details or synonyms. Silently choose a different product-specific angle and a visibly different opening and paragraph structure before writing.
Never use stock phrases such as "a new choice from our catalog", "new from our catalog", or "discover this product". Never output database-style labels or a specification list such as "Category:", "Price:", or a generic taxonomy value such as "Books" merely to fill space.
Use only the verified evidence below. Do not invent or substitute a person, author, brand, maker, origin, material, size, weight, date, price, benefit, award, availability, delivery term, review, or other product detail. Treat the image only as visual inspiration and never infer factual claims from it. Do not include a URL; the server appends the exact verified product link.

Business: {$post->agent->business_name}
Product title: {$post->title}
Verified evidence: {$encodedFacts}
Recent captions that must not be imitated: {$encodedRecentCaptions}
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
     * @return array{caption: string, distinct_from_recent: bool, similarity_reason: string, input_tokens: int, output_tokens: int}
     */
    private function sanitizeCaption(SocialMediaPost $post, string $caption, array $facts, array $recentCaptions, string $model): array
    {
        $response = $this->client()->post('/responses', [
            'model' => $model,
            'reasoning' => ['effort' => 'none'],
            'max_output_tokens' => 260,
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Perform two independent audits. First, audit the proposed social caption only for concrete, checkable product claims. Faithful paraphrase of the verified description, expressive framing, mood, rhetorical questions, invitations, and relevant hashtags are allowed and must not be rejected merely because they are not verbatim. Reject a full sentence or line only when it states a concrete product fact unsupported by or contradictory to the verified evidence, including an invented identity, creator, brand, manufacturer, price, material, dimensions, origin, date, award, availability, benefit, or specification. Return each rejected sentence or line character-for-character in unsupported_fragments; never return partial words and never rewrite safe creative copy. Second, compare the proposed caption with the recent captions. Set distinct_from_recent to false when it repeats an opening formula, hook construction, sentence-by-sentence scaffold, rhetorical progression, or closing pattern even if product nouns and adjectives were replaced with synonyms. Shared verified facts, isolated common words, URLs, and hashtags alone do not make a caption similar. Give a concise similarity_reason when it is not distinct, otherwise return an empty string. Verified evidence: '.json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Recent captions: '.json_encode(array_slice($recentCaptions, 0, 12), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Proposed caption: '.json_encode($caption, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
                        'distinct_from_recent' => ['type' => 'boolean'],
                        'similarity_reason' => ['type' => 'string', 'maxLength' => 300],
                    ],
                    'required' => ['supported', 'unsupported_fragments', 'distinct_from_recent', 'similarity_reason'],
                ],
            ]],
        ])->throw()->json();
        $raw = collect($response['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])
            ->firstWhere('type', 'output_text')['text'] ?? null;
        $audit = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($audit)
            || ! is_bool($audit['supported'] ?? null)
            || ! is_array($audit['unsupported_fragments'] ?? null)
            || ! is_bool($audit['distinct_from_recent'] ?? null)
            || ! is_string($audit['similarity_reason'] ?? null)) {
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
        if (($audit['distinct_from_recent'] === true) !== (trim($audit['similarity_reason']) === '')) {
            throw new \RuntimeException('AI Copywriter returned an inconsistent diversity audit.');
        }

        return [
            'caption' => trim(preg_replace('/\R{3,}/u', "\n\n", $safeCaption) ?? ''),
            'distinct_from_recent' => $audit['distinct_from_recent'],
            'similarity_reason' => trim($audit['similarity_reason']),
            'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0),
        ];
    }

    /** @return list<string> */
    private function recentCaptions(SocialMediaPost $post): array
    {
        return SocialMediaPost::query()
            ->where('agent_id', $post->agent_id)
            ->whereKeyNot($post->id)
            ->whereNotNull('ai_generated_at')
            ->when($post->language, fn ($query, string $language) => $query->where('language', $language), fn ($query) => $query->whereNull('language'))
            ->latest('ai_generated_at')
            ->limit(100)
            ->pluck('caption')
            ->filter(fn ($caption): bool => is_string($caption) && trim($caption) !== '')
            ->map(fn (string $caption): string => Str::limit($this->captionBody($caption), 700, '…'))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $recentCaptions
     * @param  array{distinct_from_recent: bool, similarity_reason: string}  $verification
     */
    private function diversityFailure(string $caption, array $recentCaptions, array $verification): ?string
    {
        if (! $verification['distinct_from_recent']) {
            return $verification['similarity_reason'] ?: 'The semantic diversity audit found a repeated creative structure.';
        }

        $candidateWords = $this->words($this->captionBody($caption));
        if (count($candidateWords) < 2) {
            return null;
        }

        foreach ($recentCaptions as $recentCaption) {
            $recentWords = $this->words($this->captionBody($recentCaption));
            if (count($recentWords) < 2) {
                continue;
            }
            if (array_slice($candidateWords, 0, 2) === array_slice($recentWords, 0, 2)) {
                return 'The opening repeats the same first words as a recent caption.';
            }
            if ($this->jaccard($this->ngrams($candidateWords, 2), $this->ngrams($recentWords, 2)) >= 0.48) {
                return 'The wording and sentence progression are too close to a recent caption.';
            }
        }

        return null;
    }

    private function captionBody(string $caption): string
    {
        $caption = preg_replace('~https?://\S+|#[\pL\pN_]+~u', ' ', strip_tags($caption)) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $caption) ?? '');
    }

    /** @return list<string> */
    private function words(string $caption): array
    {
        preg_match_all('/[\pL\pN]+/u', mb_strtolower($caption), $matches);

        return array_values($matches[0] ?? []);
    }

    /** @param list<string> $words @return list<string> */
    private function ngrams(array $words, int $size): array
    {
        $ngrams = [];
        for ($index = 0; $index <= count($words) - $size; $index++) {
            $ngrams[] = implode(' ', array_slice($words, $index, $size));
        }

        return array_values(array_unique($ngrams));
    }

    /** @param list<string> $left @param list<string> $right */
    private function jaccard(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($left, $right));
        $union = count(array_unique(array_merge($left, $right)));

        return $union === 0 ? 0.0 : $intersection / $union;
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
