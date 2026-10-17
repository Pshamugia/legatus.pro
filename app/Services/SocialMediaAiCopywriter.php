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

        return $this->generateWithModel($post, $model);
    }

    public function generateFallback(SocialMediaPost $post): string
    {
        $primaryModel = trim((string) config('services.openai.social_media_model', 'gpt-5.6-luna'));
        $fallbackModel = trim((string) config('services.openai.fallback_model', 'gpt-5.6-sol'));
        if (! config('services.openai.fallback_enabled', true)
            || $fallbackModel === ''
            || $fallbackModel === $primaryModel) {
            throw new \RuntimeException('A distinct social copy fallback model is not available.');
        }

        return $this->generateWithModel(
            $post,
            $fallbackModel,
            'The primary social copywriter could not produce an acceptable caption. Start again from the verified evidence. Write restrained but natural product-specific copy; preserve every concrete fact, do not embellish sparse evidence, and never return raw catalog fields.',
        );
    }

    private function generateWithModel(SocialMediaPost $post, string $model, ?string $initialInstruction = null): string
    {
        $facts = $this->verifiedFacts($post);
        $description = (string) data_get($facts, 'description.value', '');
        if (blank($description)) {
            throw new \RuntimeException('AI Copywriter requires a verified product description and will not write from the title alone.');
        }
        if (! $this->descriptionAppearsComplete($description)) {
            throw new \RuntimeException('AI Copywriter requires a complete verified product description and will not write from a truncated source.');
        }
        $recentCaptions = $this->recentCaptions($post);
        $generation = $this->requestCaption($post, $facts, $model, $recentCaptions, $initialInstruction);
        $verification = $this->sanitizeCaption($post, $generation['caption'], $facts, $recentCaptions, $model);
        $diversityFailure = $this->diversityFailure($verification['caption'], $recentCaptions, $verification);
        $identityFailure = $this->identityFailure($verification);
        $groundingFailure = $this->descriptionGroundingFailure($verification['caption'], $facts);
        $protectedReferenceFailure = $this->protectedReferenceFailure($verification['caption'], $facts);
        $meaningfulFailure = ! $this->hasMeaningfulCopy($verification['caption']);
        $completionFailure = $this->completionFailure($verification['caption']);

        if ($meaningfulFailure || $completionFailure !== null || $diversityFailure !== null || $identityFailure !== null || $groundingFailure !== null || $protectedReferenceFailure !== null) {
            $retry = $this->requestCaption(
                $post,
                $facts,
                $model,
                $recentCaptions,
                $protectedReferenceFailure !== null
                    ? 'The previous draft abbreviated a verified title, name, brand, model, or other exact reference: '.$protectedReferenceFailure.' Write a new caption. If you mention a verified reference, reproduce its complete wording exactly; otherwise omit it. Never shorten an exact reference with an ellipsis or partial quote.'
                    : ($identityFailure !== null
                    ? 'The previous draft obscured or misrepresented the product’s verified nature: '.$identityFailure.' Write a new caption that makes its actual type, form, or purpose clear from the source description without inventing contents or benefits.'
                    : ($meaningfulFailure
                        ? 'The previous draft lost its meaningful copy during factual review. Write a completely new, product-specific caption using only the verified evidence and omit every uncertain attribute.'
                        : ($completionFailure !== null
                            ? 'The previous draft was incomplete: '.$completionFailure.' Rewrite the complete caption from the beginning. Every sentence and word must be finished; never stop mid-sentence or mid-word.'
                        : ($groundingFailure !== null
                            ? 'The previous draft was based on the title instead of the verified description: '.$groundingFailure.' Rewrite it around concrete details that are actually present in the description. Beautify and adapt those details for social media, but do not invent a plot, theme, symbolism, benefit, or product property.'
                            : 'The previous draft was too similar to earlier posts: '.$diversityFailure.' Use a fundamentally different opening, narrative angle, sentence construction, progression, and invitation. Do not merely replace words with synonyms.')))),
            );
            $retryVerification = $this->sanitizeCaption($post, $retry['caption'], $facts, $recentCaptions, $model);
            $generation['input_tokens'] += $retry['input_tokens'];
            $generation['output_tokens'] += $retry['output_tokens'];
            $verification['input_tokens'] += $retryVerification['input_tokens'];
            $verification['output_tokens'] += $retryVerification['output_tokens'];
            $verification['caption'] = $retryVerification['caption'];
            $verification['distinct_from_recent'] = $retryVerification['distinct_from_recent'];
            $verification['similarity_reason'] = $retryVerification['similarity_reason'];
            $verification['core_identity_preserved'] = $retryVerification['core_identity_preserved'];
            $verification['identity_reason'] = $retryVerification['identity_reason'];
        }

        if (! $this->hasMeaningfulCopy($verification['caption'])
            || $this->completionFailure($verification['caption']) !== null
            || $this->diversityFailure($verification['caption'], $recentCaptions, $verification) !== null
            || $this->identityFailure($verification) !== null
            || $this->descriptionGroundingFailure($verification['caption'], $facts) !== null
            || $this->protectedReferenceFailure($verification['caption'], $facts) !== null) {
            throw new \RuntimeException('AI Copywriter could not produce grounded, structurally distinct product-specific copy.');
        }

        $caption = $this->renderCaption($post, $verification['caption']);
        if (! $this->hasSubstantiveProductCopy($post, $caption)) {
            throw new \RuntimeException('AI Copywriter produced title-only or otherwise non-substantive copy.');
        }

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

    public function verifiedFallback(SocialMediaPost $post): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $post->title)) ?? '');
        if ($title === '') {
            throw new \RuntimeException('A verified product title is required for the social media fallback.');
        }

        $description = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $post->description)) ?? '');
        if ($description === '') {
            throw new \RuntimeException('A verified product description is required for the social media fallback; title-only publishing is forbidden.');
        }
        $body = $title."\n\n".$this->safeVerifiedDescription($description);

        try {
            $caption = $this->renderCaption($post, $body);
            if (! $this->hasSubstantiveProductCopy($post, $caption)) {
                throw new \RuntimeException('Verified fallback produced title-only or otherwise non-substantive copy.');
            }

            return $caption;
        } catch (\RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'exceeds the provider limit')) {
                throw $exception;
            }

            $limit = SocialMediaTemplateRenderer::CHARACTER_LIMITS[$post->provider] ?? 2200;
            $reserved = mb_strlen($title) + mb_strlen((string) $post->product_url) + 4;
            $excerpt = $this->verifiedDescriptionExcerpt(
                $this->safeVerifiedDescription($description),
                max(0, $limit - $reserved),
            );
            $caption = $this->renderCaption($post, $title."\n\n".$excerpt);
            if (! $this->hasSubstantiveProductCopy($post, $caption)) {
                throw new \RuntimeException('The verified product description cannot fit the provider limit without becoming title-only.', previous: $exception);
            }

            return $caption;
        }
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
            'threads' => 'Threads post under 500 characters with a conversational opening and at most 2 relevant hashtags.',
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
Write a complete response. Every sentence and word must be finished; never end mid-sentence, mid-word, after a dangling conjunction, or with a truncation marker.
Write the complete caption as natural, original marketing copy grounded primarily in the verified description; the title identifies the product but is not a source from which to guess its contents. Select at least two concrete, distinctive details from the description, preserve their factual meaning, and reshape them into fluent social-media copy instead of copying the full description or presenting a specification list. Make its verified type, form, or purpose clear to a reader; do not let an evocative title or mood replace what the item actually is. Capture only a distinctive subject, idea, use, or mood explicitly supported by the verified description. When the description is brief, use only the detail it supports and keep the copy concise: do not invent themes, contents, symbolism, plot, benefits, or abstract claims to reach a length. Use a few relevant emojis naturally and finish with a clear invitation. Put any hashtags on a separate final line.
Every product must have its own creative concept and composition. The recent captions below are negative references, not style examples: do not reuse their opening words, hook formula, sentence-by-sentence scaffold, rhetorical progression, emoji placement, closing invitation, or hashtag pattern. Do not preserve an earlier structure while merely swapping product details or synonyms. Silently choose a different product-specific angle and a visibly different opening and paragraph structure before writing.
Never use stock phrases such as "a new choice from our catalog", "new from our catalog", or "discover this product". Never output database-style labels or a specification list such as "Category:", "Price:", or a generic taxonomy value such as "Books" merely to fill space.
Use only the verified evidence below. Do not invent or substitute a person, author, brand, maker, origin, material, size, weight, date, price, benefit, award, availability, delivery term, review, or other product detail. Treat the image only as visual inspiration and never infer factual claims from it. Do not include a URL; the server appends the exact verified product link.
Titles, names, brands, models, SKUs, ISBNs, and text enclosed in quotation marks are exact references. You may omit an exact reference, but if you mention it, reproduce its complete verified wording without paraphrasing, shortening, partial quotation, or an ellipsis.

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
        $genres = array_values(array_filter((array) data_get($metadata, 'genres', []), 'is_scalar'));
        $this->addFact($facts, 'genres', 'Genres', implode(', ', $genres));
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
            $facts[$key] = ['label' => $label, 'value' => Str::limit($clean, 1600, '…')];
        }
    }

    /**
     * @param  array<string, array{label: string, value: string}>  $facts
     * @return array{caption: string, distinct_from_recent: bool, similarity_reason: string, core_identity_preserved: bool, identity_reason: string, input_tokens: int, output_tokens: int}
     */
    private function sanitizeCaption(SocialMediaPost $post, string $caption, array $facts, array $recentCaptions, string $model): array
    {
        $response = $this->client()->post('/responses', [
            'model' => $model,
            'reasoning' => ['effort' => 'none'],
            'max_output_tokens' => 360,
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => 'Perform three independent audits. First, audit the proposed social caption only for concrete, checkable product claims. A faithful paraphrase of the verified description, rhetorical framing, invitations, and relevant hashtags are allowed, but product-specific claims about contents, plot, themes, symbolism, mood, purpose, benefits, or qualities must be traceable to the verified evidence. The product title alone never supports an inference about its contents. Reject a full sentence or line when it states or strongly implies a product fact unsupported by or contradictory to the verified evidence, including an invented identity, creator, brand, manufacturer, price, material, dimensions, origin, date, award, availability, plot, theme, symbolism, benefit, or specification. Return each rejected sentence or line character-for-character in unsupported_fragments; never return partial words and never rewrite safe creative copy. Second, compare the proposed caption with the recent captions. Set distinct_from_recent to false when it repeats an opening formula, hook construction, sentence-by-sentence scaffold, rhetorical progression, or closing pattern even if product nouns and adjectives were replaced with synonyms. Shared verified facts, isolated common words, URLs, and hashtags alone do not make a caption similar. Give a concise similarity_reason when it is not distinct, otherwise return an empty string. Third, verify that a reader can tell the product’s central type, form, or purpose established by the verified description or genre. Do not mistake an evocative title for the product type. Mark core_identity_preserved false when the caption omits or obscures that nature so it could reasonably be understood as another kind of product; for example, a poetry collection must not read like a set of academic essays, and a candle must not read like skincare. A natural paraphrase is fine; raw category labels are not required. If the source provides no specific product type, do not invent one and mark identity preserved unless the caption itself implies a contradictory type. Provide identity_reason only when false. Do not infer new facts. Verified evidence: '.json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Recent captions: '.json_encode(array_slice($recentCaptions, 0, 12), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'. Proposed caption: '.json_encode($caption, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
                        'core_identity_preserved' => ['type' => 'boolean'],
                        'identity_reason' => ['type' => 'string', 'maxLength' => 300],
                    ],
                    'required' => ['supported', 'unsupported_fragments', 'distinct_from_recent', 'similarity_reason', 'core_identity_preserved', 'identity_reason'],
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
            || ! is_string($audit['similarity_reason'] ?? null)
            || ! is_bool($audit['core_identity_preserved'] ?? null)
            || ! is_string($audit['identity_reason'] ?? null)) {
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
        if (($audit['core_identity_preserved'] === true) !== (trim($audit['identity_reason']) === '')) {
            throw new \RuntimeException('AI Copywriter returned an inconsistent product identity audit.');
        }

        return [
            'caption' => trim(preg_replace('/\R{3,}/u', "\n\n", $safeCaption) ?? ''),
            'distinct_from_recent' => $audit['distinct_from_recent'],
            'similarity_reason' => trim($audit['similarity_reason']),
            'core_identity_preserved' => $audit['core_identity_preserved'],
            'identity_reason' => trim($audit['identity_reason']),
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
            // Facebook and Instagram are two renditions of one product slot,
            // not two independent product campaigns. Treating the first
            // channel's freshly generated caption as a negative reference for
            // its sibling makes the second channel systematically fail the
            // diversity gate even though both must publish the same product.
            ->where(function ($query) use ($post): void {
                $query->where('social_media_schedule_id', '!=', $post->social_media_schedule_id)
                    ->orWhere('scheduled_for', '!=', $post->scheduled_for);
            })
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

    /** @param array{core_identity_preserved: bool, identity_reason: string} $verification */
    private function identityFailure(array $verification): ?string
    {
        return $verification['core_identity_preserved']
            ? null
            : ($verification['identity_reason'] ?: 'The central product type was not clear.');
    }

    /** @param array<string, array{label: string, value: string}> $facts */
    private function descriptionGroundingFailure(string $caption, array $facts): ?string
    {
        $description = (string) data_get($facts, 'description.value', '');
        if ($description === '') {
            return null;
        }

        $otherEvidence = collect($facts)
            ->except('description')
            ->pluck('value')
            ->implode(' ');
        $otherRoots = array_fill_keys($this->wordRoots($otherEvidence), true);
        $descriptionRoots = collect($this->wordRoots($description))
            ->reject(fn (string $root): bool => isset($otherRoots[$root]))
            ->unique()
            ->values();

        // When the source itself has fewer than two independent details, the
        // semantic factual audit remains the safety gate. A rich description,
        // however, may not be replaced with copy inferred from the title.
        if ($descriptionRoots->count() < 2) {
            return null;
        }

        $captionRoots = array_fill_keys($this->wordRoots($caption), true);
        $matches = $descriptionRoots
            ->filter(fn (string $root): bool => isset($captionRoots[$root]))
            ->count();

        return $matches >= 2
            ? null
            : 'The caption must preserve at least two distinct details from the verified description, not merely repeat or reinterpret the title.';
    }

    /** @param array<string, array{label: string, value: string}> $facts */
    private function protectedReferenceFailure(string $caption, array $facts): ?string
    {
        $protected = collect(['title', 'author', 'creator', 'brand', 'manufacturer', 'model', 'sku', 'isbn'])
            ->map(fn (string $key): string => trim((string) data_get($facts, $key.'.value', '')))
            ->filter();

        foreach ($facts as $fact) {
            $value = (string) ($fact['value'] ?? '');
            preg_match_all('/„([^“]+)“|“([^”]+)”|«([^»]+)»|"([^"]+)"/u', $value, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $protected->push(collect(array_slice($match, 1))->first(fn ($part): bool => trim((string) $part) !== ''));
            }
        }

        $normalizedCaption = $this->normalizeProtectedReference($caption);
        preg_match_all('/„([^“]+)“|“([^”]+)”|«([^»]+)»|"([^"]+)"/u', $caption, $quotedMatches, PREG_SET_ORDER);
        $quotedMentions = collect($quotedMatches)->map(function (array $match): string {
            $mention = collect(array_slice($match, 1))->first(fn ($part): bool => trim((string) $part) !== '');

            return $this->normalizeProtectedReference((string) $mention);
        })->filter();

        foreach ($protected->map(fn ($reference): string => $this->normalizeProtectedReference((string) $reference))->filter()->unique() as $reference) {
            if (Str::contains($normalizedCaption, $reference)) {
                continue;
            }

            $referenceWords = $this->words($reference);
            if (count($referenceWords) < 2) {
                continue;
            }
            $prefixWordCount = count($referenceWords) >= 3 ? 2 : 1;
            $prefix = implode(' ', array_slice($referenceWords, 0, $prefixWordCount));
            if (mb_strlen($prefix) < 5) {
                continue;
            }

            $quotedAbbreviation = $quotedMentions->first(function (string $mention) use ($reference, $prefix): bool {
                $mention = rtrim($mention, '.… ');

                return $mention !== ''
                    && Str::startsWith($reference, $mention)
                    && Str::startsWith($mention, $prefix);
            });
            $ellipsisAbbreviation = preg_match(
                '/(?<![\p{L}\p{N}])'.preg_quote($prefix, '/').'\s*(?:…|\.{3})/u',
                $normalizedCaption,
            ) === 1;

            if ($quotedAbbreviation !== null || $ellipsisAbbreviation) {
                return 'The complete verified reference is "'.$reference.'".';
            }
        }

        return null;
    }

    private function normalizeProtectedReference(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? ''));
    }

    /** @return list<string> */
    private function wordRoots(string $text): array
    {
        preg_match_all('/[\pL\pN]+/u', mb_strtolower(strip_tags($text)), $matches);

        return collect($matches[0] ?? [])
            ->filter(fn (string $word): bool => mb_strlen($word) >= 5)
            ->map(fn (string $word): string => mb_substr($word, 0, 5))
            ->unique()
            ->values()
            ->all();
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

    public function hasSubstantiveProductCopy(SocialMediaPost $post, string $caption): bool
    {
        $body = preg_replace('~https?://\S+|#[\pL\pN_]+~u', ' ', strip_tags($caption)) ?? '';
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        $title = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $post->title)) ?? '');
        if ($title !== '') {
            $body = preg_replace('/'.preg_quote($title, '/').'/iu', ' ', $body, 1) ?? $body;
        }
        $substantive = preg_replace('/[^\pL\pN]+/u', '', $body) ?? '';

        return mb_strlen($substantive) >= 20;
    }

    private function completionFailure(string $caption): ?string
    {
        $body = trim(preg_replace('~https?://\S+|#[\pL\pN_]+~u', ' ', strip_tags($caption)) ?? '');
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($body === '') {
            return 'The caption has no complete body.';
        }
        if (preg_match('/(?:\.{3}|…|[,;:–—-])\s*[”"»’\)\]]*$/u', $body) === 1) {
            return 'The caption ends with a dangling or truncated phrase.';
        }
        if (preg_match('/[\pL\pN]\s*[”"»’\)\]]*$/u', $body) === 1) {
            return 'The caption ends without a completed sentence.';
        }

        return null;
    }

    private function descriptionAppearsComplete(string $description): bool
    {
        $description = trim(preg_replace('/\s+/u', ' ', strip_tags($description)) ?? '');
        if ($description === '') {
            return false;
        }
        if (preg_match('/(?:\.{3}|…|[,;:–—-])\s*[”"»’\)\]]*$/u', $description) === 1) {
            return false;
        }

        // Storefront descriptions frequently omit terminal punctuation. That
        // is not evidence of truncation. Only explicit truncation or dangling
        // punctuation is rejected here.
        return true;
    }

    private function safeVerifiedDescription(string $description): string
    {
        if ($this->descriptionAppearsComplete($description)) {
            return $description;
        }

        if (preg_match_all('/.*?[.!?](?=\s|$)/us', $description, $sentences) > 0) {
            $complete = trim(implode(' ', $sentences[0]));
            $letters = preg_replace('/[^\pL\pN]+/u', '', $complete) ?? '';
            if (mb_strlen($letters) >= 20) {
                return $complete;
            }
        }

        throw new \RuntimeException('The verified product description is explicitly truncated and has no complete passage safe to publish.');
    }

    private function verifiedDescriptionExcerpt(string $description, int $limit): string
    {
        if ($limit < 20) {
            throw new \RuntimeException('The provider limit leaves no room for substantive verified product copy.');
        }
        if (mb_strlen($description) <= $limit) {
            return $description;
        }

        preg_match_all('/.*?[.!?](?=\s|$)/us', $description, $sentences);
        $excerpt = '';
        foreach ($sentences[0] ?? [] as $sentence) {
            $candidate = trim($excerpt.' '.trim($sentence));
            if (mb_strlen($candidate) > $limit) {
                break;
            }
            $excerpt = $candidate;
        }
        if (mb_strlen(preg_replace('/[^\pL\pN]+/u', '', $excerpt) ?? '') >= 20) {
            return $excerpt;
        }

        $excerpt = rtrim(mb_substr($description, 0, max(0, $limit - 1)));
        $excerpt = preg_replace('/\s+\S*$/u', '', $excerpt) ?? $excerpt;
        $excerpt = rtrim($excerpt, " \t\n\r\0\x0B,;:–—-").'…';
        if (mb_strlen(preg_replace('/[^\pL\pN]+/u', '', $excerpt) ?? '') < 20) {
            throw new \RuntimeException('The provider limit leaves no room for substantive verified product copy.');
        }

        return $excerpt;
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
