<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialMediaPost;
use App\Models\SocialMediaPost;
use App\Services\SocialMediaScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchSocialMediaPosts extends Command
{
    protected $signature = 'legatus:dispatch-social-posts';

    protected $description = 'Queue due social media posts exactly once';

    public function handle(SocialMediaScheduler $scheduler): int
    {
        $ids = DB::transaction(function () use ($scheduler): array {
            $posts = SocialMediaPost::query()
                ->with('schedule.agent')
                ->where('status', 'scheduled')
                ->where('scheduled_for', '<=', now('UTC'))
                ->whereHas('schedule', fn ($query) => $query->where('status', 'active'))
                ->orderBy('scheduled_for')
                ->lockForUpdate()
                ->limit(100)
                ->get();

            $ids = collect();
            $posts->groupBy(fn (SocialMediaPost $post): string => $post->social_media_schedule_id.'|'.$post->getRawOriginal('scheduled_for'))
                ->each(function ($slotPosts) use ($scheduler, $ids): void {
                    $first = $slotPosts->first();
                    $safeIds = $scheduler->prepareDueSlot($first->schedule, $first->scheduled_for);
                    if ($safeIds === []) {
                        return;
                    }

                    SocialMediaPost::query()->whereIn('id', $safeIds)->where('status', 'scheduled')->update(['status' => 'queued']);
                    $ids->push(...$safeIds);
                });

            return $ids->all();
        });

        foreach ($ids as $id) {
            PublishSocialMediaPost::dispatch($id)->onQueue('channels');
        }
        $this->info(count($ids).' social posts queued.');

        return self::SUCCESS;
    }
}
