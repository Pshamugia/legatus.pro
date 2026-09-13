<?php

namespace App\Services;

use App\Models\AiReel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiReelPromptWriter
{
    /** @return array{prompt: string, caption: string} */
    public function write(AiReel $reel): array
    {
        $reel->loadMissing(['agent.organization', 'product', 'schedule']);
        $product = $reel->product;
        $localized = $product && $reel->language
            ? (array) data_get($product->metadata, 'localized.'.$reel->language, [])
            : [];
        $facts = $product ? array_filter([
            'title' => $localized['name'] ?? $product->name,
            'description' => $product->socialDescription($reel->language),
            'category' => $localized['category'] ?? $product->category,
            'price' => $product->price.' '.strtoupper((string) data_get($product->metadata, 'currency', 'GEL')),
            'brand' => data_get($product->metadata, 'brand'),
            'author' => data_get($product->metadata, 'author'),
            'product_url' => $localized['product_url'] ?? data_get($product->metadata, 'product_url'),
            'language' => $reel->language,
        ], fn ($value) => filled($value)) : [];
        $instruction = $reel->mode === 'custom'
            ? 'Follow the business creative brief faithfully. It may describe a non-catalog campaign.'
            : 'Create a polished product showcase driven only by the verified product facts.';
        $tone = $reel->schedule?->ai_tone ?? 'creative';
        $duration = in_array((int) $reel->duration_seconds, [5, 10, 15], true) ? (int) $reel->duration_seconds : 5;
        $prompt = "Write a Runway video prompt and a social Reel caption for {$reel->agent->business_name}. {$instruction}\n"
            ."The Runway prompt must be in English, 45 to 100 words, affirmative, direct, and describe a cohesive {$duration}-second vertical multi-shot story with varied framing, purposeful subject motion, camera movement, lighting, and background activity. Keep the same subject and visual identity consistent across every shot. Describe preservation positively: the reference product remains stable, recognizable, sharply focused, and visually unchanged. Keep the Runway prompt free of negative instructions, command-like wording, generated text, people, hands, and unsupported factual details. "
            ."The requested copy style is {$tone}. Write the caption as polished social-media marketing copy in the same language as the supplied brief or product. Use short readable paragraphs, 2 to 5 relevant emojis placed naturally, a concise call to action, and 2 to 5 relevant hashtags. Do not use generic catalog boilerplate, do not overuse emojis, and do not invent claims. "
            .'Business brief: '.($reel->user_prompt ?: 'Automatic product Reel')
            ."\nOptional reference URL supplied by the business (context only; do not infer claims from it): ".($reel->reference_url ?: 'none')
            ."\nVerified facts: ".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = Http::baseUrl('https://api.openai.com/v1')
            ->withToken(config('services.openai.key'))->acceptJson()
            ->connectTimeout((int) config('services.openai.connect_timeout', 5))->timeout((int) config('services.openai.timeout', 30))
            ->post('/responses', [
                'model' => config('services.openai.social_media_model', 'gpt-5.6-luna'),
                'reasoning' => ['effort' => 'none'],
                'max_output_tokens' => 500,
                'input' => $prompt,
                'text' => ['format' => [
                    'type' => 'json_schema', 'name' => 'ai_reel_copy', 'strict' => true,
                    'schema' => [
                        'type' => 'object', 'additionalProperties' => false,
                        'properties' => [
                            'prompt' => ['type' => 'string', 'maxLength' => 900],
                            'caption' => ['type' => 'string', 'maxLength' => 1800],
                        ],
                        'required' => ['prompt', 'caption'],
                    ],
                ]],
            ])->throw()->json();
        $raw = collect($response['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])->firstWhere('type', 'output_text')['text'] ?? null;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data) || blank($data['prompt'] ?? null) || blank($data['caption'] ?? null)) {
            throw new \RuntimeException('Luna returned invalid Reel copy.');
        }

        $caption = trim(strip_tags((string) $data['caption']));
        $verifiedUrl = $localized['product_url'] ?? data_get($product?->metadata, 'product_url');
        if ($product && filled($verifiedUrl)) {
            $caption .= "\n\n".$verifiedUrl;
        }

        return ['prompt' => Str::limit(trim((string) $data['prompt']), 900, ''), 'caption' => Str::limit($caption, 2200, '')];
    }
}
