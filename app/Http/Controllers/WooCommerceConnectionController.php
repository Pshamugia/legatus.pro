<?php

namespace App\Http\Controllers;

use App\Models\CommerceInstallation;
use App\Services\CommerceCatalogSyncService;
use App\Services\CommerceOriginValidator;
use App\Services\CommerceWebhookProvisioner;
use App\Services\TenantContext;
use App\Services\WooCommerceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class WooCommerceConnectionController extends Controller
{
    public function connect(Request $request, TenantContext $tenant, CommerceOriginValidator $origins): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate(['store_url' => ['required', 'url:https', 'max:500']]);

        try {
            $baseUrl = $origins->normalize($data['store_url']);
        } catch (\InvalidArgumentException $exception) {
            return to_route('channels.index')->with('commerce_error', $exception->getMessage());
        }

        $token = Str::random(64);
        CommerceInstallation::query()
            ->where('agent_id', $tenant->agent()->id)
            ->where('provider', 'woocommerce')
            ->where('status', 'pending')
            ->delete();
        CommerceInstallation::query()->create([
            'agent_id' => $tenant->agent()->id,
            'provider' => 'woocommerce',
            'token_hash' => hash('sha256', $token),
            'base_url' => $baseUrl,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(20),
        ]);

        $query = http_build_query([
            'app_name' => 'Legatus',
            'scope' => 'read_write',
            'user_id' => $token,
            'return_url' => route('channels.commerce.woocommerce.complete'),
            'callback_url' => route('webhooks.commerce.woocommerce.authorize'),
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(rtrim($baseUrl, '/').'/wc-auth/v1/authorize?'.$query);
    }

    public function authorize(Request $request): JsonResponse
    {
        if (strlen($request->getContent()) > 32_768) {
            abort(413);
        }
        $payload = $request->json()->all();
        $validator = validator($payload, [
            'user_id' => ['required', 'string', 'size:64'],
            'consumer_key' => ['required', 'string', 'starts_with:ck_', 'max:200'],
            'consumer_secret' => ['required', 'string', 'starts_with:cs_', 'max:200'],
            'key_permissions' => ['required', 'in:read_write'],
        ]);
        if ($validator->fails()) {
            return response()->json(['ok' => false], 422);
        }
        $data = $validator->validated();
        $installation = CommerceInstallation::query()
            ->where('provider', 'woocommerce')
            ->where('token_hash', hash('sha256', $data['user_id']))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();
        $installation->update([
            'credentials' => [
                'consumer_key' => $data['consumer_key'],
                'consumer_secret' => $data['consumer_secret'],
            ],
            'status' => 'authorized',
        ]);

        return response()->json(['ok' => true]);
    }

    public function complete(
        Request $request,
        TenantContext $tenant,
        CommerceCatalogSyncService $sync,
        WooCommerceClient $wooCommerce,
        CommerceWebhookProvisioner $webhooks,
    ): RedirectResponse {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'success' => ['required', 'in:0,1'],
            'user_id' => ['required', 'string', 'size:64'],
        ]);
        $installation = CommerceInstallation::query()
            ->where('agent_id', $tenant->agent()->id)
            ->where('provider', 'woocommerce')
            ->where('token_hash', hash('sha256', $data['user_id']))
            ->where('expires_at', '>', now())
            ->first();
        if (! $installation || $data['success'] !== '1' || $installation->status !== 'authorized') {
            return to_route('channels.index')->with('commerce_error', 'WooCommerce authorization was not completed. Nothing was changed.');
        }
        $credentials = $installation->credentials;

        try {
            $currency = $wooCommerce->currency(
                $installation->base_url,
                $credentials['consumer_key'],
                $credentials['consumer_secret'],
            );
            $existingConnection = $tenant->agent()->commerceConnection()->first();
            $reuseWebhook = $existingConnection?->provider === 'woocommerce';
            $result = $sync->connect($tenant->agent(), [
                'provider' => 'woocommerce',
                'name' => parse_url($installation->base_url, PHP_URL_HOST).' · WooCommerce',
                'base_url' => $installation->base_url,
                'key_id' => $credentials['consumer_key'],
                'secret' => $credentials['consumer_secret'],
                'webhook_token' => $reuseWebhook && $existingConnection->webhook_token
                    ? $existingConnection->webhook_token
                    : (string) Str::uuid(),
                'webhook_secret' => $reuseWebhook && $existingConnection->webhook_secret
                    ? $existingConnection->webhook_secret
                    : Str::random(64),
                'settings' => ['currency' => $currency, 'attribution_status' => 'setting_up'],
            ]);
            $connection = $tenant->agent()->commerceConnection()->firstOrFail();
            try {
                $webhooks->provision($connection);
            } catch (Throwable $exception) {
                report($exception);
                $webhooks->markPending($connection);
            }
            $installation->update(['status' => 'completed', 'credentials' => null]);
        } catch (Throwable $exception) {
            report($exception);

            return to_route('channels.index')->with('commerce_error', 'WooCommerce could not finish the verified connection. Your previous catalog was preserved.');
        }

        return to_route('channels.index')->with(
            'commerce_success',
            data_get($connection->fresh()->settings, 'attribution_status') === 'active'
                ? "WooCommerce connected. {$result['received']} products verified; order attribution is active."
                : "WooCommerce connected. {$result['received']} products verified; Legatus will finish order attribution automatically.",
        );
    }
}
