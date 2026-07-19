<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EmbeddingService
{
    public function embedSource(KnowledgeSource $source): void
    {
        if (! config('services.openai.key')) {
            return;
        }

        $source->chunks()->whereNull('embedding')->chunkById(50, function ($chunks) {
            $vectors = $this->embed($chunks->pluck('content')->all());
            if (count($vectors) !== $chunks->count()) {
                throw new \RuntimeException('The embedding provider returned an incomplete batch.');
            }
            foreach ($chunks->values() as $i => $chunk) {
                $chunk->update(['embedding' => $vectors[$i]]);
            }
        });
    }

    public function semanticSearch(Agent $agent, string $query, int $limit = 5): array
    {
        if (! config('services.openai.key')) {
            return [];
        }

        $queryVector = $this->embed([$query])[0] ?? null;
        if (! $queryVector) {
            return [];
        }

        $candidateLimit = max(50, min(5000, (int) config('legatus.semantic_candidate_limit', 2000)));

        return KnowledgeChunk::where('agent_id', $agent->id)
            ->whereNotNull('embedding')
            ->latest('id')
            ->limit($candidateLimit)
            ->get()
            ->map(fn ($c) => ['chunk' => $c, 'score' => $this->cosine($queryVector, $c->embedding)])
            ->filter(fn ($item) => $item['score'] >= (float) config('legatus.semantic_similarity_threshold'))
            ->sortByDesc('score')->take($limit)
            ->map(fn ($x) => ['chunk_id' => $x['chunk']->id, 'kind' => $x['chunk']->kind, 'title' => $x['chunk']->title, 'excerpt' => Str::limit($x['chunk']->content, 700), 'metadata' => $x['chunk']->metadata, 'similarity' => round($x['score'], 4)])->values()->all();
    }

    private function embed(array $inputs): array
    {
        $response = Http::withToken(config('services.openai.key'))->timeout(45)->retry(3, 400)->post('https://api.openai.com/v1/embeddings', ['model' => config('services.openai.embedding_model'), 'input' => array_values($inputs), 'encoding_format' => 'float'])->throw()->json();

        return collect($response['data'] ?? [])->sortBy('index')->pluck('embedding')->all();
    }

    private function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] ** 2;
            $nb += $b[$i] ** 2;
        }

        return $na && $nb ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
