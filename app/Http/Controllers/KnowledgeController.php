<?php

namespace App\Http\Controllers;

use App\Jobs\CrawlPublicWebsite;
use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KnowledgeController extends Controller
{
    public function index(TenantContext $tenant, KnowledgeIngestionService $ingestion)
    {
        $agent = $tenant->agent();
        $sources = $agent->knowledgeSources()
            // Never count or scan the large knowledge_chunks table while
            // rendering this control screen. Progress is stored on the source.
            ->latest()
            ->get();
        $catalogSource = $sources->firstWhere('source_scope', 'catalog');
        $sitemapSource = $sources->firstWhere('source_scope', 'sitemap');
        $deliverySource = $sources->firstWhere('source_scope', 'delivery');
        $termsSource = $sources->firstWhere('source_scope', 'terms');
        $categorySources = $sources
            ->map(function (KnowledgeSource $source) use ($ingestion): ?array {
                $taxonomy = $ingestion->taxonomyForSource($source);
                if ($source->source_scope !== 'category' && $taxonomy === []) {
                    return null;
                }

                return [
                    'id' => $source->id,
                    'name' => $source->taxonomy_label ?: implode(', ', $taxonomy),
                    'url' => $source->url,
                    'status' => $source->status,
                    'refreshable' => $source->isRefreshable(),
                ];
            })
            ->filter()
            ->values();
        $languageSources = $sources->where('source_scope', 'language')->map(fn (KnowledgeSource $source): array => [
            'id' => $source->id,
            'name' => $source->taxonomy_label ?: $source->name,
            'url' => $source->url,
            'status' => $source->status,
            'refreshable' => $source->isRefreshable(),
        ])->values();

        $deliveryText = $deliverySource?->chunks()->where('kind', 'policy')->value('content');

        return view('knowledge', compact('agent', 'sources', 'catalogSource', 'sitemapSource', 'categorySources', 'languageSources', 'deliverySource', 'deliveryText', 'termsSource'));
    }

    public function status(TenantContext $tenant)
    {
        $sources = $tenant->agent()->knowledgeSources()
            ->select(['id', 'name', 'source_scope', 'status', 'progress', 'items_found', 'error', 'last_synced_at'])
            ->latest()
            ->get()
            ->map(fn (KnowledgeSource $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'scope' => $source->source_scope,
                'status' => $source->status,
                'progress' => (int) $source->progress,
                'items_found' => (int) $source->items_found,
                'error' => $source->error,
                'last_synced_at' => $source->last_synced_at?->toIso8601String(),
            ]);

        return response()->json([
            'sources' => $sources,
            'processing' => $sources->where('status', 'processing')->count(),
        ]);
    }

    public function store(Request $r, KnowledgeIngestionService $ingestion, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        if ($r->input('mode') === 'website_structure') {
            return $this->storeWebsiteStructure($r, $tenant, $ingestion);
        }
        if ($r->input('mode') === 'business_policies') {
            return $this->storeBusinessPolicies($r, $tenant);
        }

        $data = $r->validate([
            'type' => 'required|in:url,csv,pdf',
            'url' => 'nullable|required_if:type,url|url|max:2000',
            'file' => [
                'nullable',
                'required_unless:type,url',
                'file',
                'max:10240',
                Rule::prohibitedIf(fn (): bool => $r->input('type') === 'url'),
                Rule::when($r->input('type') === 'pdf', ['mimes:pdf'], ['mimes:csv,txt']),
            ],
            'name' => 'nullable|string|max:150',
        ]);
        $agent = $tenant->agent();
        $file = $r->file('file');
        $name = $data['name'] ?? ($data['type'] === 'url' ? parse_url($data['url'], PHP_URL_HOST) : $file->getClientOriginalName());
        $source = $agent->knowledgeSources()->create(['type' => $data['type'], 'name' => $name, 'url' => $data['url'] ?? null, 'file_path' => $file ? $ingestion->storeFile($file, $data['type']) : null]);
        if ($source->type === 'url') {
            $source->update(['status' => 'processing', 'progress' => 1, 'error' => null]);
            CrawlPublicWebsite::dispatch($source->id);

            return back()->with('success', "{$source->name} is connected. Legatus is now learning the scoped website source in the background.");
        }
        try {
            $ingestion->ingest($source);

            return back()->with('success', "{$source->name} successfully learned.");
        } catch (\Throwable) {
            return back()->with('error', $source->fresh()->error);
        }
    }

    private function storeBusinessPolicies(Request $request, TenantContext $tenant)
    {
        $data = $request->validate([
            'delivery_title' => 'required|string|max:150',
            'delivery_text' => 'required|string|max:10000',
            'terms_title' => 'required|string|max:150',
            'terms_url' => 'required|url|max:2000',
        ]);
        $agent = $tenant->agent();
        [$deliveryId, $termsId] = DB::transaction(function () use ($agent, $data): array {
            $delivery = $agent->knowledgeSources()->firstOrNew(['source_scope' => 'delivery']);
            $delivery->fill([
                'type' => 'text',
                'name' => trim($data['delivery_title']),
                'url' => null,
                'file_path' => null,
                'status' => 'ready',
                'progress' => 100,
                'items_found' => 1,
                'items_created' => $delivery->exists ? 0 : 1,
                'items_updated' => $delivery->exists ? 1 : 0,
                'error' => null,
                'last_synced_at' => now(),
            ]);
            $delivery->save();
            $delivery->chunks()->delete();
            $delivery->chunks()->create([
                'agent_id' => $agent->id,
                'kind' => 'policy',
                'title' => trim($data['delivery_title']),
                'content' => trim($data['delivery_text']),
                'content_hash' => hash('sha256', trim($data['delivery_text'])),
                'metadata' => ['source_scope' => 'delivery', 'manual' => true],
            ]);

            $terms = $agent->knowledgeSources()->firstOrNew(['source_scope' => 'terms']);
            $urlChanged = ! $terms->exists || $terms->url !== trim($data['terms_url']);
            $terms->fill([
                'type' => 'url',
                'name' => trim($data['terms_title']),
                'url' => trim($data['terms_url']),
                'file_path' => null,
                'status' => $urlChanged ? 'processing' : $terms->status,
                'progress' => $urlChanged ? 1 : $terms->progress,
                'error' => null,
            ]);
            $terms->save();

            return [$delivery->id, $terms->id];
        });

        CrawlPublicWebsite::dispatch($termsId);

        return back()->with('success', 'Delivery text was saved and Terms & Policies synchronization was queued.');
    }

    private function storeWebsiteStructure(Request $request, TenantContext $tenant, KnowledgeIngestionService $ingestion)
    {
        $data = $request->validate([
            'catalog_url' => 'required|url|max:2000',
            'search_url' => 'nullable|url|max:2000',
            'sitemap_url' => 'nullable|url|max:2000',
            'categories' => 'nullable|array',
            'categories.*.name' => 'required_with:categories.*.url|nullable|string|max:150',
            'categories.*.url' => 'required_with:categories.*.name|nullable|url|max:2000',
            'languages' => 'nullable|array',
            'languages.*.name' => 'required_with:languages.*.url|nullable|string|max:150',
            'languages.*.url' => 'required_with:languages.*.name|nullable|url|max:2000',
        ]);
        $categories = collect($data['categories'] ?? [])
            ->filter(fn (array $category): bool => filled($category['name'] ?? null) && filled($category['url'] ?? null))
            ->unique(fn (array $category): string => mb_strtolower(trim($category['name'])).'|'.trim($category['url']))
            ->values();
        $languages = collect($data['languages'] ?? [])
            ->filter(fn (array $language): bool => filled($language['name'] ?? null) && filled($language['url'] ?? null))
            ->unique(fn (array $language): string => mb_strtolower(trim($language['name'])).'|'.trim($language['url']))
            ->values();
        $agent = $tenant->agent();
        $sourceIds = DB::transaction(function () use ($agent, $data, $categories, $languages, $ingestion): array {
            $sources = [];
            $settings = $agent->settings ?? [];
            $settings['catalog_search_url'] = filled($data['search_url'] ?? null) ? trim($data['search_url']) : null;
            $settings['catalog_suggest_url'] = null;
            $agent->update(['settings' => $settings]);
            $catalog = $agent->knowledgeSources()->firstOrNew(['source_scope' => 'catalog']);
            $catalog->fill(
                ['type' => 'url', 'name' => 'Site catalog', 'url' => $data['catalog_url']],
            );
            if (! $catalog->exists) {
                $catalog->fill(['status' => 'pending', 'progress' => 0]);
            }
            $catalog->save();
            $sources[] = $catalog->id;

            foreach ($categories as $category) {
                $label = trim($category['name']);
                $source = $agent->knowledgeSources()
                    ->where('source_scope', 'category')
                    ->where('taxonomy_label', $label)
                    ->first();
                if (! $source) {
                    $source = $agent->knowledgeSources()
                        ->whereNull('source_scope')
                        ->get()
                        ->first(fn (KnowledgeSource $candidate): bool => collect($ingestion->taxonomyForSource($candidate))
                            ->contains(fn (string $value): bool => mb_strtolower($value) === mb_strtolower($label)));
                }
                $source ??= new KnowledgeSource(['agent_id' => $agent->id]);
                $source->fill([
                    'source_scope' => 'category', 'taxonomy_label' => $label,
                    'type' => 'url', 'name' => "Category: {$label}", 'url' => trim($category['url']),
                ]);
                if (! $source->exists) {
                    $source->fill(['status' => 'pending', 'progress' => 0]);
                }
                $source->save();
                $sources[] = $source->id;
            }

            foreach ($languages as $language) {
                $label = trim($language['name']);
                $source = $agent->knowledgeSources()->firstOrNew([
                    'source_scope' => 'language',
                    'taxonomy_label' => $label,
                ]);
                $url = trim($language['url']);
                $urlChanged = ! $source->exists || $source->url !== $url;
                $source->fill([
                    'type' => 'url', 'name' => "Website language: {$label}",
                    'url' => $url,
                ]);
                if ($urlChanged) {
                    $source->fill(['status' => 'pending', 'progress' => 0, 'error' => null]);
                }
                $source->save();
                $sources[] = $source->id;
            }

            if (filled($data['sitemap_url'] ?? null)) {
                $sitemap = $agent->knowledgeSources()->firstOrNew(['source_scope' => 'sitemap']);
                $sitemap->fill(['type' => 'url', 'name' => 'Sitemap', 'url' => $data['sitemap_url']]);
                if (! $sitemap->exists) {
                    $sitemap->fill(['status' => 'pending', 'progress' => 0]);
                }
                $sitemap->save();
                $sources[] = $sitemap->id;
            }

            return $sources;
        });

        $message = count($sourceIds).' website knowledge sources were saved. Synchronization was not started automatically.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'source_ids' => $sourceIds])
            : back()->with('success', $message);
    }

    public function sync(KnowledgeSource $source, KnowledgeIngestionService $ingestion, TenantContext $tenant)
    {
        $this->own($source, $tenant);
        $tenant->authorize(['owner', 'admin']);
        if (! $source->isRefreshable()) {
            return back()->with('success', 'Static fixture snapshot has no source payload to synchronize. Add a real URL or file source for refreshable knowledge.');
        }
        if ($source->type === 'url') {
            if ($source->status === 'processing' && $source->updated_at?->isAfter(now()->subHours(2))) {
                return back()->with('success', 'This source is already queued or synchronizing. A duplicate crawl was not started.');
            }
            $source->update(['status' => 'processing', 'progress' => 1, 'error' => null]);
            CrawlPublicWebsite::dispatch($source->id);

            $message = $ingestion->taxonomyForSource($source) !== []
                ? 'The category index is updating in the background. Only its listing pages will be checked.'
                : 'The complete public website is now being re-indexed in the background.';

            return back()->with('success', $message);
        }
        try {
            $ingestion->ingest($source);

            return back()->with('success', 'Source synchronized.');
        } catch (\Throwable) {
            return back()->with('error', $source->fresh()->error);
        }
    }

    public function destroy(KnowledgeSource $source, KnowledgeIngestionService $ingestion, TenantContext $tenant)
    {
        $this->own($source, $tenant);
        $tenant->authorize(['owner', 'admin']);
        $ingestion->deleteStoredFile($source);
        $source->agent->products()->where('metadata->source_id', $source->id)->update(['is_active' => false]);
        $source->delete();

        return back()->with('success', 'Source removed.');
    }

    private function own(KnowledgeSource $s, TenantContext $t): void
    {
        abort_unless($s->agent_id === $t->agent()->id, 404);
    }
}
