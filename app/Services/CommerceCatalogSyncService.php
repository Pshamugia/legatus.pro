<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\CommerceConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommerceCatalogSyncService
{
    public function __construct(private CommerceConnectorClient $client) {}

    public function sync(CommerceConnection $connection): array
    {
        if (! $connection->exists) {
            throw new \InvalidArgumentException('A saved commerce connection is required for synchronization.');
        }

        return $this->withAgentLock((int) $connection->agent_id, function () use ($connection): array {
            try {
                return $this->applySnapshot($connection, $this->inspect($connection));
            } catch (\Throwable $exception) {
                CommerceConnection::whereKey($connection->id)->update([
                    'status' => 'error',
                    'last_error' => Str::limit($exception->getMessage(), 1500),
                ]);
                throw $exception;
            }
        });
    }

    /**
     * Verify new credentials first, then atomically replace the active connection
     * and catalog. A bad URL or secret never destroys the last known-good setup.
     */
    public function connect(Agent $agent, array $attributes): array
    {
        if (! is_string($attributes['secret'] ?? null) || strlen($attributes['secret']) < 32) {
            throw new \InvalidArgumentException('The shared commerce secret must contain at least 32 characters.');
        }
        if (! is_string($attributes['key_id'] ?? null) || trim($attributes['key_id']) === '' || strlen($attributes['key_id']) > 120) {
            throw new \InvalidArgumentException('The commerce key identifier is invalid.');
        }
        $attributes['status'] = 'active';
        $attributes['last_error'] = null;

        return $this->withAgentLock((int) $agent->id, function () use ($agent, $attributes): array {
            $staged = new CommerceConnection($attributes);
            $staged->agent_id = $agent->id;
            $staged->setRelation('agent', $agent);
            $snapshot = $this->inspect($staged);

            return DB::transaction(function () use ($agent, $attributes, $snapshot): array {
                $connection = CommerceConnection::where('agent_id', $agent->id)->lockForUpdate()->first();
                if ($connection) {
                    $connection->update($attributes);
                } else {
                    $connection = $agent->commerceConnection()->create($attributes);
                }
                $connection->setRelation('agent', $agent);

                return $this->persistSnapshot($connection, $snapshot);
            });
        });
    }

    /** @return array{records: list<array>, seen_ids: list<string>} */
    public function inspect(CommerceConnection $connection): array
    {
        $items = [];
        $page = 1;
        $expectedLastPage = null;
        $expectedTotal = null;
        $maximumProducts = max(1, (int) config('legatus.commerce_max_catalog_products', 10_000));

        do {
            $payload = $this->client->catalog($connection, $page);
            $batch = $payload['data'] ?? null;
            $meta = $payload['meta'] ?? null;
            if (! is_array($batch) || ! array_is_list($batch) || ! is_array($meta)) {
                throw new \RuntimeException("Catalog page {$page} is not a valid paginated snapshot.");
            }

            if (($meta['sync_mode'] ?? null) !== 'authoritative_snapshot') {
                throw new \RuntimeException('The commerce connector must explicitly return an authoritative_snapshot catalog.');
            }

            $currentPage = $this->integer($meta['current_page'] ?? null, 'meta.current_page', 1);
            $lastPage = $this->integer($meta['last_page'] ?? null, 'meta.last_page', 1);
            $total = $this->integer($meta['total'] ?? null, 'meta.total', 0);
            if ($currentPage !== $page) {
                throw new \RuntimeException("Catalog page sequence is incomplete: expected page {$page}, received {$currentPage}.");
            }
            if ($lastPage < $currentPage) {
                throw new \RuntimeException('Catalog pagination metadata is inconsistent.');
            }
            if ($lastPage > max(1, $total)) {
                throw new \RuntimeException('Catalog pagination requests more pages than its declared product total can contain.');
            }
            if ($total > $maximumProducts) {
                throw new \RuntimeException("Catalog contains {$total} products; the configured safe limit is {$maximumProducts}.");
            }

            if ($expectedLastPage === null) {
                $expectedLastPage = $lastPage;
                $expectedTotal = $total;
            } elseif ($expectedLastPage !== $lastPage || $expectedTotal !== $total) {
                throw new \RuntimeException('Catalog pagination totals changed while the snapshot was being read.');
            }

            if (count($items) + count($batch) > $maximumProducts) {
                throw new \RuntimeException("Catalog payload exceeded the configured safe limit of {$maximumProducts} products.");
            }

            $items = array_merge($items, $batch);
            $page++;
        } while ($page <= $expectedLastPage);

        if ($expectedTotal === null || count($items) !== $expectedTotal) {
            throw new \RuntimeException('The commerce connector returned a partial catalog snapshot.');
        }

        $prepared = [];
        $seen = [];
        $seenIds = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new \RuntimeException('Catalog record '.($index + 1).' is malformed.');
            }

            $record = $this->validatedRecord($item, $index);
            $deduplicationKey = 'id:'.$record['external_id'];
            if (isset($seen[$deduplicationKey])) {
                throw new \RuntimeException("Catalog contains duplicate product id {$record['external_id']}.");
            }

            $seen[$deduplicationKey] = true;
            $seenIds[] = $record['external_id'];
            $prepared[] = $record;
        }

        if ($prepared === []) {
            throw new \RuntimeException('The commerce connector returned no valid catalog records.');
        }

        return ['records' => $prepared, 'seen_ids' => $seenIds];
    }

    /** @param array{records: list<array>, seen_ids: list<string>} $snapshot */
    private function applySnapshot(CommerceConnection $connection, array $snapshot): array
    {
        return DB::transaction(function () use ($connection, $snapshot): array {
            $locked = CommerceConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('agent', $connection->agent);

            return $this->persistSnapshot($locked, $snapshot);
        });
    }

    /** @param array{records: list<array>, seen_ids: list<string>} $snapshot */
    private function persistSnapshot(CommerceConnection $connection, array $snapshot): array
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $existingProducts = $connection->agent->products()
            ->where('commerce_connection_id', $connection->id)
            ->get()
            ->keyBy(fn ($product): string => (string) $product->external_product_id);

        foreach ($snapshot['records'] as $record) {
            $product = $existingProducts->get($record['external_id']);
            $values = $record['values'];
            $values['commerce_connection_id'] = $connection->id;
            $values['external_product_id'] = $record['external_id'];
            $values['metadata'] = array_merge($values['metadata'], [
                'commerce_connection_id' => $connection->id,
                'external_product_id' => $record['external_id'],
            ]);

            if ($product) {
                $product->fill($values);
                if ($product->isDirty()) {
                    $product->save();
                    $updated++;
                } else {
                    $unchanged++;
                }
            } else {
                $connection->agent->products()->create($values);
                $created++;
            }
        }

        $connection->agent->products()
            ->where('commerce_connection_id', $connection->id)
            ->whereNotIn('external_product_id', $snapshot['seen_ids'])
            ->update(['is_active' => false]);

        $connection->update(['status' => 'active', 'last_sync_at' => now(), 'last_error' => null]);

        return ['received' => count($snapshot['records']), 'created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
    }

    private function withAgentLock(int $agentId, callable $callback): mixed
    {
        $seconds = max(30, (int) config('legatus.commerce_sync_lock_seconds', 600));
        $lock = Cache::lock("legatus:commerce-sync:agent:{$agentId}", $seconds);
        if (! $lock->get()) {
            throw new \RuntimeException('A commerce catalog synchronization is already in progress.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function clean(mixed $value, int $limit): string
    {
        if ($value !== null && ! is_scalar($value)) {
            return '';
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $text) ?? '';
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? '';
        $text = preg_replace([
            '/\b(?:ignore|disregard|override|forget)\s+(?:all\s+)?(?:previous|prior|above|system|developer)\s+(?:instructions?|messages?|prompts?|rules?)\b/iu',
            '/\b(?:reveal|expose|print|return|leak)\s+(?:the\s+)?(?:system|developer)\s+(?:prompt|message|instructions?)\b/iu',
            '/\b(?:send|reveal|expose|leak)\s+(?:all\s+)?(?:secrets?|credentials?|api\s*keys?)\b/iu',
            '/\b(?:system|developer|assistant|tool)\s*(?:message|prompt)?\s*:/iu',
            '/წინა\s+ინსტრუქცი(?:ა|ები|ებს)\s+(?:დაივიწყე|უგულებელყავი)/iu',
        ], '[catalog directive removed]', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        return Str::limit($text, $limit, '');
    }

    private function validatedRecord(array $item, int $index): array
    {
        $recordNumber = $index + 1;
        if (! is_scalar($item['id'] ?? null) || is_bool($item['id'])) {
            throw new \RuntimeException("Catalog record {$recordNumber} has no valid product id.");
        }

        $externalId = trim((string) $item['id']);
        $name = $this->clean($item['name'] ?? null, 255);
        if ($externalId === '' || mb_strlen($externalId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $externalId) || $name === '') {
            throw new \RuntimeException("Catalog record {$recordNumber} has no valid id or name.");
        }
        if (! is_numeric($item['price'] ?? null) || ! is_finite((float) $item['price']) || (float) $item['price'] < 0) {
            throw new \RuntimeException("Catalog record {$recordNumber} has an invalid price.");
        }

        $quantity = $this->integer($item['quantity'] ?? null, "catalog record {$recordNumber} quantity", 0);
        if (! is_bool($item['in_stock'] ?? null) || ! is_bool($item['purchasable'] ?? null)) {
            throw new \RuntimeException("Catalog record {$recordNumber} has invalid availability flags.");
        }

        $currency = strtoupper($this->clean($item['currency'] ?? 'GEL', 3));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \RuntimeException("Catalog record {$recordNumber} has an invalid currency.");
        }

        $descriptionParts = [$item['author'] ?? null, $item['description'] ?? null, $item['details'] ?? null];

        return [
            'external_id' => $externalId,
            'values' => [
                'name' => $name,
                'sku' => $this->clean($item['sku'] ?? null, 255) ?: null,
                'category' => $this->clean($item['category'] ?? null, 255) ?: null,
                'description' => $this->clean(implode(' ', array_filter($descriptionParts, 'is_scalar')), 4000) ?: null,
                'price' => (float) $item['price'],
                'stock' => $quantity,
                'image' => $this->safeHttpsUrl($item['image_url'] ?? null),
                // Presence in a complete authoritative snapshot means visible. Stock
                // and purchasability remain separate verified facts.
                'is_active' => true,
                'metadata' => [
                    'url' => $this->safeHttpsUrl($item['url'] ?? null),
                    'author' => $this->clean($item['author'] ?? null, 255) ?: null,
                    'genres' => array_values(array_filter(array_map(fn ($value) => is_scalar($value) ? $this->clean($value, 120) : '', (array) ($item['genres'] ?? [])))),
                    'language' => $this->clean($item['language'] ?? null, 20) ?: null,
                    'condition' => $this->clean($item['condition'] ?? null, 100) ?: null,
                    'currency' => $currency,
                    'in_stock' => $item['in_stock'],
                    'purchasable' => $item['purchasable'],
                    'text_trust' => 'untrusted_catalog_data',
                    'remote_updated_at' => is_scalar($item['updated_at'] ?? null) ? (string) $item['updated_at'] : null,
                ],
            ],
        ];
    }

    private function integer(mixed $value, string $field, int $minimum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value)) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($validated === false) {
                throw new \RuntimeException("{$field} must be an integer.");
            }
            $integer = $validated;
        } else {
            throw new \RuntimeException("{$field} must be an integer.");
        }

        if ($integer < $minimum) {
            throw new \RuntimeException("{$field} is outside the allowed range.");
        }

        return $integer;
    }

    private function safeHttpsUrl(mixed $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : null;
    }
}
