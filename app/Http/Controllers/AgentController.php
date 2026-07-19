<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Lead;
use App\Services\KnowledgeIngestionService;
use App\Services\TenantContext;
use Illuminate\Http\Request;

class AgentController extends Controller
{
    public function landing()
    {
        $demoAgent = Agent::where('is_active', true)->where('slug', 'legatus-demo')->first();

        return view('landing', compact('demoAgent'));
    }

    public function dashboard(TenantContext $tenant)
    {
        $agent = $tenant->agent()->loadCount([
            'products' => fn ($query) => $query->where('is_active', true),
        ]);
        $customerConversations = $agent->conversations()->where('channel', '!=', 'eval');
        $conversations = (clone $customerConversations)->with('messages')->latest('last_message_at')->take(6)->get();
        $products = $agent->products()->where('is_active', true)->orderByDesc('stock')->orderBy('name')->take(8)->get();
        $total = (clone $customerConversations)->count();
        $handoffs = (clone $customerConversations)->whereNotNull('handoff_reason')->count();
        $qualifiedLeads = Lead::where('agent_id', $agent->id)
            ->whereIn('status', ['qualified', 'won'])
            ->where(function ($query) use ($customerConversations) {
                $query->whereNull('conversation_id')
                    ->orWhereIn('conversation_id', (clone $customerConversations)->select('conversations.id'));
            });
        $metrics = [
            'conversations' => $total,
            'automation_rate' => $total ? round(($total - $handoffs) / $total * 100) : 0,
            'qualified_leads' => $qualifiedLeads->count(),
            'revenue_influenced' => (float) (clone $customerConversations)->sum('outcome_value'),
            'needs_human' => (clone $customerConversations)->where('status', 'human')->count(),
            'knowledge_readiness' => $agent->knowledgeSources()->exists() ? (int) round($agent->knowledgeSources()->avg('progress')) : 0,
        ];

        return view('dashboard', compact('agent', 'conversations', 'metrics', 'products'));
    }

    public function onboarding()
    {
        return view('onboarding');
    }

    public function store(Request $r, TenantContext $tenant, KnowledgeIngestionService $ingestion)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $r->validate(['business_name' => 'required|max:120', 'website' => 'nullable|url|max:2000', 'catalog' => 'nullable|file|max:10240|mimes:csv,txt,pdf', 'description' => 'nullable|max:1000']);
        $agent = $tenant->agent();
        $agent->update([
            'name' => 'Legatus',
            'business_name' => $data['business_name'],
            'description' => $data['description'] ?? null,
            'channels' => ['web'],
            'settings' => array_merge($agent->settings ?? [], ['website' => $data['website'] ?? null]),
        ]);

        $learned = [];
        $warnings = [];
        if (! empty($data['website'])) {
            $source = $agent->knowledgeSources()->updateOrCreate(['type' => 'url', 'url' => $data['website']], ['name' => parse_url($data['website'], PHP_URL_HOST), 'status' => 'pending']);
            try {
                $ingestion->ingest($source);
                $learned[] = $source->name;
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = "Website could not be learned: {$source->fresh()->error}";
            }
        }
        if ($r->hasFile('catalog')) {
            $file = $r->file('catalog');
            $type = strtolower($file->getClientOriginalExtension()) === 'pdf' ? 'pdf' : 'csv';
            $source = $agent->knowledgeSources()->create(['type' => $type, 'name' => $file->getClientOriginalName(), 'file_path' => $ingestion->storeFile($file, $type)]);
            try {
                $ingestion->ingest($source);
                $learned[] = $source->name;
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = "Catalog could not be learned: {$source->fresh()->error}";
            }
        }

        return redirect()->route('dashboard')->with('success', 'Legatus is ready'.($learned ? ' and learned '.implode(', ', $learned) : '').'.')->with('warnings', $warnings);
    }
}
