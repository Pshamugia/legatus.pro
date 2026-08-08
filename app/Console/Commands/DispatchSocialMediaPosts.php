<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialMediaPost;
use App\Models\SocialMediaPost;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchSocialMediaPosts extends Command
{
    protected $signature = 'legatus:dispatch-social-posts';

    protected $description = 'Queue due social media posts exactly once';

    public function handle(): int
    {
        $ids = DB::transaction(function (): array {
            $posts = SocialMediaPost::query()
                ->where('status', 'scheduled')
                ->where('scheduled_for', '<=', now())
                ->whereHas('schedule', fn ($query) => $query->where('status', 'active'))
                ->orderBy('scheduled_for')
                ->lockForUpdate()
                ->limit(100)
                ->get();
            $posts->each->update(['status' => 'queued']);

            return $posts->pluck('id')->all();
        });

        foreach ($ids as $id) {
            PublishSocialMediaPost::dispatch($id)->onQueue('channels');
        }
        $this->info(count($ids).' social posts queued.');

        return self::SUCCESS;
    }
}
