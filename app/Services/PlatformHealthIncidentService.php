<?php

namespace App\Services;

use App\Models\PlatformHealthIncident;
use App\Notifications\PlatformHealthDigest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PlatformHealthIncidentService
{
    /**
     * @param  array<int, array{key: string, severity?: string, title: string, summary?: string|null, context?: array}>  $observations
     */
    public function syncCategory(string $category, array $observations): void
    {
        $this->syncCategories([$category => $observations]);
    }

    /**
     * @param  array<string, array<int, array{key: string, severity?: string, title: string, summary?: string|null, context?: array}>>  $categories
     */
    public function syncCategories(array $categories): void
    {
        if (! Schema::hasTable('platform_health_incidents')) {
            return;
        }

        $normalizedCategories = collect($categories)->map(function (array $observations, string $category) {
            return collect($observations)
                ->filter(fn (array $issue): bool => filled($issue['key'] ?? null) && filled($issue['title'] ?? null))
                ->mapWithKeys(function (array $issue) use ($category): array {
                    $key = Str::limit(trim((string) $issue['key']), 255, '');

                    return [$key => [
                        'incident_key' => $key,
                        'category' => Str::limit($category, 48, ''),
                        'severity' => in_array($issue['severity'] ?? null, ['warning', 'error', 'critical'], true)
                            ? $issue['severity'] : 'error',
                        'title' => Str::limit(trim((string) $issue['title']), 255),
                        'summary' => filled($issue['summary'] ?? null)
                            ? Str::limit(trim((string) $issue['summary']), 1000)
                            : null,
                        'context' => is_array($issue['context'] ?? null) ? $issue['context'] : [],
                    ]];
                });
        });

        [$opened, $resolved] = DB::transaction(function () use ($normalizedCategories): array {
            $now = now();
            $opened = collect();
            $resolved = collect();

            foreach ($normalizedCategories as $category => $normalized) {
                $existing = PlatformHealthIncident::query()
                    ->where('category', $category)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('incident_key');

                foreach ($normalized as $key => $issue) {
                    $incident = $existing->get($key);
                    if (! $incident) {
                        $incident = PlatformHealthIncident::create($issue + [
                            'status' => 'open',
                            'occurrences' => 1,
                            'first_detected_at' => $now,
                            'last_detected_at' => $now,
                        ]);
                        $opened->push($incident);

                        continue;
                    }

                    $wasResolved = $incident->status === 'resolved';
                    $incident->update($issue + [
                        'status' => 'open',
                        'occurrences' => $wasResolved ? 1 : $incident->occurrences + 1,
                        'first_detected_at' => $wasResolved ? $now : $incident->first_detected_at,
                        'last_detected_at' => $now,
                        'resolved_at' => null,
                    ]);
                    if ($wasResolved) {
                        $opened->push($incident->fresh());
                    }
                }

                $existing->filter(fn (PlatformHealthIncident $incident): bool => $incident->status === 'open' && ! $normalized->has($incident->incident_key)
                )->each(function (PlatformHealthIncident $incident) use ($now, $resolved): void {
                    $incident->update(['status' => 'resolved', 'resolved_at' => $now]);
                    $resolved->push($incident->fresh());
                });
            }

            return [$opened, $resolved];
        });

        $this->notify($opened->all(), $resolved->all());
    }

    /**
     * @param  array<int, PlatformHealthIncident>  $opened
     * @param  array<int, PlatformHealthIncident>  $resolved
     */
    private function notify(array $opened, array $resolved): void
    {
        if ($opened === [] && $resolved === []) {
            return;
        }

        $email = trim((string) config('legatus.super_admin_email'));
        if ($email === '') {
            return;
        }

        $safe = fn (PlatformHealthIncident $incident): array => [
            'title' => $incident->title,
            'summary' => $incident->summary,
        ];

        try {
            Notification::route('mail', $email)->notify(new PlatformHealthDigest(
                collect($opened)->map($safe)->values()->all(),
                collect($resolved)->map($safe)->values()->all(),
            ));
        } catch (Throwable $exception) {
            Log::error('Could not send the platform health notification.', [
                'exception' => $exception::class,
            ]);
        }
    }
}
