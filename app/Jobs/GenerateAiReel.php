<?php

namespace App\Jobs;

use App\Models\AiReel;
use App\Services\AiReelPromptWriter;
use App\Services\AiReelSourceImageStorage;
use App\Services\ReelCreditService;
use App\Services\ReelMusicService;
use App\Services\RunwayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GenerateAiReel implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $reelId) {}

    public function uniqueId(): string
    {
        return (string) $this->reelId;
    }

    public function handle(AiReelPromptWriter $writer, RunwayClient $runway, ReelCreditService $credits, AiReelSourceImageStorage $images, ReelMusicService $music): void
    {
        $reel = AiReel::query()->with(['agent.organization', 'product'])->find($this->reelId);
        if (! $reel || ! in_array($reel->status, ['queued', 'generation_failed'], true) || $reel->runway_task_id) {
            return;
        }
        $claimed = AiReel::query()
            ->whereKey($reel->id)
            ->whereIn('status', ['queued', 'generation_failed'])
            ->whereNull('runway_task_id')
            ->update(['status' => 'generating', 'last_error' => null]);
        if ($claimed !== 1) {
            return;
        }
        $reel->refresh();

        try {
            $music->ensureAvailable((string) $reel->music_track);
        } catch (\Throwable $exception) {
            if ($this->finishRequestedCancellation($reel, $images)) {
                return;
            }
            $this->failGeneration($reel, $credits, $images, 'Reel music preparation failed', $exception);

            return;
        }

        try {
            $copy = $writer->write($reel);
            $reel->update(['generated_prompt' => $copy['prompt'], 'caption' => $copy['caption']]);
        } catch (\Throwable $exception) {
            if ($this->finishRequestedCancellation($reel, $images)) {
                return;
            }
            $this->failGeneration($reel, $credits, $images, 'Reel copy preparation failed', $exception);

            return;
        }

        try {
            if ($this->finishRequestedCancellation($reel, $images)) {
                return;
            }
            $sourceImageUrl = $images->prepareForRunway($reel);
            $taskId = $runway->create($copy['prompt'], $sourceImageUrl, (int) $reel->duration_seconds);
            $reel->update(['runway_task_id' => $taskId]);
            $reel->refresh();
            if ($reel->status === 'canceling') {
                try {
                    $runway->cancel($taskId);
                } catch (\Throwable $exception) {
                    report($exception);
                    $reel->update([
                        'status' => 'generating',
                        'last_error' => Str::limit('Runway cancellation failed: '.$exception->getMessage(), 1000),
                    ]);
                    PollAiReelGeneration::dispatch($reel->id)->delay(now()->addSeconds(15))->onQueue('channels');

                    return;
                }
                $reel->update(['status' => 'canceled']);
                $images->delete($reel);

                return;
            }
            PollAiReelGeneration::dispatch($reel->id)->delay(now()->addSeconds(15))->onQueue('channels');
        } catch (\Throwable $exception) {
            if ($this->finishRequestedCancellation($reel, $images)) {
                return;
            }
            $this->failGeneration($reel, $credits, $images, 'Runway generation request failed', $exception);
        }
    }

    private function finishRequestedCancellation(AiReel $reel, AiReelSourceImageStorage $images): bool
    {
        $reel->refresh();
        if ($reel->status !== 'canceling' || filled($reel->runway_task_id)) {
            return false;
        }

        $reel->update(['status' => 'canceled']);
        $images->delete($reel);

        return true;
    }

    private function failGeneration(AiReel $reel, ReelCreditService $credits, AiReelSourceImageStorage $images, string $stage, \Throwable $exception): void
    {
        report($exception);
        $reel->update([
            'status' => 'generation_failed',
            'last_error' => Str::limit($stage.': '.$exception->getMessage(), 1000),
        ]);
        $credits->refund($reel, 'generation_failed');
        $images->delete($reel);
    }
}
