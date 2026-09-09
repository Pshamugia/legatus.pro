<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\SocialMediaSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocialMediaScheduler
{
    private const AI_DAILY_PRODUCT_LIMIT = 7;

    public function __construct(
        private readonly SocialMediaTemplateService $templates,
        private readonly SocialMediaTemplateRenderer $renderer,
        private readonly SocialMediaImageDesigner $images,
        private readonly ProductPagePrimaryImageResolver $primaryImages,
        private readonly SocialMediaPublicationHistory $publicationHistory,
        private readonly PublicProductAvailabilityVerifier $availability,
    ) {}

    public function create(Agent $agent, array $data): SocialMediaSchedule
    {
        $this->publicationHistory->backfill($agent);
        $allProducts = $this->eligibleProductVariants(
            $agent,
            $data['categories'] ?? [],
            $data['languages'] ?? [],
            $data['providers'],
        )->unique(fn (array $variant): int => (int) $variant['product']->id)->values();
        $previouslyPosted = $agent->socialMediaPosts()
            ->whereIn('provider', $data['providers'])
            ->whereIn('status', ['scheduled', 'preparing', 'queued'])
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true]);
        $this->startNewCycleWhenExhausted($agent, $allProducts, $data['providers']);
        $products = $allProducts
            ->reject(fn (array $variant): bool => isset($previouslyPosted[(int) $variant['product']->id]))
            ->reject(fn (array $variant): bool => $this->publicationHistory->wasUsedOnAny(
                $agent,
                $variant['product'],
                $data['providers'],
            ))
            ->values();
        $starts = CarbonImmutable::parse($data['starts_on'], $data['timezone'])->startOfDay();
        $ends = CarbonImmutable::parse($data['ends_on'], $data['timezone'])->startOfDay();
        $templateSnapshots = $this->templates->snapshots($agent, $data['providers']);

        return DB::transaction(function () use ($agent, $data, $products, $templateSnapshots, $starts, $ends): SocialMediaSchedule {
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

            $productIndex = 0;
            for ($day = $starts; $day->lte($ends); $day = $day->addDay()) {
                $remainingAiProducts = ($data['copy_mode'] ?? 'original') === 'ai'
                    ? max(0, self::AI_DAILY_PRODUCT_LIMIT - $this->scheduledAiProductSlotsForDay($agent, $day))
                    : 0;
                $slots = isset($data['posting_times'])
                    ? $this->customDailyTimes($day, $data['posting_times'])
                    : $this->dailyTimes($day, (int) $data['posts_per_day']);
                foreach ($slots as $slot) {
                    $variant = $products->get($productIndex);
                    if ($variant) {
                        $productIndex++;
                    }
                    $postCopyMode = $remainingAiProducts > 0 ? 'ai' : 'original';
                    if ($postCopyMode === 'ai') {
                        $remainingAiProducts--;
                    }
                    foreach ($data['providers'] as $provider) {
                        $schedule->posts()->create($variant
                            ? $this->postAttributes(
                                $agent,
                                $variant['product'],
                                $provider,
                                $slot,
                                $templateSnapshots[$provider],
                                $variant['language'],
                                $postCopyMode,
                            )
                            : $this->pendingProductAttributes($agent, $provider, $slot, $postCopyMode));
                    }
                }
            }

            return $schedule->loadCount('posts');
        });
    }

    public function updateTiming(SocialMediaSchedule $schedule, array $data): SocialMediaSchedule
    {
        $agent = $schedule->agent()->firstOrFail();
        $this->publicationHistory->backfill($agent);
        $allProducts = $this->eligibleProductVariants(
            $agent,
            $schedule->categories ?? [],
            $schedule->languages ?? [],
            $schedule->providers,
        )->unique(fn (array $variant): int => (int) $variant['product']->id)->values();
        $previouslyPosted = $agent->socialMediaPosts()
            ->whereIn('provider', $schedule->providers)
            ->whereIn('status', ['scheduled', 'preparing', 'queued'])
            ->where(function ($query) use ($schedule): void {
                $query->where('social_media_schedule_id', '!=', $schedule->id)
                    ->orWhereIn('status', ['queued', 'published']);
            })
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true]);
        $this->startNewCycleWhenExhausted($agent, $allProducts, $schedule->providers);
        $products = $allProducts
            ->reject(fn (array $variant): bool => isset($previouslyPosted[(int) $variant['product']->id]))
            ->reject(fn (array $variant): bool => $this->publicationHistory->wasUsedOnAny(
                $agent,
                $variant['product'],
                $schedule->providers,
            ))
            ->values();
        $starts = CarbonImmutable::parse($data['starts_on'], $schedule->timezone)->startOfDay();
        $ends = CarbonImmutable::parse($data['ends_on'], $schedule->timezone)->startOfDay();

        return DB::transaction(function () use ($schedule, $agent, $data, $products, $starts, $ends): SocialMediaSchedule {
            $lockedSchedule = SocialMediaSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($lockedSchedule->copy_mode === 'ai') {
                Agent::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();
            }

            // A queued post may already be owned by a worker, while published,
            // failed, and skipped rows are immutable history. Only genuinely
            // pending rows are safe to rebuild.
            $lockedSchedule->posts()->where('status', 'scheduled')->delete();
            $immutableSlots = $lockedSchedule->posts()->get(['scheduled_for'])
                ->mapWithKeys(fn ($post): array => [$post->scheduled_for->utc()->format('Y-m-d H:i:s') => true]);

            $lockedSchedule->update([
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'posting_times' => $data['posting_times'] ?? null,
            ]);

            $productIndex = 0;
            $nowUtc = CarbonImmutable::now('UTC');
            for ($day = $starts; $day->lte($ends); $day = $day->addDay()) {
                $remainingAiProducts = $lockedSchedule->copy_mode === 'ai'
                    ? max(0, self::AI_DAILY_PRODUCT_LIMIT - $this->scheduledAiProductSlotsForDay($agent, $day))
                    : 0;
                $slots = isset($data['posting_times'])
                    ? $this->customDailyTimes($day, $data['posting_times'])
                    : $this->dailyTimes($day, (int) $lockedSchedule->posts_per_day);
                foreach ($slots as $slot) {
                    if ($slot->lte($nowUtc) || isset($immutableSlots[$slot->format('Y-m-d H:i:s')])) {
                        continue;
                    }
                    $variant = $products->get($productIndex);
                    if ($variant) {
                        $productIndex++;
                    }
                    $copyMode = $remainingAiProducts > 0 ? 'ai' : 'original';
                    if ($copyMode === 'ai') {
                        $remainingAiProducts--;
                    }
                    foreach ($lockedSchedule->providers as $provider) {
                        $lockedSchedule->posts()->create($variant
                            ? $this->postAttributes(
                                $agent,
                                $variant['product'],
                                $provider,
                                $slot,
                                $lockedSchedule->template_snapshots[$provider],
                                $variant['language'],
                                $copyMode,
                            )
                            : $this->pendingProductAttributes($agent, $provider, $slot, $copyMode));
                    }
                }
            }

            return $lockedSchedule->refresh()->loadCount('posts');
        });
    }

    /**
     * Revalidate one multi-channel product slot immediately before it is
     * claimed by the queue. A stale product is replaced for every channel in
     * the slot, so one rejected product never silently consumes a posting
     * time or makes the selected channels diverge.
     *
     * @return list<int> IDs that are safe to queue
     */
    public function prepareDueSlot(SocialMediaSchedule $schedule, CarbonInterface $scheduledFor): array
    {
        $this->publicationHistory->backfill($schedule->agent);
        $slotPosts = $schedule->posts()
            ->where('scheduled_for', $scheduledFor)
            ->lockForUpdate()
            ->get();
        $pending = $slotPosts->whereIn('status', ['scheduled', 'preparing'])->values();
        if ($pending->isEmpty()) {
            return [];
        }

        $slotPostIds = $slotPosts->pluck('id');
        $productIds = $pending->pluck('product_id')->filter()->unique();
        $product = $productIds->count() === 1
            ? $schedule->agent->customerProducts()->find($productIds->first())
            : null;
        $eligibleVariants = $this->eligibleProductVariants(
            $schedule->agent,
            $schedule->categories ?? [],
            $schedule->languages ?? [],
            $schedule->providers ?? $pending->pluck('provider')->all(),
        );
        $productMatchesSchedule = $product && $eligibleVariants->contains(function (array $variant) use ($product, $pending): bool {
            if ((int) $variant['product']->id !== (int) $product->id) {
                return false;
            }

            return $pending->every(function ($post) use ($variant): bool {
                $postLanguage = filled($post->language) ? Str::lower(trim((string) $post->language)) : null;
                $variantLanguage = filled($variant['language']) ? Str::lower(trim((string) $variant['language'])) : null;

                return $postLanguage === $variantLanguage;
            });
        });
        $alreadyPublished = $product && (
            $schedule->agent->socialMediaPosts()
                ->where('product_id', $product->id)
                ->where('status', 'published')
                ->whereNotIn('id', $slotPostIds)
                ->exists()
            || $this->publicationHistory->wasUsedOnAny(
                $schedule->agent,
                $product,
                $pending->pluck('provider')->all(),
            )
        );

        if ($product && $productMatchesSchedule && ! $alreadyPublished && $pending->every(
            fn ($post): bool => $this->productIsPublishableForPost($product, $post),
        ) && $this->liveAvailabilityAllows($schedule->agent, $product)) {
            return $pending->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        // Published and already-claimed products are globally unavailable for
        // replacement. Future scheduled products may be pulled forward; their
        // own slot will be revalidated in exactly the same way when it is due.
        $reservedProductIds = $schedule->agent->socialMediaPosts()
            ->whereNotIn('id', $slotPostIds)
            ->where(function ($query) use ($scheduledFor): void {
                $query->whereIn('status', ['preparing', 'queued', 'published'])
                    ->orWhere(function ($due) use ($scheduledFor): void {
                        $due->where('status', 'scheduled')
                            ->where('scheduled_for', '<=', $scheduledFor);
                    });
            })
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true]);

        $wantedLanguage = $pending->pluck('language')->unique()->count() === 1
            ? $pending->first()->language
            : null;
        $allReplacements = $eligibleVariants
            ->when($wantedLanguage !== null, fn (Collection $variants): Collection => $variants->where('language', $wantedLanguage))
            ->reject(fn (array $variant): bool => isset($reservedProductIds[(int) $variant['product']->id]))
            ->unique(fn (array $variant): int => (int) $variant['product']->id)
            ->values();
        $providers = $pending->pluck('provider')->all();
        $replacement = $allReplacements
            ->reject(fn (array $variant): bool => $this->publicationHistory->wasUsedOnAny(
                $schedule->agent,
                $variant['product'],
                $providers,
            ))
            ->first(fn (array $variant): bool => $this->liveAvailabilityAllows(
                $schedule->agent,
                $variant['product'],
            ));

        if (! $replacement && $this->startNewCycleWhenExhausted($schedule->agent, $allReplacements, $providers)) {
            $replacement = $allReplacements->first(fn (array $variant): bool => $this->liveAvailabilityAllows(
                $schedule->agent,
                $variant['product'],
            ));
        }

        if (! $replacement) {
            $pending->each->update([
                'status' => 'skipped',
                'failure_reason' => 'No unused publishable product was available to replace this slot.',
            ]);

            return [];
        }

        foreach ($pending as $post) {
            $template = data_get($schedule->template_snapshots, $post->provider);
            if (! is_array($template)) {
                $pending->each->update([
                    'status' => 'skipped',
                    'failure_reason' => 'This slot could not be replaced because its template snapshot is unavailable.',
                ]);

                return [];
            }

            $attributes = $this->postAttributes(
                $schedule->agent,
                $replacement['product'],
                $post->provider,
                CarbonImmutable::instance($scheduledFor),
                $template,
                $replacement['language'],
                $post->copy_mode ?: $schedule->copy_mode ?: 'original',
            );
            unset($attributes['agent_id'], $attributes['provider'], $attributes['status'], $attributes['scheduled_for']);
            $post->update($attributes + [
                'attempts' => 0,
                'provider_post_id' => null,
                'published_at' => null,
                'failure_reason' => null,
                'ai_generation_attempted_at' => null,
                'ai_generated_at' => null,
                'ai_model' => null,
            ]);
        }

        return $pending->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function eligibleProducts(Agent $agent, array $categories = [], array $providers = []): Collection
    {
        $wanted = collect($categories)->map(fn ($value) => Str::lower(trim((string) $value)))->filter()->unique();
        $imageRequired = collect($providers)->intersect(['instagram', 'linkedin'])->isNotEmpty();

        return $agent->customerProducts()
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->get()
            ->filter(function ($product) use ($wanted, $imageRequired): bool {
                $url = data_get($product->metadata, 'product_url');
                if (! $this->publicHttpUrl($url) || ($imageRequired && $product->publicImageUrl() === null)) {
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
            // No language filter means the primary catalog record. A product
            // description improves the caption but is never a prerequisite
            // for a verified title, price, image and product-link post.
            return $products->map(fn ($product): array => [
                'product' => $product,
                'language' => null,
            ])->values();
        }

        // The first configured website language is the storefront's primary
        // language (the controller uses the same rule for the live preview).
        // Its copy lives on the base catalog record, so it must not require a
        // second metadata.localized entry produced by an optional language
        // crawl. Secondary languages still require their verified localized
        // record and therefore cannot silently fall back to the wrong copy.
        $languageSources = $agent->knowledgeSources()
            ->where('source_scope', 'language')
            ->oldest('id')
            ->get(['id', 'taxonomy_label', 'status']);
        $primaryLanguage = trim((string) optional(
            $languageSources->first(fn ($source): bool => $source->status === 'ready' && filled($source->taxonomy_label)),
        )->taxonomy_label);
        $primaryLanguageKey = Str::lower($primaryLanguage);
        $sourceLanguageKeys = $languageSources
            ->filter(fn ($source): bool => filled($source->taxonomy_label))
            ->mapWithKeys(fn ($source): array => [(int) $source->id => Str::lower(trim((string) $source->taxonomy_label))]);

        return $products->flatMap(function ($product) use ($wanted, $primaryLanguageKey, $sourceLanguageKeys): array {
            $localized = (array) data_get($product->metadata, 'localized', []);
            $localizedKeys = collect(array_keys($localized))
                ->mapWithKeys(fn ($language): array => [Str::lower(trim((string) $language)) => (string) $language]);
            $sourceLanguageKey = $sourceLanguageKeys->get((int) data_get($product->metadata, 'source_id'));

            return $wanted->filter(function (string $language) use ($localizedKeys, $primaryLanguageKey, $sourceLanguageKey): bool {
                $languageKey = Str::lower($language);

                return $localizedKeys->has($languageKey)
                    || ($primaryLanguageKey !== ''
                        && $languageKey === $primaryLanguageKey
                        && ($sourceLanguageKey === null || $sourceLanguageKey === $primaryLanguageKey));
            })
                ->map(function (string $language) use ($product, $localizedKeys): array {
                    $verifiedLanguage = $localizedKeys->get(Str::lower($language), $language);

                    return ['product' => $product, 'language' => $verifiedLanguage];
                })
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

    private function scheduledAiProductSlotsForDay(Agent $agent, CarbonImmutable $day): int
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
            ->get(['product_id', 'scheduled_for'])
            ->unique(fn ($post): string => (string) $post->product_id.'|'.$post->getRawOriginal('scheduled_for'))
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
        $primaryImage = $style !== 'original' ? $this->primaryImages->resolve($product, $language) : null;
        $catalogImage = $product->catalogDesignImageUrl() ?: $product->publicImageUrl();
        $plainImage = $primaryImage ?: ($localized['image'] ?? $product->publicImageUrl() ?: $catalogImage);
        $image = match ($style) {
            'original' => $catalogImage ? $this->images->render($catalogImage, 'original') : null,
            'storefront', 'raw' => $plainImage,
            default => $plainImage ? $this->images->render($plainImage, $style) : null,
        };
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

    private function pendingProductAttributes(Agent $agent, string $provider, CarbonImmutable $slot, string $copyMode): array
    {
        return [
            'agent_id' => $agent->id,
            'product_id' => null,
            'provider' => $provider,
            'language' => null,
            'status' => 'scheduled',
            'scheduled_for' => $slot,
            'title' => 'Next eligible product',
            'description' => null,
            'product_url' => '',
            'image_url' => null,
            'caption' => '',
            'copy_mode' => $copyMode,
        ];
    }

    /**
     * Start another rotation only after every currently publishable product
     * has been used during the active cycle. In-flight claims never trigger a
     * reset, so concurrent workers cannot publish the same product twice.
     */
    private function startNewCycleWhenExhausted(Agent $agent, Collection $products, array $providers): bool
    {
        if ($products->isEmpty()
            || ! $this->catalogCoverageIsComplete($agent)
            || $this->publicationHistory->hasClaims($agent, $providers)) {
            return false;
        }

        $exhausted = $products->every(fn (array $variant): bool => $this->publicationHistory->wasUsedOnAny(
            $agent,
            $variant['product'],
            $providers,
        ));
        if (! $exhausted) {
            return false;
        }

        $this->publicationHistory->startNewCycle($agent, $providers);

        return true;
    }

    private function catalogCoverageIsComplete(Agent $agent): bool
    {
        $catalogSources = $agent->knowledgeSources()
            ->where('type', 'url')
            ->where('source_scope', 'catalog')
            ->get(['status', 'progress', 'error']);

        return $catalogSources->isEmpty() || $catalogSources->every(
            fn ($source): bool => $source->status === 'ready'
                && (int) $source->progress === 100
                && blank($source->error),
        );
    }

    private function liveAvailabilityAllows(Agent $agent, $product): bool
    {
        $verified = $this->availability->verify($product);

        return $verified === true || ($verified === null && $this->catalogCoverageIsComplete($agent));
    }

    private function publicHttpUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function productIsPublishableForPost($product, $post): bool
    {
        $localized = $post->language
            ? (array) data_get($product->metadata, 'localized.'.$post->language, [])
            : [];
        $url = (string) ($localized['product_url'] ?? data_get($product->metadata, 'product_url'));
        $image = $product->catalogDesignImageUrl() ?: $product->publicImageUrl() ?: ($localized['image'] ?? null);

        return $product->agent_id === $post->agent_id
            && $product->is_active
            && $product->stock > 0
            && $this->publicHttpUrl($url)
            && (! in_array($post->provider, ['instagram', 'linkedin'], true) || $this->publicHttpUrl($image));
    }
}
