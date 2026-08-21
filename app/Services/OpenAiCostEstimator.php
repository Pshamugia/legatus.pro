<?php

namespace App\Services;

class OpenAiCostEstimator
{
    /**
     * @return array{usd: float, requests: int, luna_requests: int, sol_requests: int, unpriced_models: array<int, string>}
     */
    public function estimate(object|array $run): array
    {
        $usage = $this->value($run, 'model_usage');
        if (is_string($usage)) {
            $usage = json_decode($usage, true);
        }

        if (! is_array($usage) || $usage === []) {
            $usage = [[
                'model' => (string) $this->value($run, 'model'),
                'requests' => 1,
                'input_tokens' => (int) $this->value($run, 'input_tokens'),
                'cached_input_tokens' => 0,
                'cache_write_tokens' => 0,
                'output_tokens' => (int) $this->value($run, 'output_tokens'),
            ]];
        }

        $summary = [
            'usd' => 0.0,
            'requests' => 0,
            'luna_requests' => 0,
            'sol_requests' => 0,
            'unpriced_models' => [],
        ];

        foreach ($usage as $item) {
            if (! is_array($item)) {
                continue;
            }

            $model = (string) ($item['model'] ?? '');
            $requests = max(0, (int) ($item['requests'] ?? 0));
            $summary['requests'] += $requests;
            $summary['luna_requests'] += str_contains($model, 'luna') ? $requests : 0;
            $summary['sol_requests'] += str_contains($model, 'sol') ? $requests : 0;

            $rates = $this->ratesFor($model);
            if ($rates === null) {
                if ($model !== '') {
                    $summary['unpriced_models'][] = $model;
                }
                continue;
            }

            $input = max(0, (int) ($item['input_tokens'] ?? 0));
            $cached = min($input, max(0, (int) ($item['cached_input_tokens'] ?? 0)));
            $cacheWrite = min($input - $cached, max(0, (int) ($item['cache_write_tokens'] ?? 0)));
            $uncached = max(0, $input - $cached - $cacheWrite);
            $output = max(0, (int) ($item['output_tokens'] ?? 0));

            $summary['usd'] += (
                ($uncached * $rates['input'])
                + ($cached * $rates['cached_input'])
                + ($cacheWrite * $rates['cache_write'])
                + ($output * $rates['output'])
            ) / 1_000_000;
        }

        $summary['unpriced_models'] = array_values(array_unique($summary['unpriced_models']));

        return $summary;
    }

    /** @return array{input: float, cached_input: float, cache_write: float, output: float}|null */
    private function ratesFor(string $model): ?array
    {
        $models = (array) config('openai_costs.models', []);
        if (isset($models[$model])) {
            return $models[$model];
        }

        foreach ($models as $prefix => $rates) {
            if (str_starts_with($model, $prefix.'-')) {
                return $rates;
            }
        }

        return null;
    }

    private function value(object|array $run, string $key): mixed
    {
        if (is_array($run)) {
            return $run[$key] ?? null;
        }

        return $run->{$key} ?? null;
    }
}
