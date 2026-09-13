<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReelMusicService
{
    public function ensureAvailable(string $trackId): void
    {
        $track = $this->track($trackId);
        $process = Process::timeout(10)->run([(string) config('reel_music.ffmpeg_binary', 'ffmpeg'), '-version']);
        throw_unless($process->successful(), new \RuntimeException('FFmpeg is not available for Reel music processing.'));

        $this->cachedTrack($trackId, (string) $track['url']);
    }

    public function mix(string $videoContents, string $trackId, int $duration): string
    {
        $track = $this->track($trackId);
        $this->ensureAvailable($trackId);
        $musicPath = $this->cachedTrack($trackId, (string) $track['url']);
        $directory = storage_path('app/private/reel-mixing');
        File::ensureDirectoryExists($directory);
        $token = Str::uuid()->toString();
        $inputPath = $directory.'/'.$token.'-input.mp4';
        $outputPath = $directory.'/'.$token.'-output.mp4';
        File::put($inputPath, $videoContents);
        $fadeOutAt = max(0, $duration - 1);

        try {
            $process = Process::timeout(120)->run([
                (string) config('reel_music.ffmpeg_binary', 'ffmpeg'), '-y',
                '-i', $inputPath, '-stream_loop', '-1', '-i', $musicPath,
                '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k',
                '-af', "volume=0.62,afade=t=in:st=0:d=0.5,afade=t=out:st={$fadeOutAt}:d=1",
                '-t', (string) $duration, '-movflags', '+faststart', $outputPath,
            ]);
            if (! $process->successful() || ! File::exists($outputPath)) {
                throw new \RuntimeException('The selected background music could not be added to the Reel.');
            }

            return File::get($outputPath);
        } finally {
            File::delete([$inputPath, $outputPath]);
        }
    }

    private function track(string $trackId): array
    {
        $track = data_get(config('reel_music.tracks'), $trackId);
        throw_unless(is_array($track) && filled($track['url'] ?? null), new \InvalidArgumentException('Unknown Reel music track.'));

        return $track;
    }

    private function cachedTrack(string $trackId, string $url): string
    {
        $path = 'reel-music/'.$trackId.'-'.substr(hash('sha256', $url), 0, 16).'.mp3';
        if (! Storage::disk('local')->exists($path)) {
            $response = Http::connectTimeout(10)->timeout(45)->get($url)->throw();
            $contents = $response->body();
            throw_if($contents === '' || strlen($contents) > 15 * 1024 * 1024, new \RuntimeException('The selected music track is unavailable.'));
            Storage::disk('local')->put($path, $contents);
        }

        return Storage::disk('local')->path($path);
    }
}
