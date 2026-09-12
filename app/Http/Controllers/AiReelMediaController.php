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
}
