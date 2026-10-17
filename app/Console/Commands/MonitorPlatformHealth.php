<?php

namespace App\Console\Commands;

use App\Models\KnowledgeSource;
use App\Models\SocialMediaPost;
use App\Services\PlatformHealthIncidentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MonitorPlatformHealth extends Command
{
    protected $signature = 'legatus:monitor-platform-health';

    protected $description = 'Record and notify platform-wide operational health incidents';

    public function handle(PlatformHealthIncidentService $incidents): int
    {
        if (! Schema::hasTable('platform_health_incidents')
            || ! Schema::hasTable('platform_health_monitor_states')) {
            $this->error('Run database migrations before platform health monitoring.');

            return self::FAILURE;
        }

        $monitoringStartedAt = DB::table('platform_health_monitor_states')
            ->where('monitor_key', 'operational')
            ->value('started_at');
        if (! $monitoringStartedAt) {
            $this->error('Platform health monitoring baseline is missing.');

            return self::FAILURE;
        }

        $incidents->syncCategories([
            'queue' => $this->queueObservations($monitoringStartedAt),
            'meta_publishing' => $this->metaPublishingObservations($monitoringStartedAt),
            'catalog_sync' => $this->catalogSyncObservations($monitoringStartedAt),
        ]);

        $this->info('Platform health state synchronized.');

        return self::SUCCESS;
    }

    private function queueObservations(mixed $monitoringStartedAt): array
    {
        $observations = [];
        $failedJobs = Schema::hasTable('failed_jobs')
            ? DB::table('failed_jobs')->where('failed_at', '>=', $monitoringStartedAt)
            : null;
        $failedCount = $failedJobs?->count() ?? 0;
        if ($failedCount > 0) {
            $latest = (clone $failedJobs)->latest('id')->first(['queue', 'failed_at']);
            $observations[] = [
                'key' => 'queue.failed_jobs',
                'severity' => 'critical',
                'title' => 'Queue jobs have failed',
                'summary' => "{$failedCount} failed job(s) require review".($latest?->queue ? "; latest queue: {$latest->queue}" : '').'.',
                'context' => ['count' => $failedCount, 'latest_failed_at' => $latest?->failed_at],
            ];
        }

        if (Schema::hasTable('jobs')) {
            $oldestCreatedAt = DB::table('jobs')->min('created_at');
            $backlogCount = DB::table('jobs')->count();
            if ($backlogCount > 0 && is_numeric($oldestCreatedAt) && (int) $oldestCreatedAt < now()->subMinutes(15)->timestamp) {
                $observations[] = [
                    'key' => 'queue.stale_backlog',
                    'severity' => 'error',
                    'title' => 'Queue backlog is not draining',
                    'summary' => "{$backlogCount} queued job(s); the oldest has waited more than 15 minutes.",
                    'context' => ['count' => $backlogCount, 'oldest_created_at' => (int) $oldestCreatedAt],
                ];
            }
        }

        return $observations;
    }

    private function metaPublishingObservations(mixed $monitoringStartedAt): array
    {
        $posts = SocialMediaPost::query()
            ->where('updated_at', '>=', $monitoringStartedAt)
            ->where(function ($query): void {
                $query->whereIn('status', ['failed', 'delivery_unknown'])
                    ->orWhereIn('story_status', ['failed', 'delivery_unknown']);
            })
            ->with('agent:id,organization_id,business_name')
            ->get(['id', 'agent_id', 'provider', 'status', 'story_status', 'failure_reason', 'story_failure_reason', 'updated_at']);

        $observations = $posts->groupBy('agent_id')->map(function ($agentPosts, $agentId): array {
            $feedFailures = $agentPosts->whereIn('status', ['failed', 'delivery_unknown'])->count();
            $storyFailures = $agentPosts->whereIn('story_status', ['failed', 'delivery_unknown'])->count();
            $latest = $agentPosts->sortByDesc('updated_at')->first();
            $business = $latest?->agent?->business_name ?: "Agent #{$agentId}";

            return [
                'key' => 'meta.agent.'.$agentId,
                'severity' => $agentPosts->contains(fn ($post): bool => $post->status === 'delivery_unknown' || $post->story_status === 'delivery_unknown'
                ) ? 'critical' : 'error',
                'title' => 'Meta publishing needs attention · '.$business,
                'summary' => "{$feedFailures} feed and {$storyFailures} Story delivery issue(s).",
                'context' => [
                    'agent_id' => (int) $agentId,
                    'feed_failures' => $feedFailures,
                    'story_failures' => $storyFailures,
                    'latest_post_id' => $latest?->id,
                ],
            ];
        })->values()->all();

        return $observations;
    }

    private function catalogSyncObservations(mixed $monitoringStartedAt): array
    {
        $sources = KnowledgeSource::query()
            ->where('updated_at', '>=', $monitoringStartedAt)
            ->where(function ($query): void {
                $query->where('status', 'failed')
                    ->orWhere(function ($processing): void {
                        $processing->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(30));
                    });
            })
            ->with('agent:id,organization_id,business_name')
            ->get(['id', 'agent_id', 'name', 'status', 'error', 'updated_at']);

        $observations = $sources->groupBy('agent_id')->map(function ($agentSources, $agentId): array {
            $latest = $agentSources->sortByDesc('updated_at')->first();
            $business = $latest?->agent?->business_name ?: "Agent #{$agentId}";
            $names = $agentSources->pluck('name')->filter()->take(3)->join(', ');

            return [
                'key' => 'catalog.agent.'.$agentId,
                'severity' => 'error',
                'title' => 'Catalog or knowledge sync needs attention · '.$business,
                'summary' => $agentSources->count().' source(s) failed or remained stuck'.($names ? ': '.Str::limit($names, 300) : '').'.',
                'context' => [
                    'agent_id' => (int) $agentId,
                    'source_ids' => $agentSources->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                ],
            ];
        })->values()->all();

        return $observations;
    }
}
