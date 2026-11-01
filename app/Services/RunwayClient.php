<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RunwayClient
{
    /**
     * @return array{creditBalance: int, tier: array<string, mixed>, usage: array<string, mixed>}
     */
    public function organization(): array
    {
        throw_if(blank(config('services.runway.key')), new \RuntimeException('Runway API is not configured.'));

        return $this->request()
            ->connectTimeout(3)
            ->timeout(8)
            ->get('/organization')
            ->throw()
            ->json();
    }

    public function create(string $prompt, ?string $imageUrl, int $duration = 5): string
    {
        throw_if(blank(config('services.runway.key')), new \RuntimeException('Runway API is not configured.'));
        throw_unless(in_array($duration, [5, 10, 15], true), new \InvalidArgumentException('Unsupported Reel duration.'));
        $payload = [
            'version' => config('services.runway.multi_shot_version', '2026-06'),
            'mode' => 'auto',
            'prompt' => $prompt,
            'ratio' => config('services.runway.multi_shot_ratio', '720:1280'),
            'duration' => $duration,
            'audio' => false,
        ];
        if ($imageUrl) {
            $payload['firstFrame'] = ['uri' => $imageUrl];
        }

        $response = $this->request()
            ->retry(3, 750, fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()))
            ->post('/recipes/multi_shot_video', $payload)
            ->throw()
            ->json();
        $id = (string) ($response['id'] ?? '');
        throw_if($id === '', new \RuntimeException('Runway did not return a generation task ID.'));

        return $id;
    }

    /** @param list<string> $referenceImages */
    public function createWithReferences(string $prompt, string $firstImage, array $referenceImages, int $duration = 5, string $imageInstructions = ''): string
    {
        throw_if(blank(config('services.runway.key')), new \RuntimeException('Runway API is not configured.'));
        throw_unless(in_array($duration, [5, 10, 15], true), new \InvalidArgumentException('Unsupported Reel duration.'));
        throw_unless(count($referenceImages) >= 1 && count($referenceImages) <= 4, new \InvalidArgumentException('Choose one to four additional reference images.'));

        $visualInstruction = '<<<image_1>>> is the first uploaded image and opening frame. ';
        foreach ($referenceImages as $index => $reference) {
            $number = $index + 2;
            $visualInstruction .= "<<<image_{$number}>>> is uploaded image {$number}. ";
        }
        $visualInstruction .= 'Use each image for the role specified by the business. Preserve the actual appearance of every referenced person or product; do not invent replacement artwork or legible text. ';
        if (trim($imageInstructions) !== '') {
            $visualInstruction .= 'Business instructions for the numbered images: '.Str::limit(trim(strip_tags($imageInstructions)), 1100, '').'. ';
        }
        $payload = [
            'model' => 'klingO3_standard',
            'promptImage' => $firstImage,
            'references' => array_map(fn (string $uri): array => ['uri' => $uri], $referenceImages),
            'promptText' => Str::limit(trim($visualInstruction.$prompt), 2400, ''),
            'ratio' => '720:1280',
            'duration' => $duration,
            'audio' => false,
        ];
        $response = $this->request()
            ->retry(3, 750, fn (\Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && $exception->response->serverError()))
            ->post('/image_to_video', $payload)
            ->throw()
            ->json();
        $id = (string) ($response['id'] ?? '');
        throw_if($id === '', new \RuntimeException('Runway did not return a generation task ID.'));

        return $id;
    }

    public function task(string $taskId): array
    {
        return $this->request()->get('/tasks/'.$taskId)->throw()->json();
    }

    public function cancel(string $taskId): void
    {
        $this->request()->delete('/tasks/'.$taskId)->throw();
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
