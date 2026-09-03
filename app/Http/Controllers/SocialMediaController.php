<?php

namespace App\Http\Controllers;

use App\Models\SocialMediaSchedule;
use App\Services\ProductPagePrimaryImageResolver;
use App\Services\SocialMediaImageDesigner;
use App\Services\SocialMediaScheduler;
use App\Services\SocialMediaTemplateService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SocialMediaController extends Controller
{
    public function index(TenantContext $tenant, SocialMediaTemplateService $templateService, SocialMediaImageDesigner $imageDesigner, ProductPagePrimaryImageResolver $primaryImages)
    {
        $agent = $tenant->agent();
        $connections = $agent->channelConnections()
            ->whereIn('provider', ['facebook', 'instagram'])
            ->get()
            ->keyBy('provider');
        $products = $agent->customerProducts()->where('is_active', true)->get();
        $categories = $products
            ->flatMap(fn ($product) => [
                $product->category,
                ...((array) data_get($product->metadata, 'genres', [])),
                ...((array) data_get($product->metadata, 'taxonomy', [])),
            ])->filter(fn ($value): bool => is_scalar($value))->map(fn ($value) => trim((string) $value))->filter()->unique(fn ($value) => Str::lower($value))->sort()->values();
        $languages = $agent->knowledgeSources()->where('source_scope', 'language')->where('status', 'ready')
            ->pluck('taxonomy_label')->filter()->unique(fn ($value) => Str::lower(trim((string) $value)))->sort()->values();
        $schedules = $agent->socialMediaSchedules()
            ->withCount([
                'posts',
                'posts as published_posts_count' => fn ($query) => $query->where('status', 'published'),
                'posts as failed_posts_count' => fn ($query) => $query->where('status', 'failed'),
            ])->latest()->get();
        $upcoming = $agent->socialMediaPosts()->with('schedule:id,timezone')->whereIn('status', ['scheduled', 'queued'])
            ->orderBy('scheduled_for')->limit(12)->get();
        $canManage = in_array($tenant->role(), ['owner', 'admin'], true);
        $templates = $templateService->configurations($agent);
        $sampleCandidates = $products->sortByDesc(fn ($product): int => $product->socialDescription() !== null ? 1 : 0);
        $sample = $sampleCandidates->first(function ($product): bool {
            $url = data_get($product->metadata, 'product_url');

            return $product->stock > 0 && $this->publicHttpUrl($url)
                && ($product->catalogDesignImageUrl() !== null || $product->publicImageUrl() !== null);
        }) ?? $sampleCandidates->first(fn ($product): bool => $product->stock > 0 && $this->publicHttpUrl(data_get($product->metadata, 'product_url')));
        $localizedPreview = $sample ? collect((array) data_get($sample->metadata, 'localized', []))
            ->first(fn ($variant): bool => filled(trim((string) data_get($variant, 'description')))
                && $this->publicHttpUrl(data_get($variant, 'product_url'))) : null;
        $primaryImage = $sample ? $primaryImages->resolve($sample) : null;
        $catalogImage = $sample ? ($sample->catalogDesignImageUrl() ?: $sample->publicImageUrl() ?: data_get($localizedPreview, 'image')) : null;
        $previewProduct = $sample ? [
            'title' => (string) data_get($localizedPreview, 'name', $sample->name),
            'description' => Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $sample->socialDescription())) ?? ''), 400, '…'),
            'price' => number_format((float) $sample->price, 2, '.', ' ').' '.strtoupper((string) data_get($sample->metadata, 'currency', data_get($agent->organization?->settings, 'currency', 'GEL'))),
            'category' => (string) data_get($localizedPreview, 'category', $sample->category),
            'url' => (string) data_get($localizedPreview, 'product_url', data_get($sample->metadata, 'product_url')),
            // Preserve the catalog's curated/branded image. The localized
            // crawl image is only a fallback when the catalog has none.
            'image' => $catalogImage,
            'raw_image' => data_get($localizedPreview, 'image', $sample->publicImageUrl()),
            'business_name' => (string) ($agent->business_name ?: $agent->name),
        ] : [
            'title' => 'Product title',
            'description' => 'Verified public product description.',
            'price' => '0.00 '.strtoupper((string) data_get($agent->organization?->settings, 'currency', 'GEL')),
            'category' => 'Category',
            'url' => 'https://business.example/product',
            'image' => null,
            'raw_image' => null,
            'business_name' => (string) ($agent->business_name ?: $agent->name),
        ];

        $previewSource = $previewProduct['image'];
        $previewProduct['style_images'] = collect(SocialMediaTemplateService::IMAGE_STYLES)
            ->mapWithKeys(fn (string $style): array => [
                $style => $style === 'original' && $primaryImage
                    ? $primaryImage
                    : ($previewSource ? $imageDesigner->render(
                        $style === 'raw' ? ($previewProduct['raw_image'] ?: $previewSource) : $previewSource,
                        $style,
                    ) : null),
            ])->all();

        return view('social-media', compact('agent', 'connections', 'categories', 'languages', 'schedules', 'upcoming', 'canManage', 'templates', 'previewProduct'));
    }

    public function store(Request $request, TenantContext $tenant, SocialMediaScheduler $scheduler)
    {
        $tenant->authorize(['owner', 'admin']);
        $request->merge(['timing_mode' => $request->input('timing_mode', 'auto')]);
        $request->merge(['copy_mode' => $request->input('copy_mode', 'original')]);
        $data = $request->validate([
            'starts_on' => ['required', 'date', 'after_or_equal:today'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on', 'before_or_equal:'.now()->addYear()->toDateString()],
            'posts_per_day' => ['required', 'integer', 'min:1', 'max:24'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', 'max:255'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'max:150'],
            'providers' => ['required', 'array', 'min:1'],
            'providers.*' => [Rule::in(['facebook', 'instagram'])],
            'timezone' => ['required', 'timezone'],
            'timing_mode' => ['required', Rule::in(['auto', 'custom'])],
            'posting_times' => ['nullable', 'array'],
            'posting_times.*' => ['required', 'date_format:H:i'],
            'copy_mode' => ['required', Rule::in(['original', 'ai'])],
            'ai_tone' => ['nullable', Rule::requiredIf($request->input('copy_mode') === 'ai'), Rule::in(['simple', 'creative', 'academic'])],
        ]);

        $configuredLanguages = $tenant->agent()->knowledgeSources()->where('source_scope', 'language')
            ->where('status', 'ready')->pluck('taxonomy_label')->filter();
        if (collect($data['languages'] ?? [])->diff($configuredLanguages)->isNotEmpty()) {
            throw ValidationException::withMessages(['languages' => 'Choose only synchronized website languages.']);
        }

        if ($data['timing_mode'] === 'custom') {
            $times = collect($data['posting_times'] ?? [])->map(fn ($time): string => (string) $time);
            if ($times->count() !== (int) $data['posts_per_day']) {
                throw ValidationException::withMessages(['posting_times' => 'Choose one posting time for every daily post.']);
            }
            if ($times->unique()->count() !== $times->count()) {
                throw ValidationException::withMessages(['posting_times' => 'Each daily posting time must be unique.']);
            }
            if ($data['starts_on'] === now($data['timezone'])->toDateString()) {
                $minimum = now($data['timezone'])->addMinutes(2)->format('H:i');
                if ($times->contains(fn (string $time): bool => $time < $minimum)) {
                    throw ValidationException::withMessages(['posting_times' => 'For a schedule starting today, every posting time must still be in the future.']);
                }
            }
            $data['posting_times'] = $times->sort()->values()->all();
        } else {
            $data['posting_times'] = null;
        }

        $agent = $tenant->agent();
        $active = $agent->channelConnections()->whereIn('provider', $data['providers'])->where('status', 'active')->pluck('provider');
        $missing = collect($data['providers'])->diff($active);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['providers' => 'Connect the selected Facebook/Instagram accounts before creating a schedule.']);
        }

        if ($data['copy_mode'] === 'ai' && ((int) $data['posts_per_day'] * count($data['providers'])) > 3) {
            throw ValidationException::withMessages([
                'posts_per_day' => 'AI Copywriter is limited to 3 generated posts per business per day across all selected channels.',
            ]);
        }

        $scheduler->create($agent, $data);

        return redirect()->route('social-media.index')->with('social_success', 'Social media schedule created. Posts are ready in the publishing queue.');
    }

    public function pause(SocialMediaSchedule $schedule, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($schedule->agent_id === $tenant->agent()->id, 404);
        $active = $schedule->status !== 'active';
        if ($active) {
            $schedule->posts()->where('status', 'scheduled')->where('scheduled_for', '<', now())->update(['status' => 'skipped']);
        }
        $schedule->update(['status' => $active ? 'active' : 'paused', 'paused_at' => $active ? null : now()]);

        return back()->with('social_success', $active ? 'Schedule resumed.' : 'Schedule paused.');
    }

    public function destroy(SocialMediaSchedule $schedule, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($schedule->agent_id === $tenant->agent()->id, 404);
        $schedule->delete();

        return back()->with('social_success', 'Schedule and its post history were removed.');
    }

    private function publicHttpUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
