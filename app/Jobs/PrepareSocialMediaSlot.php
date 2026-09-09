<?php

namespace App\Jobs;

use App\Models\SocialMediaPost;
use App\Models\SocialMediaSchedule;
use App\Services\SocialMediaScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

class PrepareSocialMediaSlot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 75;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public int $scheduleId, public string $scheduledFor)
    {
        $this->onQueue('channels');
    }

    public function uniqueId(): string
    {
        return "social-slot:{$this->scheduleId}:{$this->scheduledFor}";
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(10)->expireAfter(90)];
    }

    public function handle(SocialMediaScheduler $scheduler): void
    {
        $schedule = SocialMediaSchedule::query()->with('agent')->find($this->scheduleId);
        if (! $schedule || $schedule->status !== 'active') {
            $this->releasePreparingPosts();

            return;
        }

        $scheduledFor = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $this->scheduledFor, 'UTC');
        $safeIds = $scheduler->prepareDueSlot($schedule, $scheduledFor);
        if ($safeIds === []) {
            return;
        }

        $queuedIds = DB::transaction(function () use ($safeIds): array {
            $posts = SocialMediaPost::query()
                ->whereIn('id', $safeIds)
                ->where('status', 'preparing')
                ->lockForUpdate()
                ->get();
            if ($posts->count() !== count($safeIds)) {
                return [];
            }

            SocialMediaPost::query()->whereIn('id', $safeIds)->update(['status' => 'queued']);

            return $posts->pluck('id')->map(fn ($id): int => (int) $id)->all();
        });

        foreach ($queuedIds as $id) {
            PublishSocialMediaPost::dispatch($id)->onQueue('channels');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->releasePreparingPosts('Product verification will be retried by the scheduler.');
    }

    private function releasePreparingPosts(?string $reason = null): void
    {
        SocialMediaPost::query()
            ->where('social_media_schedule_id', $this->scheduleId)
            ->where('scheduled_for', $this->scheduledFor)
            ->where('status', 'preparing')
            ->update(['status' => 'scheduled', 'failure_reason' => $reason]);
    }
}
