<?php

namespace App\Services;

use App\Models\AiReel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiReelSourceImageStorage
{
    /** @return array{path: string, url: string} */
    public function store(UploadedFile $image): array
    {
        $extension = match ($image->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new \RuntimeException('Unsupported Reel image type.'),
        };
        $filename = hash('sha256', Str::random(64).'|'.microtime(true)).'.'.$extension;
        $path = $image->storeAs('reel-inputs', $filename, 'local');
        throw_if(! is_string($path), new \RuntimeException('The Reel image could not be stored.'));

        return ['path' => $path, 'url' => route('ai-reels.input', ['filename' => $filename])];
    }

    public function prepareForRunway(AiReel $reel): ?string
    {
        if (blank($reel->source_image_url) || ! extension_loaded('gd')) {
            return $reel->source_image_url;
        }

        try {
            if (filled($reel->source_image_path)) {
                $originalPath = $reel->source_image_path;
                $input = Storage::disk('local')->get($originalPath);
            } else {
                $originalPath = null;
                $response = Http::connectTimeout(5)->timeout(15)->get($reel->source_image_url);
                if (! $response->successful() || strlen($response->body()) > 12_000_000) {
                    return $reel->source_image_url;
                }
                $input = $response->body();
            }

            $source = @imagecreatefromstring($input);
            if (! $source) {
                return $reel->source_image_url;
            }

            $canvasWidth = 720;
            $canvasHeight = 1280;
            $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 246, 246, 242));
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $availableWidth = $canvasWidth - 64;
            $availableHeight = $canvasHeight - 96;
            $scale = min($availableWidth / max(1, $sourceWidth), $availableHeight / max(1, $sourceHeight));
            $targetWidth = max(1, (int) round($sourceWidth * $scale));
            $targetHeight = max(1, (int) round($sourceHeight * $scale));
            imagecopyresampled(
                $canvas,
                $source,
                (int) (($canvasWidth - $targetWidth) / 2),
                (int) (($canvasHeight - $targetHeight) / 2),
                0,
                0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight,
            );
            ob_start();
            imagejpeg($canvas, null, 92);
            $contents = (string) ob_get_clean();
            imagedestroy($source);
            imagedestroy($canvas);
            throw_if($contents === '', new \RuntimeException('The Reel image could not be prepared.'));

            $filename = hash('sha256', Str::random(64).'|'.microtime(true)).'.jpg';
            $path = 'reel-inputs/'.$filename;
            Storage::disk('local')->put($path, $contents);
            $url = route('ai-reels.input', ['filename' => $filename]);
            $reel->update(['source_image_path' => $path, 'source_image_url' => $url]);
            if ($originalPath && $originalPath !== $path) {
                Storage::disk('local')->delete($originalPath);
            }

            return 'data:image/jpeg;base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return $reel->source_image_url;
        }
    }

    public function delete(AiReel $reel): void
    {
        if (filled($reel->source_image_path)) {
            Storage::disk('local')->delete($reel->source_image_path);
        }
    }

    public function deletePath(?string $path): void
    {
        if (filled($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
