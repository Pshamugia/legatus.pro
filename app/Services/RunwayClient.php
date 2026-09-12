<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class RunwayClient
{
    public function create(string $prompt, ?string $imageUrl, bool $custom): string
    {
        throw_if(blank(config('services.runway.key')), new \RuntimeException('Runway API is not configured.'));
        $model = (string) config($custom ? 'services.runway.custom_model' : 'services.runway.product_model');
        $payload = [
            'model' => $model,
            'promptText' => $prompt,
            'ratio' => config($custom ? 'services.runway.custom_ratio' : 'services.runway.product_ratio'),
            'duration' => (int) config('services.runway.duration', 5),
        ];
        if ($imageUrl) {
            $payload['promptImage'] = $imageUrl;
        }

        $response = $this->request()->post('/image_to_video', $payload)->throw()->json();
        $id = (string) ($response['id'] ?? '');
        throw_if($id === '', new \RuntimeException('Runway did not return a generation task ID.'));

        return $id;
    }

    public function task(string $taskId): array
    {
        return $this->request()->get('/tasks/'.$taskId)->throw()->json();
    }

    public function download(string $url): string
    {
        $response = Http::connectTimeout(10)->timeout(60)->get($url)->throw();
        $body = $response->body();
        throw_if($body === '', new \RuntimeException('Runway returned an empty video.'));

        return $body;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.runway.base_url'), '/'))
            ->withToken(config('services.runway.key'))
            ->withHeaders(['X-Runway-Version' => config('services.runway.api_version')])
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout(45);
    }
}
