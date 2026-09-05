<?php

namespace App\Services;

use App\Models\ChannelConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppCloudClient
{
    public function exchangeCode(string $code): array
    {
        $response = $this->request()->get($this->url('oauth/access_token'), array_filter([
            'client_id' => config('whatsapp.app_id'),
            'client_secret' => config('whatsapp.app_secret'),
            'code' => $code,
            'redirect_uri' => config('whatsapp.redirect_uri'),
        ]))->throw()->json();

        $token = trim((string) ($response['access_token'] ?? ''));
        throw_if($token === '', new \RuntimeException('WhatsApp did not return an access token.'));

        return ['access_token' => $token, 'expires_in' => isset($response['expires_in']) ? (int) $response['expires_in'] : null];
    }

    public function phoneNumber(string $accessToken, string $phoneNumberId): array
    {
        return $this->authorized($accessToken)->get($this->url($phoneNumberId), [
            'fields' => 'id,display_phone_number,verified_name,quality_rating',
        ])->throw()->json();
    }

    public function subscribeWaba(string $accessToken, string $wabaId): void
    {
        $this->authorized($accessToken)->post($this->url($wabaId.'/subscribed_apps'))->throw();
    }

    public function sendText(ChannelConnection $connection, string $recipientId, string $text): array
    {
        throw_unless($connection->provider === 'whatsapp' && $connection->isActive(), new \RuntimeException('An active WhatsApp connection is required.'));

        return $this->authorized($connection->access_token)
            ->post($this->url($connection->external_account_id.'/messages'), [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipientId,
                'type' => 'text',
                'text' => ['preview_url' => true, 'body' => Str::limit($text, 4096, '')],
            ])->throw()->json();
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()
            ->connectTimeout(max(1, (int) config('whatsapp.connect_timeout')))
            ->timeout(max(2, (int) config('whatsapp.timeout')));
    }

    private function authorized(string $token): PendingRequest
    {
        return $this->request()->withToken($token)
            ->withQueryParameters(['appsecret_proof' => hash_hmac('sha256', $token, (string) config('whatsapp.app_secret'))]);
    }

    private function url(string $path): string
    {
        return config('whatsapp.graph_url').'/'.config('whatsapp.graph_version').'/'.ltrim($path, '/');
    }
}
