<?php

namespace App\Http\Controllers;

use App\Services\CommerceCatalogSyncService;
use App\Services\CommerceWebhookProvisioner;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class ShopifyConnectionController extends Controller
{
    public function connect(Request $request, TenantContext $tenant): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        if (! config('services.shopify.client_id') || ! config('services.shopify.client_secret')) {
            return to_route('channels.index')->with('commerce_error', 'Shopify connection is not enabled on this Legatus installation yet.');
        }
        $data = $request->validate(['shop' => ['required', 'string', 'max:255']]);
        $shop = $this->shopDomain($data['shop']);
        if (! $shop) {
            return to_route('channels.index')->with('commerce_error', 'Enter the shop address in the form your-store.myshopify.com.');
        }
        $state = Str::random(64);
        $request->session()->put('shopify_oauth', [
            'state_hash' => hash('sha256', $state),
            'agent_id' => $tenant->agent()->id,
            'shop' => $shop,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ]);
        $query = http_build_query([
            'client_id' => config('services.shopify.client_id'),
            'scope' => config('services.shopify.scopes'),
            'redirect_uri' => route('channels.commerce.shopify.callback'),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://'.$shop.'/admin/oauth/authorize?'.$query);
    }

    public function callback(
        Request $request,
        TenantContext $tenant,
        CommerceCatalogSyncService $sync,
        CommerceWebhookProvisioner $webhooks,
    ): RedirectResponse {
        $tenant->authorize(['owner', 'admin']);
        $oauth = $request->session()->pull('shopify_oauth');
        $shop = $this->shopDomain((string) $request->query('shop'));
        $state = (string) $request->query('state');
        if (! is_array($oauth)
            || ($oauth['agent_id'] ?? null) !== $tenant->agent()->id
            || ($oauth['shop'] ?? null) !== $shop
            || (int) ($oauth['expires_at'] ?? 0) < now()->timestamp
            || ! hash_equals((string) ($oauth['state_hash'] ?? ''), hash('sha256', $state))
            || ! $this->validHmac($request)) {
            return to_route('channels.index')->with('commerce_error', 'Shopify authorization could not be verified. Nothing was changed.');
        }

        try {
            $token = Http::asForm()->acceptJson()->post('https://'.$shop.'/admin/oauth/access_token', [
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => config('services.shopify.client_secret'),
                'code' => (string) $request->query('code'),
                'expiring' => 1,
            ])->throw()->json();
            if (! is_array($token) || blank($token['access_token'] ?? null) || blank($token['refresh_token'] ?? null)) {
                throw new \RuntimeException('Shopify returned an invalid authorization response.');
            }
            $settings = [
                'currency' => strtoupper((string) data_get($tenant->agent()->organization?->settings, 'currency', 'USD')),
                'token_expires_at' => now()->addSeconds(max(60, (int) ($token['expires_in'] ?? 3600)))->toIso8601String(),
                'refresh_token_expires_at' => now()->addSeconds(max(60, (int) ($token['refresh_token_expires_in'] ?? 7_776_000)))->toIso8601String(),
                'granted_scopes' => array_values(array_filter(explode(',', (string) ($token['scope'] ?? '')))),
                'attribution_status' => 'setting_up',
            ];
            $existingConnection = $tenant->agent()->commerceConnection()->first();
            $result = $sync->connect($tenant->agent(), [
                'provider' => 'shopify',
                'name' => $shop.' · Shopify',
                'base_url' => 'https://'.$shop,
                'key_id' => $shop,
                'secret' => json_encode([
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'],
                ], JSON_THROW_ON_ERROR),
                'webhook_token' => $existingConnection?->provider === 'shopify' && $existingConnection->webhook_token
                    ? $existingConnection->webhook_token
                    : (string) Str::uuid(),
                'webhook_secret' => config('services.shopify.client_secret'),
                'settings' => $settings,
            ]);
            $connection = $tenant->agent()->commerceConnection()->firstOrFail();
            try {
                $webhooks->provision($connection);
            } catch (Throwable $exception) {
                report($exception);
                $webhooks->markPending($connection);
            }
        } catch (Throwable $exception) {
            report($exception);

            return to_route('channels.index')->with('commerce_error', 'Shopify could not finish the verified connection. Try Connect again; no manual keys are required.');
        }

        return to_route('channels.index')->with(
            'commerce_success',
            data_get($connection->fresh()->settings, 'attribution_status') === 'active'
                ? "Shopify connected. {$result['received']} products verified; order attribution is active."
                : "Shopify connected. {$result['received']} products verified; Legatus will finish order attribution automatically.",
        );
    }

    private function shopDomain(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $value = strtolower((string) parse_url($value, PHP_URL_HOST));
        }

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $value) ? $value : null;
    }

    private function validHmac(Request $request): bool
    {
        $provided = (string) $request->query('hmac');
        $parameters = $request->query();
        unset($parameters['hmac'], $parameters['signature']);
        ksort($parameters);
        $message = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $expected = hash_hmac('sha256', $message, (string) config('services.shopify.client_secret'));

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
