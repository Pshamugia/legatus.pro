<?php

namespace App\Jobs;

use App\Models\AiReel;
use App\Services\AiReelPromptWriter;
use App\Services\AiReelSourceImageStorage;
use App\Services\ReelCreditService;
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

    public function handle(AiReelPromptWriter $writer, RunwayClient $runway, ReelCreditService $credits, AiReelSourceImageStorage $images): void
    {
        $reel = AiReel::query()->with(['agent.organization', 'product'])->find($this->reelId);
        if (! $reel || ! in_array($reel->status, ['queued', 'generation_failed'], true) || $reel->runway_task_id) {
            return;
        }
        $reel->update(['status' => 'generating', 'last_error' => null]);

        try {
            $copy = $writer->write($reel);
            $reel->update(['generated_prompt' => $copy['prompt'], 'caption' => $copy['caption']]);
        } catch (\Throwable $exception) {
            $this->failGeneration($reel, $credits, $images, 'Reel copy preparation failed', $exception);

            return;
        }

        try {
            $taskId = $runway->create($copy['prompt'], $reel->source_image_url, $reel->mode === 'custom');
            $reel->update(['runway_task_id' => $taskId]);
            PollAiReelGeneration::dispatch($reel->id)->delay(now()->addSeconds(15))->onQueue('channels');
        } catch (\Throwable $exception) {
            $this->failGeneration($reel, $credits, $images, 'Runway generation request failed', $exception);
        }
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
