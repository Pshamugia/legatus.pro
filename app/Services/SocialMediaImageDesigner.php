<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SocialMediaImageDesigner
{
    public function render(string $sourceUrl, string $style): string
    {
        if ($style === 'raw' || ! extension_loaded('gd')) {
            return $sourceUrl;
        }

        $style = in_array($style, SocialMediaTemplateService::IMAGE_STYLES, true) ? $style : 'original';
        $hash = hash('sha256', $sourceUrl.'|'.$style.'|v2');
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

            // A storefront may already provide a finished square catalogue
            // composition. Keep it intact instead of adding a second mock-up.
            if ($style === 'original' && $this->looksLikePreparedCatalogArtwork($source)) {
                imagedestroy($source);

                return $sourceUrl;
            }

            $canvas = imagecreatetruecolor(1080, 1080);
            imageantialias($canvas, true);
            if ($style === 'original') {
                $backgroundPath = public_path('images/social/catalog-background.png');
                $background = is_file($backgroundPath) ? @imagecreatefrompng($backgroundPath) : false;
                if (! $background) {
                    imagedestroy($source);
                    imagedestroy($canvas);

                    return $sourceUrl;
                }
                imagecopyresampled($canvas, $background, 0, 0, 0, 0, 1080, 1080, imagesx($background), imagesy($background));
                imagedestroy($background);

                $this->drawThreeDimensionalBook($canvas, $source);
            } else {
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
            }
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

    /** Draw a photographed cover as the perspective hardback used by the original catalog artwork. */
    private function drawThreeDimensionalBook(\GdImage $canvas, \GdImage $source): void
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $frontWidth = 620;
        $frontHeight = min(920, (int) round($frontWidth * ($sourceHeight / max(1, $sourceWidth))));
        if ($frontHeight < 720) {
            $frontHeight = 720;
            $frontWidth = min(650, (int) round($frontHeight * ($sourceWidth / max(1, $sourceHeight))));
        }

        $left = 1080 - $frontWidth - 92;
        $right = $left + $frontWidth;
        $topLeft = (int) ((1080 - $frontHeight) / 2) + 18;
        $topRight = $topLeft - 18;
        $bottomLeft = $topLeft + $frontHeight - 8;
        $bottomRight = $topRight + $frontHeight + 12;
        $depth = 24;

        $shadow = imagecolorallocatealpha($canvas, 25, 25, 25, 82);
        imagefilledpolygon($canvas, [
            $left + 24, $topLeft + 28,
            $right + $depth + 28, $topRight + 40,
            $right + $depth + 30, $bottomRight + 35,
            $left + 28, $bottomLeft + 34,
        ], $shadow);

        // Page block and hardback depth sit behind the front cover.
        imagefilledpolygon($canvas, [
            $left + 8, $bottomLeft,
            $right, $bottomRight,
            $right + $depth, $bottomRight - 10,
            $left + 14, $bottomLeft - 13,
        ], imagecolorallocate($canvas, 224, 221, 210));
        imagefilledpolygon($canvas, [
            $right, $topRight,
            $right + $depth, $topRight + 13,
            $right + $depth, $bottomRight - 10,
            $right, $bottomRight,
        ], imagecolorallocate($canvas, 55, 55, 52));

        // Column mapping gives the cover a true trapezoidal perspective rather
        // than putting a flat rectangle on top of the background.
        for ($column = 0; $column < $frontWidth; $column++) {
            $ratio = $column / max(1, $frontWidth - 1);
            $top = (int) round($topLeft + (($topRight - $topLeft) * $ratio));
            $bottom = (int) round($bottomLeft + (($bottomRight - $bottomLeft) * $ratio));
            $sourceX = min($sourceWidth - 1, (int) floor($ratio * $sourceWidth));
            imagecopyresampled($canvas, $source, $left + $column, $top, $sourceX, 0, 1, max(1, $bottom - $top), 1, $sourceHeight);
        }

        imageline($canvas, $left, $topLeft, $right, $topRight, imagecolorallocatealpha($canvas, 255, 255, 255, 70));
        imageline($canvas, $left, $bottomLeft, $right, $bottomRight, imagecolorallocatealpha($canvas, 15, 15, 15, 70));
    }

    private function looksLikePreparedCatalogArtwork(\GdImage $source): bool
    {
        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 120 || $height < 120 || abs(($width / $height) - 1) > 0.12) {
            return false;
        }

        $sampleWidth = max(1, (int) floor($width * 0.22));
        $stepX = max(1, (int) floor($sampleWidth / 24));
        $stepY = max(1, (int) floor($height / 32));
        $samples = 0;
        $light = 0;
        for ($x = 0; $x < $sampleWidth; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $rgb = imagecolorat($source, $x, $y);
                $red = ($rgb >> 16) & 0xFF;
                $green = ($rgb >> 8) & 0xFF;
                $blue = $rgb & 0xFF;
                $samples++;
                if ($red >= 205 && $green >= 205 && $blue >= 205) {
                    $light++;
                }
            }
        }

        return $samples > 0 && ($light / $samples) >= 0.72;
    }
}
