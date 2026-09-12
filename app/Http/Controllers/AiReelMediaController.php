<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiReelMediaController extends Controller
{
    public function show(string $filename): StreamedResponse
    {
        $path = 'reels/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $filename, [
            'Content-Type' => 'video/mp4', 'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function input(string $filename): StreamedResponse
    {
        $path = 'reel-inputs/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => abort(404),
        };

        return Storage::disk('local')->response($path, $filename, [
            'Content-Type' => $contentType,
            'Content-Length' => (string) Storage::disk('local')->size($path),
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
