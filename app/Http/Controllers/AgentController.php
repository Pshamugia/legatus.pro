<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Lead;
use App\Services\KnowledgeIngestionService;
use App\Services\TenantContext;
use App\Support\WidgetTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        $connections = $agent->channelConnections()
            ->whereIn('provider', ['facebook', 'instagram'])
            ->get()
            ->keyBy('provider');
        $channelStatuses = collect(['facebook', 'instagram'])->mapWithKeys(function (string $provider) use ($connections): array {
            $connection = $connections->get($provider);

            return [$provider => [
                'connected' => $connection?->isActive() ?? false,
                'name' => $connection?->external_account_name,
                'needs_attention' => $connection !== null && ! $connection->isActive(),
            ]];
        });

        return view('dashboard', compact('agent', 'conversations', 'metrics', 'products', 'channelStatuses'));
    }

    public function onboarding(TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        $agent->load([
            'knowledgeSources' => fn ($query) => $query->latest(),
            'commerceConnection',
        ]);

        return view('onboarding', compact('agent'));
    }

    public function store(Request $r, TenantContext $tenant, KnowledgeIngestionService $ingestion)
    {
        $tenant->authorize(['owner', 'admin']);
        $r->merge([
            'business_name' => $this->trimUnicodeWhitespace($r->input('business_name')),
            'agent_name' => $this->trimUnicodeWhitespace($r->input('agent_name')),
            'website' => is_string($r->input('website')) ? trim($r->input('website')) : $r->input('website'),
            'catalog_url' => is_string($r->input('catalog_url')) ? trim($r->input('catalog_url')) : $r->input('catalog_url'),
        ]);
        $data = $r->validate([
            'business_name' => $this->identityRules(120),
            'agent_name' => $this->identityRules(80),
            'website' => $this->publicUrlRules(),
            'catalog_url' => $this->publicUrlRules(),
            'catalog' => ['nullable', 'file', 'max:10240', 'mimes:csv,txt,pdf'],
            'description' => ['nullable', 'string', 'max:1000'],
            'widget_theme_preset' => ['sometimes', 'string', Rule::in(WidgetTheme::allowedPresets())],
            'widget_theme_primary' => ['bail', 'exclude_unless:widget_theme_preset,custom', 'required', 'string', 'regex:/\A#[0-9A-Fa-f]{6}\z/'],
            'widget_theme_accent' => [
                'bail',
                'exclude_unless:widget_theme_preset,custom',
                'required',
                'string',
                'regex:/\A#[0-9A-Fa-f]{6}\z/',
                function (string $attribute, mixed $value, \Closure $fail) use ($r): void {
                    $primary = WidgetTheme::normalizeHex($r->input('widget_theme_primary'));
                    $accent = WidgetTheme::normalizeHex($value);

                    if ($primary !== null && $accent !== null && ! WidgetTheme::hasSufficientPairContrast($primary, $accent)) {
                        $fail('Choose primary and accent colors with at least a 3:1 contrast ratio.');
                    }
                },
            ],
        ]);
        $agent = $tenant->agent();
        $currentTheme = $agent->widgetTheme();
        $widgetTheme = array_key_exists('widget_theme_preset', $data)
            ? WidgetTheme::configured(
                $data['widget_theme_preset'],
                $data['widget_theme_primary'] ?? $currentTheme['primary'],
                $data['widget_theme_accent'] ?? $currentTheme['accent'],
            )
            : null;
        $settings = array_merge($agent->settings ?? [], [
            'website' => $data['website'] ?? null,
            'catalog_url' => $data['catalog_url'] ?? null,
        ]);
        if ($widgetTheme !== null) {
            $settings['widget_theme'] = $widgetTheme;
        }
        if (! empty($data['website'])) {
            $origin = $this->origin($data['website']);
            if ($origin !== null) {
                $settings['widget_allowed_origins'] = array_values(array_unique([$origin, $this->alternateWwwOrigin($origin)]));
            }
        } else {
            $settings['widget_allowed_origins'] = [];
        }

        DB::transaction(function () use ($agent, $data, $settings): void {
            $agent->organization()->update(['name' => $data['business_name']]);
            $agent->update([
                'name' => trim($data['agent_name']),
                'business_name' => $data['business_name'],
                'description' => $data['description'] ?? null,
                'settings' => $settings,
            ]);
        });

        $learned = [];
        $warnings = [];
        $activeCommerceConnection = $agent->commerceConnection()
            ->where('status', 'active')
            ->first();
        $urlSources = collect([
            [
                'url' => $data['catalog_url'] ?? null,
                'name' => ! empty($data['catalog_url']) ? parse_url($data['catalog_url'], PHP_URL_HOST).' product catalog' : null,
                'purpose' => 'catalog',
            ],
            [
                'url' => $data['website'] ?? null,
                'name' => ! empty($data['website']) ? parse_url($data['website'], PHP_URL_HOST) : null,
                'purpose' => 'website',
            ],
        ])->filter(fn (array $source): bool => filled($source['url']))
            // A verified live connector owns product, price and stock facts. Keep
            // the human-entered URL in settings for reference or later fallback,
            // but do not repeatedly import it as a competing catalog source.
            ->reject(fn (array $source): bool => $activeCommerceConnection !== null && $source['purpose'] === 'catalog')
            ->unique('url');

        foreach ($urlSources as $urlSource) {
            $source = $agent->knowledgeSources()->firstOrNew(['type' => 'url', 'url' => $urlSource['url']]);
            $source->name = $urlSource['name'];
            if (! $source->exists) {
                $source->status = 'pending';
            }
            $source->save();
            try {
                $ingestion->ingest($source);
                $learned[] = $source->name;
                if ($urlSource['purpose'] === 'catalog'
                    && $activeCommerceConnection === null
                    && ! $agent->products()->where('metadata->source_id', $source->id)->where('is_active', true)->exists()) {
                    $warnings[] = 'The catalog URL was readable, but it did not expose structured products with verified prices. Use a supported JSON feed, structured product data, CSV, or the live store connector.';
                }
            } catch (\Throwable $exception) {
                report($exception);
                $warnings[] = "URL could not be learned: {$source->fresh()->error}";
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

        $success = match (true) {
            $warnings !== [] && $learned !== [] => 'Setup saved. Some sources were learned; the items below still need attention.',
            $warnings !== [] => 'Setup saved, but one or more sources still need attention.',
            $learned !== [] => 'Setup saved and learned '.implode(', ', $learned).'.',
            $activeCommerceConnection !== null => 'Setup saved. Your live store connection remains the authoritative product catalog.',
            default => 'Setup saved.',
        };

        return redirect()->route('channels.index')
            ->with('success', $success)
            ->with('warnings', $warnings);
    }

    private function publicUrlRules(): array
    {
        return ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $parts = is_string($value) ? parse_url($value) : false;
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            if (! is_array($parts)
                || ! in_array($scheme, ['http', 'https'], true)
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
                || preg_match('/[\x00-\x20\x7f]/', (string) $value) === 1) {
                $fail('Enter a public HTTP(S) URL without embedded credentials.');
            }
        }];
    }

    private function identityRules(int $maximum): array
    {
        return ['bail', 'required', 'string', "max:{$maximum}", function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
                $fail('Use valid Unicode text for this name.');

                return;
            }

            if (preg_match('/[\p{Cc}]/u', $value) === 1) {
                $fail('Names cannot contain control characters.');
            }
        }];
    }

    private function trimUnicodeWhitespace(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $value) ?? $value;
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return strtolower($scheme.'://'.$parts['host']).(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
    }

    private function alternateWwwOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        $host = (string) ($parts['host'] ?? '');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host;

        return ($parts['scheme'] ?? 'https').'://'.$host.(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
    }
}
