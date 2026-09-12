<?php

namespace App\Services;

use App\Jobs\GenerateAiReel;
use App\Models\Agent;
use App\Models\AiReelSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiReelScheduler
{
    public function __construct(private readonly ReelCreditService $credits) {}

    public function create(Agent $agent, array $data): AiReelSchedule
    {
        $products = $agent->customerProducts()->where('is_active', true)->where('stock', '>', 0)->get()
            ->filter(fn ($product): bool => $product->publicImageUrl() !== null)
            ->filter(function ($product) use ($data): bool {
                $selected = collect($data['categories'] ?? [])->map(fn ($value) => Str::lower(trim((string) $value)))->filter();
                if ($selected->isEmpty()) {
                    return true;
                }
                $values = collect([$product->category, ...((array) data_get($product->metadata, 'genres', [])), ...((array) data_get($product->metadata, 'taxonomy', []))])
                    ->filter(fn ($value) => is_scalar($value))->map(fn ($value) => Str::lower(trim((string) $value)));

                return $selected->intersect($values)->isNotEmpty();
            })->shuffle()->values();
        $variants = $this->languageVariants($agent, $products, $data['languages'] ?? []);
        if ($variants->count() < (int) $data['reel_count']) {
            throw ValidationException::withMessages(['reel_count' => 'The selected catalog does not have enough available products with public images.']);
        }
        $slots = $this->slots($data);
        if ($slots->count() < (int) $data['reel_count']) {
            throw ValidationException::withMessages(['reel_count' => 'The selected dates and times do not contain enough future Reel slots.']);
        }

        return DB::transaction(function () use ($agent, $data, $variants, $slots): AiReelSchedule {
            $schedule = $agent->aiReelSchedules()->create([
                'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'reel_count' => $data['reel_count'],
                'categories' => array_values($data['categories'] ?? []), 'languages' => array_values($data['languages'] ?? []),
                'providers' => array_values($data['providers']), 'timezone' => $data['timezone'],
                'timing_mode' => $data['timing_mode'], 'posting_times' => $data['posting_times'] ?? null,
                'ai_tone' => $data['ai_tone'], 'status' => 'active',
            ]);
            $this->credits->debit($agent->organization, (int) $data['reel_count'], 'reel-schedule:'.$schedule->id, ['schedule_id' => $schedule->id]);

            for ($index = 0; $index < (int) $data['reel_count']; $index++) {
                $variant = $variants[$index];
                $product = $variant['product'];
                $language = $variant['language'];
                $localized = $language ? (array) data_get($product->metadata, 'localized.'.$language, []) : [];
                $reel = $schedule->reels()->create([
                    'agent_id' => $agent->id, 'product_id' => $product->id, 'mode' => 'scheduled',
                    'language' => $language, 'providers' => array_values($data['providers']),
                    'source_image_url' => $localized['image'] ?? $product->publicImageUrl(),
                    'scheduled_for' => $slots[$index]->utc(), 'status' => 'queued',
                ]);
                foreach ($data['providers'] as $provider) {
                    $reel->deliveries()->create(['provider' => $provider, 'status' => 'scheduled']);
                }
                GenerateAiReel::dispatch($reel->id)->onQueue('channels')->afterCommit();
            }

            return $schedule;
        }, 3);
    }

    private function languageVariants(Agent $agent, Collection $products, array $languages): Collection
    {
        $wanted = collect($languages)->map(fn ($value): string => trim((string) $value))->filter();
        if ($wanted->isEmpty()) {
            return $products->map(fn ($product): array => ['product' => $product, 'language' => null])->values();
        }

        $sources = $agent->knowledgeSources()->where('source_scope', 'language')->oldest('id')
            ->get(['id', 'taxonomy_label', 'status']);
        $primary = Str::lower(trim((string) optional(
            $sources->first(fn ($source): bool => $source->status === 'ready' && filled($source->taxonomy_label)),
        )->taxonomy_label));
        $sourceLanguages = $sources->filter(fn ($source): bool => filled($source->taxonomy_label))
            ->mapWithKeys(fn ($source): array => [(int) $source->id => Str::lower(trim((string) $source->taxonomy_label))]);

        return $products->flatMap(function ($product) use ($wanted, $primary, $sourceLanguages): array {
            $localized = (array) data_get($product->metadata, 'localized', []);
            $localizedKeys = collect(array_keys($localized))
                ->mapWithKeys(fn ($language): array => [Str::lower(trim((string) $language)) => (string) $language]);
            $sourceLanguage = $sourceLanguages->get((int) data_get($product->metadata, 'source_id'));

            return $wanted->filter(function (string $language) use ($localizedKeys, $primary, $sourceLanguage): bool {
                $key = Str::lower($language);

                return $localizedKeys->has($key)
                    || ($primary !== '' && $key === $primary && ($sourceLanguage === null || $sourceLanguage === $primary));
            })->map(fn (string $language): array => [
                'product' => $product,
                'language' => $localizedKeys->get(Str::lower($language), $language),
            ])->values()->all();
        })->shuffle()->values();
    }

    private function slots(array $data)
    {
        $start = CarbonImmutable::parse($data['starts_on'], $data['timezone'])->startOfDay();
        $end = CarbonImmutable::parse($data['ends_on'], $data['timezone'])->endOfDay();
        $times = $data['timing_mode'] === 'custom'
            ? collect($data['posting_times'] ?? [])->sort()->values()
            : collect(['10:00', '13:00', '16:00', '19:00']);
        $slots = collect();
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            foreach ($times as $time) {
                [$hour, $minute] = array_map('intval', explode(':', $time));
                $slot = $day->setTime($hour, $minute);
                if ($slot->greaterThan(now($data['timezone'])->addMinutes(5))) {
                    $slots->push($slot);
                }
            }
        }

        return $slots;
    }
}
