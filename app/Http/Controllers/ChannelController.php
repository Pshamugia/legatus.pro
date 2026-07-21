<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

class ChannelController extends Controller
{
    public function index(TenantContext $tenant)
    {
        $agent = $tenant->agent();
        $snippet = '<script src="'.route('widget.script', $agent).'" async></script>';
        $connections = method_exists($agent, 'channelConnections')
            ? $agent->channelConnections()->whereIn('provider', ['facebook', 'instagram'])->get()
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

        $productCount = $agent->products()->where('is_active', true)->count();
        $knowledgeSourceCount = $agent->knowledgeSources()->count();
        $failedKnowledgeSources = $agent->knowledgeSources()
            ->where('status', 'failed')
            ->get(['id', 'name', 'error']);
        $commerceConnection = $agent->commerceConnection()->first();
        $commerceProductCount = $commerceConnection
            ? $agent->products()->where('commerce_connection_id', $commerceConnection->id)->where('is_active', true)->count()
            : 0;
        $canManageChannels = in_array($tenant->role(), ['owner', 'admin'], true);
        $widgetEnabled = $agent->websiteWidgetEnabled();
        $widgetDomains = collect(data_get($agent->settings, 'widget_allowed_origins', []))
            ->filter(fn (mixed $origin): bool => is_string($origin))
            ->map(fn (string $origin): string => (string) (parse_url($origin, PHP_URL_HOST) ?: $origin))
            ->filter()
            ->unique()
            ->values();

        return view('channels', compact(
            'agent',
            'snippet',
            'metaChannels',
            'productCount',
            'knowledgeSourceCount',
            'failedKnowledgeSources',
            'widgetDomains',
            'commerceConnection',
            'commerceProductCount',
            'canManageChannels',
            'widgetEnabled',
        ));
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

        return redirect()->route('channels.index')->with(
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
            ? route('channels.meta.connect', ['provider' => $provider])
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
}
