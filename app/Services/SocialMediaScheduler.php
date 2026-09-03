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
    private const AI_DAILY_LIMIT = 7;

    public function __construct(
        private readonly SocialMediaTemplateService $templates,
        private readonly SocialMediaTemplateRenderer $renderer,
        private readonly SocialMediaImageDesigner $images,
        private readonly ProductPagePrimaryImageResolver $primaryImages,
    ) {}

    public function create(Agent $agent, array $data): SocialMediaSchedule
    {
        $productsByProvider = collect($data['providers'])->mapWithKeys(fn (string $provider): array => [
            $provider => $this->eligibleProductVariants($agent, $data['categories'] ?? [], $data['languages'] ?? [], [$provider]),
        ]);
        foreach ($productsByProvider as $provider => $products) {
            if ($products->isEmpty()) {
                $requirement = $provider === 'instagram' ? 'descriptions, public links and images' : 'descriptions and public links';
                throw ValidationException::withMessages([
                    'categories' => 'No products with usable '.$requirement.' were found for '.ucfirst($provider).' in this selection.',
                ]);
            }
        }

        $templateSnapshots = $this->templates->snapshots($agent, $data['providers']);

        return DB::transaction(function () use ($agent, $data, $productsByProvider, $templateSnapshots): SocialMediaSchedule {
            if (($data['copy_mode'] ?? 'original') === 'ai') {
                Agent::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();
            }

            $schedule = $agent->socialMediaSchedules()->create([
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'posts_per_day' => $data['posts_per_day'],
                'categories' => array_values($data['categories'] ?? []),
                'languages' => array_values($data['languages'] ?? []),
                'providers' => array_values($data['providers']),
                'timezone' => $data['timezone'],
                'posting_times' => $data['posting_times'] ?? null,
                'template_snapshots' => $templateSnapshots,
                'copy_mode' => $data['copy_mode'] ?? 'original',
                'ai_tone' => ($data['copy_mode'] ?? 'original') === 'ai' ? $data['ai_tone'] : null,
                'status' => 'active',
            ]);

            $starts = CarbonImmutable::parse($data['starts_on'], $data['timezone'])->startOfDay();
            $ends = CarbonImmutable::parse($data['ends_on'], $data['timezone'])->startOfDay();
            $productIndexes = collect($data['providers'])
                ->mapWithKeys(fn (string $provider): array => [$provider => 0])
                ->all();
            for ($day = $starts; $day->lte($ends); $day = $day->addDay()) {
                $remainingAiPosts = ($data['copy_mode'] ?? 'original') === 'ai'
                    ? max(0, self::AI_DAILY_LIMIT - $this->scheduledAiPostsForDay($agent, $day))
                    : 0;
                $slots = isset($data['posting_times'])
                    ? $this->customDailyTimes($day, $data['posting_times'])
                    : $this->dailyTimes($day, (int) $data['posts_per_day']);
                foreach ($slots as $slot) {
                    foreach ($data['providers'] as $provider) {
                        $products = $productsByProvider[$provider];
                        $variant = $products[$productIndexes[$provider] % $products->count()];
                        $productIndexes[$provider]++;
                        $postCopyMode = $remainingAiPosts > 0 ? 'ai' : 'original';
                        if ($postCopyMode === 'ai') {
                            $remainingAiPosts--;
                        }
                        $schedule->posts()->create($this->postAttributes(
                            $agent,
                            $variant['product'],
                            $provider,
                            $slot,
                            $templateSnapshots[$provider],
                            $variant['language'],
                            $postCopyMode,
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

    private function eligibleProductVariants(Agent $agent, array $categories, array $languages, array $providers): Collection
    {
        $products = $this->eligibleProducts($agent, $categories, $providers);
        $wanted = collect($languages)->map(fn ($value): string => trim((string) $value))->filter();

        if ($wanted->isEmpty()) {
            $wanted = $agent->knowledgeSources()->where('source_scope', 'language')->where('status', 'ready')
                ->pluck('taxonomy_label')->filter()->values();
        }
        if ($wanted->isEmpty()) {
            return $products->filter(fn ($product): bool => filled(trim((string) $product->description)))
                ->map(fn ($product): array => ['product' => $product, 'language' => null])->values();
        }

        return $products->flatMap(function ($product) use ($wanted): array {
            $localized = (array) data_get($product->metadata, 'localized', []);

            return $wanted->filter(fn (string $language): bool => isset($localized[$language])
                    && filled(trim((string) data_get($localized[$language], 'description'))))
                ->map(fn (string $language): array => ['product' => $product, 'language' => $language])
                ->values()->all();
        })->shuffle()->values();
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

    private function scheduledAiPostsForDay(Agent $agent, CarbonImmutable $day): int
    {
        return $agent->socialMediaPosts()
            ->where('scheduled_for', '>=', $day->utc())
            ->where('scheduled_for', '<', $day->addDay()->utc())
            ->whereHas('schedule', fn ($schedule) => $schedule->where('status', 'active'))
            ->where(function ($query): void {
                $query->where('copy_mode', 'ai')
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('copy_mode')
                            ->whereHas('schedule', fn ($schedule) => $schedule->where('copy_mode', 'ai'));
                    });
            })
            ->count();
    }

    /** @param list<string> $times */
    private function customDailyTimes(CarbonImmutable $day, array $times): array
    {
        return collect($times)->map(function (string $time) use ($day): CarbonImmutable {
            [$hour, $minute] = array_map('intval', explode(':', $time));

            return $day->setTime($hour, $minute)->utc();
        })->all();
    }

    private function postAttributes(Agent $agent, $product, string $provider, CarbonImmutable $slot, array $template, ?string $language = null, string $copyMode = 'original'): array
    {
        $localizedMap = (array) data_get($product->metadata, 'localized', []);
        $localized = $language ? (array) ($localizedMap[$language] ?? []) : [];
        $url = (string) ($localized['product_url'] ?? data_get($product->metadata, 'product_url'));
        $style = (string) ($template['image_style'] ?? 'original');
        $primaryImage = $style === 'storefront' ? $this->primaryImages->resolve($product, $language) : null;
        $catalogImage = $product->catalogDesignImageUrl() ?: $product->publicImageUrl();
        $sourceImage = $style === 'raw'
            ? ($localized['image'] ?? $catalogImage)
            : ($style === 'storefront' && $primaryImage
                ? $primaryImage
                : ($catalogImage ?: ($localized['image'] ?? null)));
        $image = $sourceImage
            ? ($style === 'storefront' && $primaryImage
                ? $primaryImage
                : $this->images->render($sourceImage, $style === 'storefront' ? 'original' : $style))
            : null;
        $title = (string) ($localized['name'] ?? $product->name);
        $descriptionValue = $product->socialDescription($language);
        $description = trim(strip_tags((string) $descriptionValue));
        $description = Str::limit(preg_replace('/\s+/u', ' ', $description) ?? '', 700, '…');

        $renderProduct = clone $product;
        $renderProduct->name = $title;
        $renderProduct->category = $localized['category'] ?? $product->category;
        $renderProduct->description = $description;
        $renderProduct->metadata = array_replace($product->metadata ?? [], ['product_url' => $url]);
        $renderProduct->image = $image;

        return [
            'agent_id' => $agent->id,
            'product_id' => $product->id,
            'provider' => $provider,
            'language' => $language,
            'status' => 'scheduled',
            'scheduled_for' => $slot,
            'title' => $title,
            'description' => $description ?: null,
            'product_url' => $url,
            'image_url' => $this->publicHttpUrl($image) ? $image : null,
            'caption' => $this->renderer->render($provider, $template, $agent, $renderProduct),
            'copy_mode' => $copyMode,
        ];
    }

    private function publicHttpUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
