<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\SocialMediaSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SocialMediaScheduler
{
    public function __construct(
        private readonly SocialMediaTemplateService $templates,
        private readonly SocialMediaTemplateRenderer $renderer,
    ) {}

    public function create(Agent $agent, array $data): SocialMediaSchedule
    {
        $productsByProvider = collect($data['providers'])->mapWithKeys(fn (string $provider): array => [
            $provider => $this->eligibleProducts($agent, $data['categories'] ?? [], [$provider]),
        ]);
        foreach ($productsByProvider as $provider => $products) {
            if ($products->isEmpty()) {
                $requirement = $provider === 'instagram' ? 'public links and images' : 'public links';
                throw ValidationException::withMessages([
                    'categories' => 'No products with usable '.$requirement.' were found for '.ucfirst($provider).' in this selection.',
                ]);
            }
        }

        $templateSnapshots = $this->templates->snapshots($agent, $data['providers']);

        return DB::transaction(function () use ($agent, $data, $productsByProvider, $templateSnapshots): SocialMediaSchedule {
            $schedule = $agent->socialMediaSchedules()->create([
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'posts_per_day' => $data['posts_per_day'],
                'categories' => array_values($data['categories'] ?? []),
                'providers' => array_values($data['providers']),
                'timezone' => $data['timezone'],
                'posting_times' => $data['posting_times'] ?? null,
                'template_snapshots' => $templateSnapshots,
                'status' => 'active',
            ]);

            $starts = CarbonImmutable::parse($data['starts_on'], $data['timezone'])->startOfDay();
            $ends = CarbonImmutable::parse($data['ends_on'], $data['timezone'])->startOfDay();
            $productIndexes = collect($data['providers'])
                ->mapWithKeys(fn (string $provider): array => [$provider => 0])
                ->all();
            for ($day = $starts; $day->lte($ends); $day = $day->addDay()) {
                $slots = isset($data['posting_times'])
                    ? $this->customDailyTimes($day, $data['posting_times'])
                    : $this->dailyTimes($day, (int) $data['posts_per_day']);
                foreach ($slots as $slot) {
                    foreach ($data['providers'] as $provider) {
                        $products = $productsByProvider[$provider];
                        $product = $products[$productIndexes[$provider] % $products->count()];
                        $productIndexes[$provider]++;
                        $schedule->posts()->create($this->postAttributes(
                            $agent,
                            $product,
                            $provider,
                            $slot,
                            $templateSnapshots[$provider],
                        ));
                    }
                }
            }

            return $schedule->loadCount('posts');
        });
    }

    public function eligibleProducts(Agent $agent, array $categories = [], array $providers = []): Collection
    {
        $wanted = collect($categories)->map(fn ($value) => Str::lower(trim((string) $value)))->filter()->unique();
        $instagram = in_array('instagram', $providers, true);

        return $agent->customerProducts()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->get()
            ->filter(function ($product) use ($wanted, $instagram): bool {
                $url = data_get($product->metadata, 'product_url');
                if (! $this->publicHttpUrl($url) || ($instagram && $product->publicImageUrl() === null)) {
                    return false;
                }
                if ($wanted->isEmpty()) {
                    return true;
                }

                $taxonomy = collect([
                    $product->category,
                    ...((array) data_get($product->metadata, 'genres', [])),
                    ...((array) data_get($product->metadata, 'taxonomy', [])),
                ])->filter(fn ($value): bool => is_scalar($value))->map(fn ($value) => Str::lower(trim((string) $value)));

                return $wanted->contains(fn (string $category): bool => $taxonomy->contains(
                    fn (string $label): bool => $label === $category || Str::contains($label, $category) || Str::contains($category, $label),
                ));
            })
            ->shuffle()
            ->values();
    }

    private function dailyTimes(CarbonImmutable $day, int $count): array
    {
        $startMinute = 9 * 60;
        $endMinute = 21 * 60;
        $now = CarbonImmutable::now($day->getTimezone());
        if ($day->isSameDay($now)) {
            $startMinute = max($startMinute, ($now->hour * 60) + $now->minute + 5);
            $endMinute = max($endMinute, min((23 * 60) + 55, $startMinute + $count - 1));
        }
        if ($count === 1) {
            $minute = max($startMinute, 12 * 60);

            return [$day->setTime(intdiv($minute, 60), $minute % 60)->utc()];
        }

        $step = ($endMinute - $startMinute) / ($count - 1);

        return collect(range(0, $count - 1))->map(function (int $index) use ($day, $startMinute, $step): CarbonImmutable {
            $minute = (int) round($startMinute + ($step * $index));

            return $day->setTime(intdiv($minute, 60), $minute % 60)->utc();
        })->all();
    }

    /** @param list<string> $times */
    private function customDailyTimes(CarbonImmutable $day, array $times): array
    {
        return collect($times)->map(function (string $time) use ($day): CarbonImmutable {
            [$hour, $minute] = array_map('intval', explode(':', $time));

            return $day->setTime($hour, $minute)->utc();
        })->all();
    }

    private function postAttributes(Agent $agent, $product, string $provider, CarbonImmutable $slot, array $template): array
    {
        $url = (string) data_get($product->metadata, 'product_url');
        $image = $product->publicImageUrl();
        $description = trim(strip_tags((string) $product->description));
        $description = Str::limit(preg_replace('/\s+/u', ' ', $description) ?? '', 700, '…');

        return [
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'provider' => $provider,
            'status' => 'scheduled',
            'scheduled_for' => $slot,
            'title' => $product->name,
            'description' => $description ?: null,
            'product_url' => $url,
            'image_url' => $this->publicHttpUrl($image) ? $image : null,
            'caption' => $this->renderer->render($provider, $template, $agent, $product),
        ];
    }

    private function publicHttpUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
