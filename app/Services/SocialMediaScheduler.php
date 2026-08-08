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
    public function create(Agent $agent, array $data): SocialMediaSchedule
    {
        $products = $this->eligibleProducts($agent, $data['categories'] ?? [], $data['providers']);
        if ($products->isEmpty()) {
            throw ValidationException::withMessages([
                'categories' => 'No public products with usable links and images were found for this selection.',
            ]);
        }

        return DB::transaction(function () use ($agent, $data, $products): SocialMediaSchedule {
            $schedule = $agent->socialMediaSchedules()->create([
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'posts_per_day' => $data['posts_per_day'],
                'categories' => array_values($data['categories'] ?? []),
                'providers' => array_values($data['providers']),
                'timezone' => $data['timezone'],
                'status' => 'active',
            ]);

            $starts = CarbonImmutable::parse($data['starts_on'], $data['timezone'])->startOfDay();
            $ends = CarbonImmutable::parse($data['ends_on'], $data['timezone'])->startOfDay();
            $productIndex = 0;
            for ($day = $starts; $day->lte($ends); $day = $day->addDay()) {
                foreach ($this->dailyTimes($day, (int) $data['posts_per_day']) as $slot) {
                    $product = $products[$productIndex % $products->count()];
                    $productIndex++;
                    foreach ($data['providers'] as $provider) {
                        $schedule->posts()->create($this->postAttributes($agent, $product, $provider, $slot));
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
                $image = data_get($product->metadata, 'image');
                if (! $this->publicHttpUrl($url) || ($instagram && ! $this->publicHttpUrl($image))) {
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

    private function postAttributes(Agent $agent, $product, string $provider, CarbonImmutable $slot): array
    {
        $url = (string) data_get($product->metadata, 'product_url');
        $image = data_get($product->metadata, 'image');
        $description = trim(strip_tags((string) $product->description));
        $description = Str::limit(preg_replace('/\s+/u', ' ', $description) ?? '', 600, '…');
        $caption = trim($product->name."\n\n".($description !== '' ? $description."\n\n" : '')."შესაძენად გადადით საიტზე:\n{$url}");

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
            'caption' => $caption,
        ];
    }

    private function publicHttpUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
