<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Services\TenantContext;
use App\Services\WhatsAppCloudClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class WhatsAppConnectionController extends Controller
{
    public function connect(TenantContext $tenant): View|RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        if (! config('whatsapp.app_id') || ! config('whatsapp.app_secret') || ! config('whatsapp.configuration_id')) {
            return to_route('channels.index')->with('channel_error', 'WhatsApp connection is not configured yet.');
        }

        return view('whatsapp-connect', [
            'appId' => (string) config('whatsapp.app_id'),
            'configurationId' => (string) config('whatsapp.configuration_id'),
        ]);
    }

    public function store(Request $request, TenantContext $tenant, WhatsAppCloudClient $client): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:4096'],
            'waba_id' => ['required', 'string', 'max:128'],
            'phone_number_id' => ['required', 'string', 'max:128'],
        ]);
        $agent = $tenant->agent();

        try {
            $token = $client->exchangeCode($data['code']);
            $phone = $client->phoneNumber($token['access_token'], $data['phone_number_id']);
            throw_unless(hash_equals($data['phone_number_id'], (string) ($phone['id'] ?? '')), new \RuntimeException('Phone number identity mismatch.'));

            DB::transaction(function () use ($agent, $data, $token, $phone): void {
                $ownedElsewhere = ChannelConnection::query()
                    ->where('provider', 'whatsapp')
                    ->where('external_account_id', $data['phone_number_id'])
                    ->where('agent_id', '!=', $agent->id)
                    ->lockForUpdate()->exists();
                throw_if($ownedElsewhere, new \RuntimeException('This WhatsApp number is already connected to another business.'));

                ChannelConnection::query()->updateOrCreate(
                    ['agent_id' => $agent->id, 'provider' => 'whatsapp'],
                    [
                        'status' => 'connecting',
                        'external_account_id' => $data['phone_number_id'],
                        'external_account_name' => trim((string) ($phone['verified_name'] ?? '')) ?: (string) ($phone['display_phone_number'] ?? 'WhatsApp Business'),
                        'access_token' => $token['access_token'],
                        'token_expires_at' => $token['expires_in'] ? now()->addSeconds($token['expires_in']) : null,
                        'metadata' => [
                            'waba_id' => $data['waba_id'],
                            'display_phone_number' => $phone['display_phone_number'] ?? null,
                            'quality_rating' => $phone['quality_rating'] ?? null,
                        ],
                        'connected_at' => now(),
                        'last_error' => null,
                    ],
                );
            });
            $client->subscribeWaba($token['access_token'], $data['waba_id']);
            ChannelConnection::query()->where('agent_id', $agent->id)->where('provider', 'whatsapp')->update([
                'status' => 'active',
                'last_error' => null,
            ]);
        } catch (\Throwable) {
            ChannelConnection::query()->where('agent_id', $agent->id)->where('provider', 'whatsapp')
                ->where('status', 'connecting')->update([
                    'status' => 'error',
                    'last_error' => 'Meta did not complete the WhatsApp subscription. Reconnect to try again.',
                ]);

            return to_route('channels.index')->with('channel_error', 'WhatsApp could not be connected. Check the Meta setup and try again.');
        }

        return to_route('channels.index')->with('channel_success', 'WhatsApp Business connected. New customer messages can now reach Legatus.');
    }

    public function disconnect(ChannelConnection $connection, TenantContext $tenant): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($connection->agent_id === $tenant->agent()->id && $connection->provider === 'whatsapp', 404);
        $connection->delete();

        return to_route('channels.index')->with('channel_success', 'WhatsApp disconnected.');
    }
}
