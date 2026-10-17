<?php

namespace App\Console\Commands;

use App\Services\PlatformHealthIncidentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'legatus:record-scheduler-heartbeat';

    protected $description = 'Record the Laravel scheduler heartbeat and detect execution gaps';

    public function handle(PlatformHealthIncidentService $incidents): int
    {
        $key = 'legatus:platform-health:scheduler-heartbeat';
        $previous = Cache::get($key);
        $now = now();
        Cache::forever($key, $now->toIso8601String());

        $observations = [];
        if (is_string($previous)) {
            try {
                $lastHeartbeat = Carbon::parse($previous);
                $gapMinutes = $lastHeartbeat->diffInMinutes($now);
                if ($gapMinutes > 3) {
                    $observations[] = [
                        'key' => 'scheduler.heartbeat_gap',
                        'severity' => 'critical',
                        'title' => 'Scheduler heartbeat was interrupted',
                        'summary' => "No scheduler heartbeat was recorded for {$gapMinutes} minutes.",
                        'context' => [
                            'previous_heartbeat' => $lastHeartbeat->toIso8601String(),
                            'recovered_at' => $now->toIso8601String(),
                        ],
                    ];
                }
            } catch (\Throwable) {
                // Replace malformed legacy heartbeat state without creating noise.
            }
        }

        $incidents->syncCategory('scheduler', $observations);

        return self::SUCCESS;
    }
}
