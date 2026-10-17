<?php

namespace App\Console\Commands;

use App\Jobs\PublishSocialMediaPost;
use App\Models\Agent;
use App\Models\SocialMediaPost;
use App\Models\SocialMediaPublicationIdentity;
use App\Services\MetaGraphClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepublishSocialMediaPost extends Command
{
    protected $signature = 'legatus:republish-social-post
        {--agent= : Exact business name}
        {--provider=facebook : One provider or a comma-separated provider list}
        {--url= : Exact product URL}
        {--scheduled-local= : Local schedule minute, for example 2026-10-17 19:00}
        {--timezone=Asia/Tbilisi : Schedule timezone}
        {--remote-posts-deleted : Confirm that the selected live posts were already deleted manually}
        {--confirm : Delete the live post and queue its corrected replacement}';

    protected $description = 'Safely delete one exactly identified live social post and queue one corrected replacement';

    public function handle(MetaGraphClient $meta): int
    {
        $businessName = trim((string) $this->option('agent'));
        $providers = collect(explode(',', (string) $this->option('provider')))
            ->map(fn (string $provider): string => trim($provider))
            ->filter()
            ->unique()
            ->values();
        $url = trim((string) $this->option('url'));
        $scheduledLocal = trim((string) $this->option('scheduled-local'));
        $timezone = trim((string) $this->option('timezone'));

        if ($businessName === '' || $url === '' || $scheduledLocal === '' || $timezone === '') {
            $this->error('The --agent, --url, --scheduled-local and --timezone options are required.');

            return self::FAILURE;
        }
        if ($providers->isEmpty() || $providers->diff(['facebook', 'instagram', 'threads'])->isNotEmpty()) {
            $this->error('Supported providers are facebook, instagram and threads.');

            return self::FAILURE;
        }
        if (! $this->option('confirm')) {
            $this->error('Nothing changed. Add --confirm only after checking every selector.');

            return self::FAILURE;
        }

        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d H:i', $scheduledLocal, $timezone)->utc();
        } catch (\Throwable) {
            $this->error('The local schedule must use the exact format YYYY-MM-DD HH:MM and a valid timezone.');

            return self::FAILURE;
        }
        $to = $from->addMinute();
        $agent = Agent::query()->where('business_name', $businessName)->first();
        if (! $agent) {
            $this->error('No business matched the exact --agent value.');

            return self::FAILURE;
        }

        $manualDeletionConfirmed = (bool) $this->option('remote-posts-deleted');
        if ($providers->contains(fn (string $provider): bool => $provider !== 'facebook') && ! $manualDeletionConfirmed) {
            $this->error('Nothing changed: Instagram and Threads posts must be deleted manually first, then confirmed with --remote-posts-deleted.');

            return self::FAILURE;
        }

        $targets = collect();
        foreach ($providers as $provider) {
            $matches = $agent->socialMediaPosts()
                ->where('provider', $provider)
                ->where('status', 'published')
                ->whereNull('provider_deleted_at')
                ->where('product_url', $url)
                ->where('scheduled_for', '>=', $from)
                ->where('scheduled_for', '<', $to)
                ->get();
            if ($matches->count() !== 1) {
                $this->error("Nothing changed: expected exactly one live {$provider} post, found {$matches->count()}.");

                return self::FAILURE;
            }

            /** @var SocialMediaPost $post */
            $post = $matches->first();
            $connection = $agent->channelConnections()
                ->where('provider', $provider)
                ->where('status', 'active')
                ->first();
            if (! $connection || ! $connection->isActive()) {
                $this->error("Nothing changed: the {$provider} connection is not active.");

                return self::FAILURE;
            }

            $retryAt = $this->availableRetryTime($post);
            if (! $retryAt) {
                $this->error("Nothing changed: no unused second remains for {$provider} in this schedule minute.");

                return self::FAILURE;
            }
            $targets->push(compact('post', 'connection', 'retryAt'));
        }

        if (! $manualDeletionConfirmed) {
            $target = $targets->sole();
            /** @var SocialMediaPost $facebookPost */
            $facebookPost = $target['post'];
            $this->line("Deleting Facebook post {$facebookPost->provider_post_id} and replacing local post {$facebookPost->id}.");
            $meta->deleteFacebookPost($target['connection'], (string) $facebookPost->provider_post_id);
        }

        $newPostIds = DB::transaction(function () use ($targets): array {
            return $targets->map(function (array $target): int {
                /** @var SocialMediaPost $post */
                $post = $target['post'];
                $retry = $post->replicate();

                SocialMediaPublicationIdentity::query()
                    ->where('social_media_post_id', $post->id)
                    ->delete();

                $post->update([
                    'status' => 'skipped',
                    'provider_deleted_at' => now(),
                    'failure_reason' => 'Deleted for a corrected one-time republication.',
                ]);

                $retry->forceFill([
                    'status' => 'queued',
                    'scheduled_for' => $target['retryAt'],
                    'caption' => '',
                    'attempts' => 0,
                    'provider_post_id' => null,
                    'published_at' => null,
                    'provider_updated_at' => null,
                    'provider_deleted_at' => null,
                    'failure_reason' => null,
                    'ai_generation_attempted_at' => null,
                    'ai_generated_at' => null,
                    'ai_model' => null,
                    'story_status' => null,
                    'provider_story_id' => null,
                    'story_published_at' => null,
                    'story_attempts' => 0,
                    'story_failure_reason' => null,
                ])->save();

                return (int) $retry->id;
            })->all();
        });

        foreach ($newPostIds as $newPostId) {
            PublishSocialMediaPost::dispatch($newPostId)->onQueue('channels');
        }
        $this->info('Corrected replacements queued as social posts '.implode(', ', $newPostIds).'.');

        return self::SUCCESS;
    }

    private function availableRetryTime(SocialMediaPost $post): ?CarbonImmutable
    {
        $scheduledFor = CarbonImmutable::instance($post->scheduled_for);
        for ($second = 1; $second < 60; $second++) {
            $candidate = $scheduledFor->startOfMinute()->addSeconds($second);
            $exists = SocialMediaPost::query()
                ->where('social_media_schedule_id', $post->social_media_schedule_id)
                ->where('provider', $post->provider)
                ->where('scheduled_for', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return null;
    }
}
