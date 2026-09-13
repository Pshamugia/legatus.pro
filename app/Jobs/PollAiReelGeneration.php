<?php

namespace App\Jobs;

use App\Models\AiReel;
use App\Services\AiReelSourceImageStorage;
use App\Services\ReelCreditService;
use App\Services\ReelMusicService;
use App\Services\RunwayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PollAiReelGeneration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $reelId) {}

    public function handle(RunwayClient $runway, ReelCreditService $credits, AiReelSourceImageStorage $images, ReelMusicService $music): void
    {
        $reel = AiReel::query()->find($this->reelId);
        if (! $reel || $reel->status !== 'generating' || ! $reel->runway_task_id) {
            return;
        }
        try {
            $task = $runway->task($reel->runway_task_id);
        } catch (\Throwable $exception) {
            $this->retryOrFail($reel, $credits, $images, $exception->getMessage());

            return;
        }
        $status = strtoupper((string) ($task['status'] ?? ''));
        if (in_array($status, ['PENDING', 'THROTTLED', 'RUNNING'], true)) {
            $this->retryOrFail($reel, $credits, $images, 'Runway generation is still pending.');

            return;
        }
        if ($status !== 'SUCCEEDED' || blank(data_get($task, 'output.0'))) {
            $reel->update(['status' => 'generation_failed', 'last_error' => Str::limit((string) ($task['failure'] ?? $task['failureCode'] ?? 'Runway generation failed.'), 1000)]);
            $credits->refund($reel, 'generation_failed');
            $images->delete($reel);

            return;
        }
        try {
            $contents = $runway->download((string) data_get($task, 'output.0'));
            $contents = $music->mix($contents, (string) $reel->music_track, (int) $reel->duration_seconds);
            $filename = hash('sha256', $reel->id.'|'.$reel->runway_task_id.'|'.Str::random(32)).'.mp4';
            $path = 'reels/'.$filename;
            Storage::disk('local')->put($path, $contents);
            $reel->update([
                'video_path' => $path,
                'status' => $reel->mode === 'custom' ? 'awaiting_approval' : 'ready',
                'generated_at' => now(), 'last_error' => null,
            ]);
            $images->delete($reel);
        } catch (\Throwable $exception) {
            $reel->update(['status' => 'generation_failed', 'last_error' => Str::limit($exception->getMessage(), 1000)]);
            $credits->refund($reel, 'download_failed');
            $images->delete($reel);
        }
    }

    private function retryOrFail(AiReel $reel, ReelCreditService $credits, AiReelSourceImageStorage $images, string $reason): void
    {
        $attempts = (int) $reel->poll_attempts + 1;
        $reel->update(['poll_attempts' => $attempts, 'last_error' => Str::limit($reason, 1000)]);
        if ($attempts >= 20) {
            $reel->update(['status' => 'generation_failed']);
            $credits->refund($reel, 'generation_timeout');
            $images->delete($reel);

            return;
        }
        self::dispatch($reel->id)->delay(now()->addSeconds(20))->onQueue('channels');
    }
}
