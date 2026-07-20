<?php

namespace App\Services;

use App\Models\ChannelConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MetaGraphClient
{
    public function authorizationUrl(string $provider, string $state, string $redirectUri): string
    {
        $query = http_build_query([
            'client_id' => config('meta.app_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', config("meta.scopes.{$provider}", [])),
        ], '', '&', PHP_QUERY_RFC3986);

        return config('meta.dialog_url').'/'.config('meta.graph_version').'/dialog/oauth?'.$query;
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        $tokenResponse = $this->request()->get($this->url('oauth/access_token'), [
            'client_id' => config('meta.app_id'),
            'client_secret' => config('meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        throw_unless($tokenResponse->successful(), new \RuntimeException("Meta token exchange failed with HTTP {$tokenResponse->status()}."));
        $response = $tokenResponse->json();

        $token = (string) ($response['access_token'] ?? '');
        throw_if($token === '', new \RuntimeException('Meta did not return an access token.'));

        // Prefer a long-lived user token. Some test/business configurations do
        // not support the exchange, so the valid short-lived token remains a
        // safe fallback and its expiry is retained.
        try {
            $longLivedResponse = $this->request()->get($this->url('oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('meta.app_id'),
                'client_secret' => config('meta.app_secret'),
                'fb_exchange_token' => $token,
            ]);
            throw_unless($longLivedResponse->successful(), new \RuntimeException('Long-lived Meta token exchange failed.'));
            $longLived = $longLivedResponse->json();
            if (is_string($longLived['access_token'] ?? null) && $longLived['access_token'] !== '') {
                $response = $longLived;
                $token = $longLived['access_token'];
            }
        } catch (\Throwable) {
            // The original token is still valid and can complete onboarding.
        }

        return [
            'access_token' => $token,
            'expires_in' => isset($response['expires_in']) ? (int) $response['expires_in'] : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function managedAccounts(string $userAccessToken): array
    {
        $accounts = [];
        $after = null;

        do {
            $query = [
                'fields' => 'id,name,access_token,instagram_business_account{id,username,name}',
                'limit' => 100,
            ];
            if (is_string($after) && $after !== '') {
                $query['after'] = $after;
            }

            $accountsResponse = $this->authorizedRequest($userAccessToken)->get($this->url('me/accounts'), $query);
            throw_unless($accountsResponse->successful(), new \RuntimeException("Meta account discovery failed with HTTP {$accountsResponse->status()}."));
            $response = $accountsResponse->json();
            $accounts = array_merge($accounts, array_values((array) ($response['data'] ?? [])));
            $after = data_get($response, 'paging.cursors.after');
            $hasNext = is_string(data_get($response, 'paging.next')) && data_get($response, 'paging.next') !== '';
        } while ($hasNext && is_string($after) && $after !== '');

        return $accounts;
    }

    public function subscribe(ChannelConnection $connection): void
    {
        // This endpoint installs the app on the linked Facebook Page and only
        // accepts Page webhook fields. Instagram topics (including
        // messaging_seen) are configured once at app level in Meta's App
        // Dashboard. Keep the Page-valid union identical for both providers so
        // connecting a linked Instagram account cannot narrow a Facebook setup.
        $fields = 'messages,messaging_postbacks,message_deliveries,message_reads';

        $this->authorizedRequest($connection->access_token)
            ->post($this->url($connection->graphAccountId().'/subscribed_apps'), [
                'subscribed_fields' => $fields,
            ])
            ->throw();
    }

    public function unsubscribe(ChannelConnection $connection): void
    {
        $this->authorizedRequest($connection->access_token)
            ->delete($this->url($connection->graphAccountId().'/subscribed_apps'))
            ->throw();
    }

    public function sendText(ChannelConnection $connection, string $recipientId, string $text): array
    {
        throw_unless($connection->isActive(), new \RuntimeException('The Meta channel connection is not active.'));
        throw_if($recipientId === '', new \InvalidArgumentException('A Meta recipient ID is required.'));

        $payload = [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $connection->provider === 'instagram'
                ? $this->instagramText($text)
                : Str::limit($text, 2000, '')],
        ];
        if ($connection->provider === 'facebook') {
            $payload['messaging_type'] = 'RESPONSE';
        }

        // Never retry a send at the HTTP transport layer. Meta has no
        // idempotency key for this endpoint, so a timeout can mean the message
        // was accepted even though the response was lost.
        return $this->authorizedRequest($connection->access_token, retry: false)
            ->post($this->url($connection->graphAccountId().'/messages'), $payload)
            ->throw()
            ->json();
    }

    private function request(bool $retry = true): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) config('meta.connect_timeout')))
            ->timeout(max(2, (int) config('meta.timeout')));

        $retries = max(0, (int) config('meta.retries'));

        return $retry && $retries > 0
            ? $request->retry($retries, 300, throw: false)
            : $request;
    }

    private function authorizedRequest(string $token, bool $retry = true): PendingRequest
    {
        return $this->request($retry)
            ->withToken($token)
            ->withQueryParameters(['appsecret_proof' => $this->appSecretProof($token)]);
    }

    private function appSecretProof(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('meta.app_secret'));
    }

    private function instagramText(string $text): string
    {
        throw_if(! mb_check_encoding($text, 'UTF-8'), new \InvalidArgumentException('Instagram message must be valid UTF-8.'));
        throw_if(mb_strlen($text, 'UTF-8') >= 1000, new \LengthException('Instagram message must contain fewer than 1000 characters.'));

        return $text;
    }

    private function url(string $path): string
    {
        return config('meta.graph_url').'/'.config('meta.graph_version').'/'.ltrim($path, '/');
    }
}
