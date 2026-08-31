<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SocialMediaImageDesigner
{
    public function render(string $sourceUrl, string $style): string
    {
        if (in_array($style, ['original', 'raw'], true) || ! extension_loaded('gd')) {
            return $sourceUrl;
        }

        $style = in_array($style, SocialMediaTemplateService::IMAGE_STYLES, true) ? $style : 'original';
        $hash = hash('sha256', $sourceUrl.'|'.$style.'|v1');
        $path = 'social-media/'.$hash.'.jpg';
        if (Storage::disk('public')->exists($path)) {
            return route('social-media.image', ['filename' => $hash.'.jpg']);
        }

        try {
            $response = Http::connectTimeout(3)->timeout(12)->retry(1, 150)->get($sourceUrl);
            if (! $response->successful() || strlen($response->body()) > 12_000_000) {
                return $sourceUrl;
            }
            $source = @imagecreatefromstring($response->body());
            if (! $source) {
                return $sourceUrl;
            }

            $canvas = imagecreatetruecolor(1080, 1080);
            imageantialias($canvas, true);
            [$background, $mat, $accent, $margin] = match ($style) {
                'framed' => [[246, 242, 232], [255, 255, 255], [31, 74, 60], 105],
                'editorial' => [[238, 241, 235], [255, 255, 255], [190, 255, 73], 72],
                'dark' => [[19, 29, 26], [34, 47, 42], [190, 255, 73], 92],
                default => [[222, 245, 229], [255, 255, 255], [24, 93, 70], 82],
            };
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$background));
            imagefilledrectangle($canvas, $margin - 18, $margin - 18, 1080 - $margin + 18, 1080 - $margin + 18, imagecolorallocate($canvas, ...$accent));
            imagefilledrectangle($canvas, $margin, $margin, 1080 - $margin, 1080 - $margin, imagecolorallocate($canvas, ...$mat));

            $inner = 1080 - ($margin * 2) - 42;
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min($inner / max(1, $width), $inner / max(1, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $x = (int) ((1080 - $targetWidth) / 2);
            $y = (int) ((1080 - $targetHeight) / 2);
            imagecopyresampled($canvas, $source, $x, $y, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            imagejpeg($canvas, null, 90);
            $jpeg = (string) ob_get_clean();
            imagedestroy($source);
            imagedestroy($canvas);
            Storage::disk('public')->put($path, $jpeg);

            return route('social-media.image', ['filename' => $hash.'.jpg']);
        } catch (\Throwable) {
            return $sourceUrl;
        }
    }
}
