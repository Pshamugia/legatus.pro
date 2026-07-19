<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KnowledgeController extends Controller
{
    public function index(TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $sources = $agent->knowledgeSources()
            ->withCount([
                'chunks',
                'chunks as embedded_chunks_count' => fn ($query) => $query->whereNotNull('embedding'),
            ])
            ->latest()
            ->get();

        return view('knowledge', compact('agent', 'sources'));
    }

    public function store(Request $r, KnowledgeIngestionService $ingestion, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
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
        try {
            $ingestion->ingest($source);

            return back()->with('success', "{$source->name} successfully learned.");
        } catch (\Throwable) {
            return back()->with('error', $source->fresh()->error);
        }
    }

    public function sync(KnowledgeSource $source, KnowledgeIngestionService $ingestion, TenantContext $tenant)
    {
        $this->own($source, $tenant);
        $tenant->authorize(['owner', 'admin']);
        if (! $source->isRefreshable()) {
            return back()->with('success', 'Static fixture snapshot has no source payload to synchronize. Add a real URL or file source for refreshable knowledge.');
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
