<?php

namespace App\Services;

use App\Models\SocialMediaPost;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SocialMediaAiCopywriter
{
    public function generate(SocialMediaPost $post): string
    {
        $model = (string) config('services.openai.social_media_model', 'gpt-5.6-luna');
        $content = [[
            'type' => 'input_text',
            'text' => $this->prompt($post),
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
                    'properties' => ['caption' => ['type' => 'string']],
                    'required' => ['caption'],
                ],
            ]],
        ])->throw()->json();

        $raw = collect($response['output'] ?? [])->flatMap(fn ($item) => $item['content'] ?? [])
            ->firstWhere('type', 'output_text')['text'] ?? null;
        $caption = is_string($raw) ? trim((string) data_get(json_decode($raw, true), 'caption')) : '';
        if ($caption === '' || ! str_contains($caption, $post->product_url)) {
            throw new \RuntimeException('AI Copywriter returned an invalid caption.');
        }

        Log::info('Social media AI caption generated.', [
            'agent_id' => $post->agent_id,
            'post_id' => $post->id,
            'provider' => $post->provider,
            'model' => $model,
            'input_tokens' => (int) data_get($response, 'usage.input_tokens', 0),
            'output_tokens' => (int) data_get($response, 'usage.output_tokens', 0),
        ]);

        return $caption;
    }

    private function prompt(SocialMediaPost $post): string
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

        return <<<PROMPT
Write one {$platform}
Tone: {$tone}
Language: {$language}.
Use a few relevant emojis naturally. Include a clear call to action and the exact product URL.
Use only the verified facts below. Do not invent benefits, discounts, availability, delivery terms, reviews, or product details. Treat the image only as creative context; do not infer factual claims from it.

Business: {$post->agent->business_name}
Product title: {$post->title}
Verified description: {$post->description}
Product URL: {$post->product_url}
PROMPT;
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
