<?php

namespace App\Jobs;

use App\Models\AiReel;
use App\Services\ReelCreditService;
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

    public function handle(RunwayClient $runway, ReelCreditService $credits): void
    {
        $reel = AiReel::query()->find($this->reelId);
        if (! $reel || $reel->status !== 'generating' || ! $reel->runway_task_id) {
            return;
        }
        try {
            $task = $runway->task($reel->runway_task_id);
        } catch (\Throwable $exception) {
            $this->retryOrFail($reel, $credits, $exception->getMessage());

            return;
        }
        $status = strtoupper((string) ($task['status'] ?? ''));
        if (in_array($status, ['PENDING', 'THROTTLED', 'RUNNING'], true)) {
            $this->retryOrFail($reel, $credits, 'Runway generation is still pending.');

            return;
        }
        if ($status !== 'SUCCEEDED' || blank(data_get($task, 'output.0'))) {
            $reel->update(['status' => 'generation_failed', 'last_error' => Str::limit((string) ($task['failure'] ?? $task['failureCode'] ?? 'Runway generation failed.'), 1000)]);
            $credits->refund($reel, 'generation_failed');

            return;
        }
        try {
            $contents = $runway->download((string) data_get($task, 'output.0'));
            $filename = hash('sha256', $reel->id.'|'.$reel->runway_task_id.'|'.Str::random(32)).'.mp4';
            $path = 'reels/'.$filename;
            Storage::disk('local')->put($path, $contents);
            $reel->update([
                'video_path' => $path,
                'status' => $reel->mode === 'custom' ? 'awaiting_approval' : 'ready',
                'generated_at' => now(), 'last_error' => null,
            ]);
        } catch (\Throwable $exception) {
            $reel->update(['status' => 'generation_failed', 'last_error' => Str::limit($exception->getMessage(), 1000)]);
            $credits->refund($reel, 'download_failed');
        }
    }

    private function retryOrFail(AiReel $reel, ReelCreditService $credits, string $reason): void
    {
        $attempts = (int) $reel->poll_attempts + 1;
        $reel->update(['poll_attempts' => $attempts, 'last_error' => Str::limit($reason, 1000)]);
        if ($attempts >= 20) {
            $reel->update(['status' => 'generation_failed']);
            $credits->refund($reel, 'generation_timeout');

            return;
        }
        self::dispatch($reel->id)->delay(now()->addSeconds(20))->onQueue('channels');
    }
}
