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
    public function index(TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $sources = $agent->knowledgeSources()
            // Never scan the large embedding JSON column on a page request.
            // Semantic indexing progress is maintained by the background job.
            ->withCount('chunks')
            ->latest()
            ->get();

        return view('knowledge', compact('agent', 'sources'));
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
            return $this->storeWebsiteStructure($r, $tenant);
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

    private function storeWebsiteStructure(Request $request, TenantContext $tenant)
    {
        $data = $request->validate([
            'catalog_url' => 'required|url|max:2000',
            'sitemap_url' => 'nullable|url|max:2000',
            'categories' => 'nullable|array',
            'categories.*.name' => 'required_with:categories.*.url|nullable|string|max:150',
            'categories.*.url' => 'required_with:categories.*.name|nullable|url|max:2000',
        ]);
        $categories = collect($data['categories'] ?? [])
            ->filter(fn (array $category): bool => filled($category['name'] ?? null) && filled($category['url'] ?? null))
            ->unique(fn (array $category): string => mb_strtolower(trim($category['name'])).'|'.trim($category['url']))
            ->values();
        $agent = $tenant->agent();
        $sourceIds = DB::transaction(function () use ($agent, $data, $categories): array {
            $sources = [];
            $catalog = $agent->knowledgeSources()->updateOrCreate(
                ['source_scope' => 'catalog'],
                ['type' => 'url', 'name' => 'Site catalog', 'url' => $data['catalog_url'], 'status' => 'processing', 'progress' => 1, 'error' => null],
            );
            $sources[] = $catalog->id;

            foreach ($categories as $category) {
                $label = trim($category['name']);
                $source = $agent->knowledgeSources()->updateOrCreate(
                    ['source_scope' => 'category', 'taxonomy_label' => $label],
                    ['type' => 'url', 'name' => "Category: {$label}", 'url' => trim($category['url']), 'status' => 'processing', 'progress' => 1, 'error' => null],
                );
                $sources[] = $source->id;
            }

            if (filled($data['sitemap_url'] ?? null)) {
                $sitemap = $agent->knowledgeSources()->updateOrCreate(
                    ['source_scope' => 'sitemap'],
                    ['type' => 'url', 'name' => 'Sitemap', 'url' => $data['sitemap_url'], 'status' => 'processing', 'progress' => 1, 'error' => null],
                );
                $sources[] = $sitemap->id;
            }

            return $sources;
        });

        foreach ($sourceIds as $sourceId) {
            CrawlPublicWebsite::dispatch($sourceId);
        }

        $message = count($sourceIds).' website knowledge indexes were queued safely in the background.';

        return $request->expectsJson()
            ? response()->json(['message' => $message, 'source_ids' => $sourceIds], 202)
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
