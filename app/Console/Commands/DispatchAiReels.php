<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiReel;
use App\Jobs\PublishAiReelDelivery;
use App\Models\AiReel;
use App\Models\AiReelDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchAiReels extends Command
{
    protected $signature = 'legatus:dispatch-ai-reels';

    protected $description = 'Queue generated AI Reels whose publishing time has arrived';

    public function handle(): int
    {
        AiReel::query()->where('status', 'queued')->whereNull('runway_task_id')
            ->orderBy('id')->limit(100)->pluck('id')
            ->each(fn ($id) => GenerateAiReel::dispatch((int) $id)->onQueue('channels'));

        AiReelDelivery::query()
            ->where('status', 'scheduled')
            ->whereHas('reel', fn ($query) => $query->where('status', 'ready')->where('scheduled_for', '<=', now())
                ->where(fn ($reelQuery) => $reelQuery->where('mode', 'custom')
                    ->orWhereHas('schedule', fn ($scheduleQuery) => $scheduleQuery->where('status', 'active'))))
            ->orderBy('id')->limit(100)->pluck('id')->each(function ($id): void {
                $claimed = DB::transaction(fn (): bool => AiReelDelivery::query()
                    ->whereKey($id)->where('status', 'scheduled')->update(['status' => 'queued']) === 1);
                if ($claimed) {
                    PublishAiReelDelivery::dispatch((int) $id)->onQueue('channels');
                }
            });

        return self::SUCCESS;
    }
}
