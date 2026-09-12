<?php

namespace App\Jobs;

use App\Models\AiReel;
use App\Services\AiReelPromptWriter;
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

    public function handle(AiReelPromptWriter $writer, RunwayClient $runway, ReelCreditService $credits): void
    {
        $reel = AiReel::query()->with(['agent.organization', 'product'])->find($this->reelId);
        if (! $reel || ! in_array($reel->status, ['queued', 'generation_failed'], true) || $reel->runway_task_id) {
            return;
        }
        $reel->update(['status' => 'generating', 'last_error' => null]);
        try {
            $copy = $writer->write($reel);
            $taskId = $runway->create($copy['prompt'], $reel->source_image_url, $reel->mode === 'custom');
            $reel->update(['runway_task_id' => $taskId, 'generated_prompt' => $copy['prompt'], 'caption' => $copy['caption']]);
            PollAiReelGeneration::dispatch($reel->id)->delay(now()->addSeconds(15))->onQueue('channels');
        } catch (\Throwable $exception) {
            $reel->update(['status' => 'generation_failed', 'last_error' => Str::limit($exception->getMessage(), 1000)]);
            $credits->refund($reel, 'generation_failed');
        }
    }
}
