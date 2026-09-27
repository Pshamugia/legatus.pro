<?php

namespace App\Services;

use App\Models\SocialMediaPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SocialMediaAiPhotoEditor
{
    /** @return array{url: string, model: string, source_url: string} */
    public function generate(SocialMediaPost $post): array
    {
        $product = $post->product;
        $agent = $post->agent;
        if (! $product || ! $agent || blank(config('services.openai.key'))) {
            throw new \RuntimeException('AI Photo Editor is unavailable because its verified product or OpenAI connection is missing.');
        }
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('AI Photo Editor requires the GD image extension.');
        }

        $localized = filled($post->language)
            ? (array) data_get($product->metadata, 'localized.'.$post->language, [])
            : [];
        $sourceUrl = (string) ($localized['image'] ?? $product->publicImageUrl() ?? '');
        if (! $this->publicHttpUrl($sourceUrl)) {
            throw new \RuntimeException('AI Photo Editor requires a public product photo.');
        }

        $model = (string) config('services.openai.social_media_model', 'gpt-5.6-luna');
        $imageModel = (string) config('services.openai.social_media_image_model', 'gpt-image-2.5-sunburst');
        $description = Str::limit(
            preg_replace('/\s+/u', ' ', trim(strip_tags((string) ($post->description ?: $product->socialDescription($post->language))))) ?? '',
            700,
            '…',
        );
        $prompt = implode("\n", [
            'Create a premium, photorealistic square social-media background for the verified product described below.',
            'The original product photo will be composited unchanged by the server after generation.',
            'Do not draw, recreate, imitate, duplicate, or include the product itself. Leave a clean, visually intentional central area for the exact product photo.',
            'Enrich only the environment with tasteful lighting, depth, subtle relevant props, and a polished commercial composition grounded in the product description.',
            'No text, letters, prices, logos, watermarks, borders, people, hands, or unrelated objects. Avoid clutter and misleading product features.',
            'The result must look solid, elegant, realistic, and suitable for a professional Facebook, Instagram, or LinkedIn product post.',
            'Business: '.($agent->business_name ?: $agent->name),
            'Product title: '.($post->title ?: $product->name),
            'Product category: '.($product->category ?: 'not specified'),
            'Verified description: '.($description !== '' ? $description : 'No additional description is available.'),
        ]);

        $response = Http::withToken(config('services.openai.key'))
            ->acceptJson()
            ->connectTimeout((int) config('services.openai.connect_timeout', 5))
            ->timeout(65)
            ->post('https://api.openai.com/v1/responses', [
                'model' => $model,
                'input' => [[
                    'role' => 'user',
                    'content' => [['type' => 'input_text', 'text' => $prompt]],
                ]],
                'tools' => [[
                    'type' => 'image_generation',
                    'model' => $imageModel,
                    'action' => 'generate',
                    'quality' => 'medium',
                    'size' => '1024x1024',
                ]],
                'tool_choice' => ['type' => 'image_generation'],
            ])->throw()->json();

        $imageCall = collect($response['output'] ?? [])
            ->first(fn ($item): bool => ($item['type'] ?? null) === 'image_generation_call');
        $encoded = is_array($imageCall) ? ($imageCall['result'] ?? null) : null;
        if (! is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('OpenAI did not return an edited social image.');
        }
        $backgroundBytes = base64_decode($encoded, true);
        if (! is_string($backgroundBytes) || $backgroundBytes === '' || strlen($backgroundBytes) > 25_000_000) {
            throw new \RuntimeException('OpenAI returned an invalid social image.');
        }

        $sourceResponse = Http::connectTimeout(5)->timeout(15)->get($sourceUrl)->throw();
        $sourceBytes = $sourceResponse->body();
        if ($sourceBytes === '' || strlen($sourceBytes) > 12_000_000) {
            throw new \RuntimeException('The original product photo could not be prepared safely.');
        }

        $jpeg = $this->composite($backgroundBytes, $sourceBytes);
        $hash = hash('sha256', implode('|', [
            'ai-photo-v1', (string) $post->agent_id, (string) $post->product_id,
            (string) $post->getRawOriginal('scheduled_for'), hash('sha256', $jpeg),
        ]));
        Storage::disk('public')->put('social-media/'.$hash.'.jpg', $jpeg);

        return [
            'url' => route('social-media.image', ['filename' => $hash.'.jpg']),
            'model' => $model.' + '.$imageModel,
            'source_url' => $sourceUrl,
        ];
    }

    private function composite(string $backgroundBytes, string $sourceBytes): string
    {
        $background = @imagecreatefromstring($backgroundBytes);
        $source = @imagecreatefromstring($sourceBytes);
        if (! $background || ! $source) {
            if ($background) {
                imagedestroy($background);
            }
            if ($source) {
                imagedestroy($source);
            }
            throw new \RuntimeException('AI Photo Editor received an unsupported image format.');
        }

        $canvas = imagecreatetruecolor(1080, 1080);
        imageantialias($canvas, true);
        imagecopyresampled($canvas, $background, 0, 0, 0, 0, 1080, 1080, imagesx($background), imagesy($background));

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(720 / max(1, $sourceWidth), 820 / max(1, $sourceHeight));
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $x = (int) round((1080 - $targetWidth) / 2);
        $y = (int) round((1080 - $targetHeight) / 2);

        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 88);
        foreach (range(18, 2, -2) as $spread) {
            imagefilledrectangle(
                $canvas,
                $x - $spread,
                $y - $spread + 10,
                $x + $targetWidth + $spread,
                $y + $targetHeight + $spread + 10,
                $shadow,
            );
        }
        imagecopyresampled(
            $canvas,
            $source,
            $x,
            $y,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        imagejpeg($canvas, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($background);
        imagedestroy($source);
        imagedestroy($canvas);
        if ($jpeg === '') {
            throw new \RuntimeException('AI Photo Editor could not save the final image.');
        }

        return $jpeg;
    }

    private function publicHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
