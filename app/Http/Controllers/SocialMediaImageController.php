<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SocialMediaImageController extends Controller
{
    public function show(string $filename): Response
    {
        abort_unless(preg_match('/^[a-f0-9]{64}\.jpg$/', $filename) === 1, 404);
        $path = 'social-media/'.$filename;
        abort_unless(Storage::disk('public')->exists($path), 404);

        return response(Storage::disk('public')->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
