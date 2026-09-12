<?php

namespace App\Services;

use App\Models\AiReel;
use Illuminate\Http\UploadedFile;
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
