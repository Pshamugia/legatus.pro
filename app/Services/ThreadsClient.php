<?php

namespace App\Services;

use App\Models\ChannelConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ThreadsClient
{
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return config('threads.authorization_url').'/oauth/authorize?'.http_build_query([
            'client_id' => config('threads.app_id'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', config('threads.scopes', [])),
            'response_type' => 'code',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return Http::asForm()->post(config('threads.api_url').'/oauth/access_token', [
            'client_id' => config('threads.app_id'),
            'client_secret' => config('threads.app_secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ])->throw()->json();
    }

    public function exchangeLongLivedToken(string $shortLivedToken): array
    {
        return Http::get(config('threads.api_url').'/access_token', [
            'grant_type' => 'th_exchange_token',
            'client_secret' => config('threads.app_secret'),
            'access_token' => $shortLivedToken,
        ])->throw()->json();
    }

    public function profile(string $token): array
    {
        return $this->request($token)->get('/me', [
            'fields' => 'id,username,name',
        ])->throw()->json();
    }

    public function publish(ChannelConnection $connection, string $caption, string $imageUrl): array
    {
        $this->refreshWhenNeeded($connection);
        $token = (string) $connection->access_token;
        $container = $this->request($token)->post('/me/threads', [
            'media_type' => 'IMAGE',
            'image_url' => $imageUrl,
            'text' => $caption,
        ])->throw()->json();
        $containerId = (string) ($container['id'] ?? '');
        throw_if($containerId === '', new \RuntimeException('Threads did not return a media container identifier.'));

        $status = null;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $status = $this->request($token)->get('/'.$containerId, [
                'fields' => 'status,error_message',
            ])->throw()->json();
            if (($status['status'] ?? null) === 'FINISHED') {
                break;
            }
            if (in_array($status['status'] ?? null, ['ERROR', 'EXPIRED'], true)) {
                throw new \RuntimeException('Threads could not prepare the image: '.(string) ($status['error_message'] ?? $status['status']));
            }
            usleep(400000);
        }
        throw_if(($status['status'] ?? null) !== 'FINISHED', new \RuntimeException('Threads image preparation did not finish in time.'));

        $published = $this->request($token)->post('/me/threads_publish', [
            'creation_id' => $containerId,
        ])->throw()->json();
        $postId = (string) ($published['id'] ?? '');
        throw_if($postId === '', new \RuntimeException('Threads published the request without returning a post identifier.'));

        return ['id' => $postId];
    }

    private function refreshWhenNeeded(ChannelConnection $connection): void
    {
        if (! $connection->token_expires_at || $connection->token_expires_at->isAfter(now()->addDays(7))) {
            return;
        }

        $refreshed = $this->request((string) $connection->access_token)
            ->get('/refresh_access_token', ['grant_type' => 'th_refresh_token'])
            ->throw()->json();
        $token = (string) ($refreshed['access_token'] ?? '');
        $expiresIn = (int) ($refreshed['expires_in'] ?? 0);
        throw_if($token === '' || $expiresIn < 1, new \RuntimeException('Threads did not refresh the publishing authorization.'));

        $connection->update([
            'access_token' => $token,
            'token_expires_at' => now()->addSeconds($expiresIn),
            'last_error' => null,
        ]);
        $connection->refresh();
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl(config('threads.api_url'))
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }
}
