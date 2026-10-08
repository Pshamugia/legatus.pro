<?php

namespace App\Services;

use App\Models\AiReel;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReelVideoEditor
{
    /** @return array{path: string, duration_ms: int} */
    public function trim(AiReel $reel, float $start, float $end): array
    {
        $sourcePath = $reel->video_path;
        throw_unless(filled($sourcePath) && Storage::disk('local')->exists($sourcePath), new \RuntimeException('The Reel video is unavailable.'));

        $duration = round($end - $start, 3);
        throw_if($duration < 0.5, new \InvalidArgumentException('Keep at least 0.5 seconds of the Reel.'));

        $outputPath = 'reels/'.hash('sha256', $reel->id.'|trim|'.Str::random(48).'|'.microtime(true)).'.mp4';
        $absoluteOutputPath = Storage::disk('local')->path($outputPath);
        File::ensureDirectoryExists(dirname($absoluteOutputPath));

        try {
            $process = Process::timeout(120)->run([
                (string) config('reel_music.ffmpeg_binary', 'ffmpeg'), '-y',
                '-ss', number_format($start, 3, '.', ''),
                '-i', Storage::disk('local')->path($sourcePath),
                '-t', number_format($duration, 3, '.', ''),
                '-map', '0:v:0', '-map', '0:a?',
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '20', '-pix_fmt', 'yuv420p',
                '-threads', '1',
                '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart',
                $absoluteOutputPath,
            ]);
            if (! $process->successful() || ! File::exists($absoluteOutputPath) || File::size($absoluteOutputPath) === 0) {
                throw new \RuntimeException('The Reel could not be trimmed. Please try again.');
            }

            return ['path' => $outputPath, 'duration_ms' => (int) round($duration * 1000)];
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($outputPath);

            throw $exception;
        }
    }
}
