<?php

namespace App\Services;

use App\Models\ChannelConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class LinkedInClient
{
    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return config('linkedin.oauth_url').'/oauth/v2/authorization?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('linkedin.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(' ', config('linkedin.scopes', [])),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): array
    {
        return Http::asForm()->post(config('linkedin.oauth_url').'/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => config('linkedin.client_id'),
            'client_secret' => config('linkedin.client_secret'),
        ])->throw()->json();
    }

    public function managedOrganizations(string $token): array
    {
        $elements = $this->request($token)->get('/rest/organizationAcls', [
            'q' => 'roleAssignee',
            'state' => 'APPROVED',
        ])->throw()->json('elements', []);

        return collect($elements)->filter(fn (array $item): bool => in_array(
            $item['role'] ?? null,
            ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR', 'DIRECT_SPONSORED_CONTENT_POSTER'],
            true,
        ))->map(function (array $item) use ($token): ?array {
            $urn = (string) ($item['organizationTarget'] ?? '');
            if (! preg_match('/^urn:li:organization:(\d+)$/', $urn, $match)) {
                return null;
            }
            $organization = $this->request($token)->get('/rest/organizations/'.$match[1])->throw()->json();
            $name = trim((string) data_get($organization, 'localizedName'));

            return ['id' => $match[1], 'urn' => $urn, 'name' => $name !== '' ? $name : 'LinkedIn Page'];
        })->filter()->unique('id')->values()->all();
    }

    public function publish(ChannelConnection $connection, string $caption, string $imageUrl): array
    {
        $owner = 'urn:li:organization:'.$connection->external_account_id;
        $initialize = $this->request($connection->access_token)->post('/rest/images?action=initializeUpload', [
            'initializeUploadRequest' => ['owner' => $owner],
        ])->throw()->json('value');
        $uploadUrl = (string) ($initialize['uploadUrl'] ?? '');
        $imageUrn = (string) ($initialize['image'] ?? '');
        throw_if($uploadUrl === '' || $imageUrn === '', new \RuntimeException('LinkedIn did not initialize the image upload.'));

        $image = Http::connectTimeout(5)->timeout(30)->get($imageUrl)->throw();
        Http::withBody($image->body(), $image->header('Content-Type') ?: 'image/jpeg')
            ->put($uploadUrl)->throw();

        $response = $this->request($connection->access_token)->post('/rest/posts', [
            'author' => $owner,
            'commentary' => $caption,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'content' => ['media' => ['id' => $imageUrn]],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ])->throw();

        $postId = (string) $response->header('x-restli-id');
        throw_if($postId === '', new \RuntimeException('LinkedIn published the request without returning a post identifier.'));

        return ['id' => $postId];
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl(config('linkedin.api_url'))
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'LinkedIn-Version' => config('linkedin.version'),
                'X-Restli-Protocol-Version' => '2.0.0',
            ])->connectTimeout(5)->timeout(30);
    }
}
