<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\TenantContext;
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
        $commerceConnection = $agent->commerceConnection()->first();
        $commerceProductCount = $commerceConnection
            ? $agent->products()->where('commerce_connection_id', $commerceConnection->id)->where('is_active', true)->count()
            : 0;
        $canManageChannels = in_array($tenant->role(), ['owner', 'admin'], true);
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
            'widgetDomains',
            'commerceConnection',
            'commerceProductCount',
            'canManageChannels',
        ));
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
