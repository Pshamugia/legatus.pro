<?php

namespace App\Jobs;

use App\Models\SocialMediaPost;
use App\Models\SocialMediaSchedule;
use App\Services\SocialMediaAiPhotoEditor;
use App\Services\SocialMediaScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    public function handle(SocialMediaScheduler $scheduler, ?SocialMediaAiPhotoEditor $photoEditor = null): void
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

        if ($schedule->ai_photo_editor) {
            $this->prepareAiPhoto($safeIds, $photoEditor ?? app(SocialMediaAiPhotoEditor::class));
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
        $schedule = SocialMediaSchedule::query()->find($this->scheduleId);
        if ($schedule?->ai_photo_editor) {
            SocialMediaPost::query()
                ->where('social_media_schedule_id', $this->scheduleId)
                ->where('scheduled_for', $this->scheduledFor)
                ->where('status', 'preparing')
                ->update([
                    'status' => 'failed',
                    'failure_reason' => Str::limit(
                        'AI Photo Editor failed; the original product image was not published. '.($exception?->getMessage() ?: 'Unknown image generation error.'),
                        1000,
                    ),
                ]);

            return;
        }

        $this->releasePreparingPosts('Product verification will be retried by the scheduler.');
    }

    /** @param list<int> $postIds */
    private function prepareAiPhoto(array $postIds, SocialMediaAiPhotoEditor $photoEditor): void
    {
        $posts = SocialMediaPost::query()
            ->with(['agent', 'product'])
            ->whereIn('id', $postIds)
            ->where('status', 'preparing')
            ->orderBy('id')
            ->get();
        if ($posts->count() !== count($postIds)) {
            throw new \RuntimeException('AI Photo Editor could not prepare the complete multi-channel product slot.');
        }

        $prepared = $posts->first(fn (SocialMediaPost $post): bool => filled($post->ai_image_generated_at) && filled($post->image_url));
        if ($prepared) {
            SocialMediaPost::query()->whereIn('id', $postIds)->update([
                'image_url' => $prepared->image_url,
                'ai_image_generation_attempted_at' => $prepared->ai_image_generation_attempted_at,
                'ai_image_generated_at' => $prepared->ai_image_generated_at,
                'ai_image_model' => $prepared->ai_image_model,
                'ai_image_source_url' => $prepared->ai_image_source_url,
                'failure_reason' => null,
            ]);

            return;
        }

        SocialMediaPost::query()->whereIn('id', $postIds)->update([
            'ai_image_generation_attempted_at' => now(),
            'failure_reason' => null,
        ]);

        try {
            $result = $photoEditor->generate($posts->firstOrFail());
        } catch (\Throwable $exception) {
            SocialMediaPost::query()->whereIn('id', $postIds)->update([
                'failure_reason' => Str::limit('AI Photo Editor attempt failed: '.$exception->getMessage(), 1000),
            ]);

            throw $exception;
        }

        SocialMediaPost::query()->whereIn('id', $postIds)->update([
            'image_url' => $result['url'],
            'ai_image_generated_at' => now(),
            'ai_image_model' => $result['model'],
            'ai_image_source_url' => $result['source_url'],
            'failure_reason' => null,
        ]);
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
