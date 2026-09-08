<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\TenantContext;
use App\Services\WidgetInstallationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    public function __construct(private WidgetInstallationService $installation) {}

    public function index(TenantContext $tenant)
    {
        return redirect(route('onboarding').'#channels');
    }

    public function setupViewData(TenantContext $tenant): array
    {
        $agent = $tenant->agent();
        $snippet = '<script src="'.route('widget.script', $agent).'" async></script>';
        $connections = method_exists($agent, 'channelConnections')
            ? $agent->channelConnections()->whereIn('provider', ['facebook', 'instagram', 'linkedin', 'whatsapp'])->get()
            : collect();

        $metaChannels = collect([
            'facebook' => [
                'name' => 'Facebook Messenger',
                'short_name' => 'Facebook',
                'icon' => 'f',
                'description' => 'Answer every new Messenger conversation from the same Legatus inbox.',
            ],
            'instagram' => [
                'name' => 'Instagram Direct',
                'short_name' => 'Instagram',
                'icon' => '◎',
                'description' => 'Turn product questions and recommendation requests in Direct into sales.',
            ],
        ])->map(function (array $channel, string $provider) use ($agent, $connections): array {
            $connection = $connections
                ->where('provider', $provider)
                ->sortByDesc('id')
                ->first();

            return array_merge($channel, $this->connectionState($agent, $provider, $connection));
        });
        $metaConnectUrl = Route::has('channels.meta.connect')
            ? route('channels.meta.connect', ['provider' => 'meta'])
            : null;
        $linkedinConnection = $connections->firstWhere('provider', 'linkedin');
        $linkedinConnected = $linkedinConnection?->isActive() ?? false;
        $linkedinChannel = [
            'connection' => $linkedinConnection,
            'connected' => $linkedinConnected,
            'account_name' => $linkedinConnection?->external_account_name,
            'connect_url' => Route::has('channels.linkedin.connect') ? route('channels.linkedin.connect') : null,
            'disconnect_url' => $linkedinConnection && Route::has('channels.linkedin.disconnect')
                ? route('channels.linkedin.disconnect', $linkedinConnection)
                : null,
            'error' => $linkedinConnection?->token_expires_at?->isPast()
                ? 'LinkedIn authorization expired. Reconnect to resume publishing.'
                : $linkedinConnection?->last_error,
        ];
        $whatsappConnection = $connections->firstWhere('provider', 'whatsapp');
        $whatsappChannel = [
            'connection' => $whatsappConnection,
            'connected' => $whatsappConnection?->isActive() ?? false,
            'account_name' => $whatsappConnection?->external_account_name,
            'phone_number' => data_get($whatsappConnection?->metadata, 'display_phone_number'),
            'connect_url' => Route::has('channels.whatsapp.connect') ? route('channels.whatsapp.connect') : null,
            'disconnect_url' => $whatsappConnection && Route::has('channels.whatsapp.disconnect')
                ? route('channels.whatsapp.disconnect', $whatsappConnection) : null,
            'error' => $whatsappConnection?->token_expires_at?->isPast()
                ? 'WhatsApp authorization expired. Reconnect to restore replies.' : $whatsappConnection?->last_error,
        ];

        // Match the exact tenant-scoped catalog that customer conversations can use.
        $productCount = $agent->customerProducts()->where('is_active', true)->count();
        $knowledgeSourceCount = $agent->knowledgeSources()->count();
        $failedKnowledgeSources = $agent->knowledgeSources()
            ->where('status', 'failed')
            ->get(['id', 'name', 'error']);
        $commerceConnection = $agent->commerceConnection()->first();
        $commerceProductCount = $commerceConnection
            ? $agent->products()->where('commerce_connection_id', $commerceConnection->id)->where('is_active', true)->count()
            : 0;
        $catalogConnectionState = match (true) {
            $commerceConnection?->status === 'active' => 'live',
            $commerceConnection !== null => 'attention',
            $productCount > 0 => 'available',
            default => 'missing',
        };
        $canManageChannels = in_array($tenant->role(), ['owner', 'admin'], true);
        $widgetEnabled = $agent->websiteWidgetEnabled();
        $widgetWebsite = trim((string) data_get($agent->settings, 'website', ''));
        $widgetPlatforms = $this->installation->platforms();
        $widgetPlatform = (string) data_get($agent->settings, 'widget_installation.platform', 'other');
        if (! array_key_exists($widgetPlatform, $widgetPlatforms)) {
            $widgetPlatform = 'other';
        }
        $widgetInstallation = data_get($agent->settings, 'widget_installation', []);
        $widgetInstallation = is_array($widgetInstallation) ? $widgetInstallation : [];
        $widgetDomains = collect(data_get($agent->settings, 'widget_allowed_origins', []))
            ->filter(fn (mixed $origin): bool => is_string($origin))
            ->map(fn (string $origin): string => (string) (parse_url($origin, PHP_URL_HOST) ?: $origin))
            ->filter()
            ->unique()
            ->values();

        return compact(
            'agent',
            'snippet',
            'metaChannels',
            'metaConnectUrl',
            'linkedinChannel',
            'whatsappChannel',
            'productCount',
            'knowledgeSourceCount',
            'failedKnowledgeSources',
            'widgetDomains',
            'commerceConnection',
            'commerceProductCount',
            'catalogConnectionState',
            'canManageChannels',
            'widgetEnabled',
            'widgetWebsite',
            'widgetPlatforms',
            'widgetPlatform',
            'widgetInstallation',
        );
    }

    public function detectWidgetPlatform(TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        $website = trim((string) data_get($agent->settings, 'website', ''));
        if ($website === '') {
            return redirect(route('onboarding').'#website-channel')
                ->with('channel_error', 'Save your public website address before detecting its platform.');
        }

        try {
            $platform = $this->installation->detect($website);
            $this->updateWidgetInstallation($agent, [
                'platform' => $platform,
                'detected_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect(route('onboarding').'#website-channel')
                ->with('channel_error', 'Legatus could not inspect the website safely. Choose the platform manually below.');
        }

        $label = $this->installation->platforms()[$platform]['label'];

        return redirect(route('onboarding').'#website-channel')
            ->with('channel_success', "Platform detected: {$label}. Follow the instructions below.");
    }

    public function updateWidgetPlatform(Request $request, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'platform' => ['required', 'string', Rule::in(array_keys($this->installation->platforms()))],
        ]);
        $this->updateWidgetInstallation($tenant->agent(), [
            'platform' => $data['platform'],
            'selected_at' => now()->toIso8601String(),
        ]);

        return redirect(route('onboarding').'#website-channel')
            ->with('channel_success', 'Installation instructions updated for '.$this->installation->platforms()[$data['platform']]['label'].'.');
    }

    public function verifyWidgetInstallation(TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        $website = trim((string) data_get($agent->settings, 'website', ''));
        if ($website === '') {
            return redirect(route('onboarding').'#website-channel')
                ->with('channel_error', 'Save your public website address before checking the installation.');
        }

        try {
            $result = $this->installation->verify($website, route('widget.script', $agent));
            $this->updateWidgetInstallation($agent, [
                'installed' => $result['installed'],
                'checked_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect(route('onboarding').'#website-channel')
                ->with('channel_error', 'The website could not be checked safely right now. Nothing was changed on the website.');
        }

        return redirect(route('onboarding').'#website-channel')->with(
            $result['installed'] ? 'channel_success' : 'channel_error',
            $result['installed']
                ? 'Legatus is installed and visible in the website source.'
                : 'Legatus was not found on the public homepage yet. Publish the website changes, clear its cache, and check again.',
        );
    }

    public function updateWidget(Request $request, TenantContext $tenant)
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $enabled = (bool) $data['enabled'];
        $agentId = $tenant->agent()->getKey();

        DB::transaction(function () use ($agentId, $enabled): void {
            $agent = Agent::query()->lockForUpdate()->findOrFail($agentId);
            $channels = collect($agent->channels ?? ['web'])
                ->filter(fn (mixed $channel): bool => is_string($channel) && $channel !== '')
                ->reject(fn (string $channel): bool => $channel === 'web');

            if ($enabled) {
                $channels->prepend('web');
            }

            $agent->update(['channels' => $channels->unique()->values()->all()]);
        });

        return redirect(route('onboarding').'#channels')->with(
            'channel_success',
            $enabled
                ? 'Website chat is ON. The existing script will show the widget again.'
                : 'Website chat is OFF. The existing script can stay installed, but customers cannot open or use the chat.',
        );
    }

    private function connectionState(Agent $agent, string $provider, mixed $connection): array
    {
        $fallback = data_get($agent->settings, "channel_connections.{$provider}", []);
        $fallback = is_array($fallback) ? $fallback : [];

        // A generated widget is ready immediately, but social channels are only
        // connected after a persisted, valid OAuth connection exists.
        $rawStatus = strtolower((string) ($connection?->status ?? 'disconnected'));
        $tokenExpired = $connection?->token_expires_at?->isPast() ?? false;
        $isConnected = $connection
            ? ($rawStatus === 'active' && ! $tokenExpired)
            : false;

        $status = match (true) {
            $isConnected => 'connected',
            $tokenExpired => 'error',
            in_array($rawStatus, ['pending', 'connecting'], true) => 'pending',
            in_array($rawStatus, ['error', 'failed', 'expired', 'needs_attention'], true) => 'error',
            default => 'disconnected',
        };

        $accountName = $connection?->external_account_name
            ?? data_get($connection?->metadata, 'display_name')
            ?? data_get($connection?->metadata, 'username')
            ?? ($fallback['account_name'] ?? null);

        $connectUrl = Route::has('channels.meta.connect')
            ? route('channels.meta.connect', ['provider' => 'meta'])
            : null;
        $disconnectUrl = $connection && Route::has('channels.meta.disconnect')
            ? route('channels.meta.disconnect', ['connection' => $connection])
            : null;

        $error = $tokenExpired
            ? 'Meta authorization expired. Reconnect to restore replies.'
            : ($connection?->last_error ? 'Meta could not complete the last connection. Reconnect to try again.' : null);

        return [
            'provider' => $provider,
            'connection' => $connection,
            'status' => $status,
            'connected' => $isConnected,
            'account_name' => $accountName,
            'connect_url' => $connectUrl,
            'disconnect_url' => $disconnectUrl,
            'last_webhook_at' => $connection?->last_webhook_at,
            'error' => $error,
        ];
    }

    /** @param array<string, mixed> $values */
    private function updateWidgetInstallation(Agent $agent, array $values): void
    {
        DB::transaction(function () use ($agent, $values): void {
            $locked = Agent::query()->lockForUpdate()->findOrFail($agent->getKey());
            $settings = $locked->settings ?? [];
            $current = data_get($settings, 'widget_installation', []);
            $settings['widget_installation'] = array_merge(is_array($current) ? $current : [], $values);
            $locked->update(['settings' => $settings]);
        });
    }
}
