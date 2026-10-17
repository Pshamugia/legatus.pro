<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Notifications\PlatformHealthDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformHealthMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legatus.super_admin_email' => 'operator@example.com']);
        Notification::fake();
        Cache::clear();
    }

    public function test_monitor_opens_deduplicates_and_resolves_operational_incidents_in_one_digest(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'Synthetic failure',
            'failed_at' => now(),
        ]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Catalog sync',
            'status' => 'failed',
            'error' => 'Synthetic failure',
        ]);
        $schedule = $agent->socialMediaSchedules()->create([
            'starts_on' => today(),
            'ends_on' => today()->addDay(),
            'posts_per_day' => 1,
            'categories' => [],
            'providers' => ['facebook'],
            'timezone' => 'Asia/Tbilisi',
            'status' => 'active',
        ]);
        $post = $agent->socialMediaPosts()->create([
            'social_media_schedule_id' => $schedule->id,
            'provider' => 'facebook',
            'status' => 'failed',
            'scheduled_for' => now(),
            'title' => 'Verified product',
            'product_url' => 'https://example.test/product',
            'caption' => 'Verified caption',
            'failure_reason' => 'Synthetic failure',
        ]);

        $this->artisan('legatus:monitor-platform-health')->assertSuccessful();

        $this->assertDatabaseHas('platform_health_incidents', ['incident_key' => 'queue.failed_jobs', 'status' => 'open']);
        $this->assertDatabaseHas('platform_health_incidents', ['incident_key' => 'meta.agent.'.$agent->id, 'status' => 'open']);
        $this->assertDatabaseHas('platform_health_incidents', ['incident_key' => 'catalog.agent.'.$agent->id, 'status' => 'open']);
        Notification::assertSentOnDemandTimes(PlatformHealthDigest::class, 1);
        Notification::assertSentOnDemand(
            PlatformHealthDigest::class,
            fn (PlatformHealthDigest $notification): bool => count($notification->opened) === 3
                && $notification->resolved === [],
        );

        $this->artisan('legatus:monitor-platform-health')->assertSuccessful();
        Notification::assertSentOnDemandTimes(PlatformHealthDigest::class, 1);

        DB::table('failed_jobs')->delete();
        $source->update(['status' => 'ready', 'error' => null]);
        $post->update(['status' => 'published', 'failure_reason' => null]);
        $this->artisan('legatus:monitor-platform-health')->assertSuccessful();

        $this->assertDatabaseMissing('platform_health_incidents', ['status' => 'open']);
        Notification::assertSentOnDemandTimes(PlatformHealthDigest::class, 2);
        Notification::assertSentOnDemand(
            PlatformHealthDigest::class,
            fn (PlatformHealthDigest $notification): bool => $notification->opened === []
                && count($notification->resolved) === 3,
        );
    }

    public function test_scheduler_heartbeat_reports_a_gap_once_and_then_resolves_it(): void
    {
        Cache::forever('legatus:platform-health:scheduler-heartbeat', now()->subMinutes(10)->toIso8601String());

        $this->artisan('legatus:record-scheduler-heartbeat')->assertSuccessful();
        $this->assertDatabaseHas('platform_health_incidents', [
            'incident_key' => 'scheduler.heartbeat_gap',
            'status' => 'open',
        ]);
        Notification::assertSentOnDemandTimes(PlatformHealthDigest::class, 1);

        $this->artisan('legatus:record-scheduler-heartbeat')->assertSuccessful();
        $this->assertDatabaseHas('platform_health_incidents', [
            'incident_key' => 'scheduler.heartbeat_gap',
            'status' => 'resolved',
        ]);
        Notification::assertSentOnDemandTimes(PlatformHealthDigest::class, 2);
    }
}
