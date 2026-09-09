<?php

namespace App\Console\Commands;

use App\Jobs\PrepareSocialMediaSlot;
use App\Models\SocialMediaPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchSocialMediaPosts extends Command
{
    protected $signature = 'legatus:dispatch-social-posts';

    protected $description = 'Queue due social media posts exactly once';

    public function handle(): int
    {
        // If a worker was terminated after a slot was claimed but before its
        // preparation job completed, make the slot eligible again. Active
        // preparation jobs have a 75-second timeout, so ten minutes is safely
        // beyond their normal lifetime.
        SocialMediaPost::query()
            ->where('status', 'preparing')
            ->where('updated_at', '<=', now('UTC')->subMinutes(10))
            ->update([
                'status' => 'scheduled',
                'failure_reason' => 'A stale preparation claim was recovered automatically.',
            ]);

        $posts = SocialMediaPost::query()
            ->with('schedule.agent')
            ->where('status', 'scheduled')
            ->where('scheduled_for', '<=', now('UTC'))
            ->whereHas('schedule', fn ($query) => $query->where('status', 'active'))
            ->orderBy('scheduled_for')
            ->limit(100)
            ->get();

        $queuedSlots = 0;
        $posts->groupBy(fn (SocialMediaPost $post): string => $post->social_media_schedule_id.'|'.$post->getRawOriginal('scheduled_for'))
            ->each(function ($slotPosts) use (&$queuedSlots): void {
                $ids = $slotPosts->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $claimed = DB::transaction(function () use ($ids): bool {
                    $locked = SocialMediaPost::query()->whereIn('id', $ids)->lockForUpdate()->get(['id', 'status']);
                    if ($locked->count() !== count($ids) || $locked->contains(fn (SocialMediaPost $post): bool => $post->status !== 'scheduled')) {
                        return false;
                    }

                    SocialMediaPost::query()->whereIn('id', $ids)->update([
                        'status' => 'preparing',
                        'failure_reason' => null,
                    ]);

                    return true;
                });
                if (! $claimed) {
                    return;
                }

                $first = $slotPosts->first();
                PrepareSocialMediaSlot::dispatch(
                    (int) $first->social_media_schedule_id,
                    (string) $first->getRawOriginal('scheduled_for'),
                )->onQueue('channels');
                $queuedSlots++;
            });

        $this->info($queuedSlots.' social '.($queuedSlots === 1 ? 'slot' : 'slots').' queued for preparation.');

        return self::SUCCESS;
    }
}
