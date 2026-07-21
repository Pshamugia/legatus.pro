<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\VerifiedCatalogResponder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CatalogPreflight extends Command
{
    protected $signature = 'legatus:preflight
        {agent : Agent ID or slug}
        {--query= : Real customer catalogue question}
        {--expect= : Product, author, title, or SKU that must be present}
        {--require-commerce : Fail unless a live commerce connector is active and synchronized}';

    protected $description = 'Prove that a tenant can answer a real verified catalogue question before enabling its widget';

    public function handle(VerifiedCatalogResponder $catalog): int
    {
        $agent = $this->resolveAgent();
        $activeProducts = $agent->products()->where('is_active', true)->count();
        $pricedProducts = $agent->products()->where('is_active', true)->where('price', '>', 0)->count();
        $connection = $agent->commerceConnection()->first();
        $commerceProducts = $connection
            ? $agent->products()->where('is_active', true)->where('commerce_connection_id', $connection->id)->count()
            : 0;

        $firstProduct = $agent->products()->where('is_active', true)->first();
        $query = trim((string) $this->option('query'));
        $expected = trim((string) $this->option('expect'));
        if ($query === '' && $firstProduct) {
            $query = (string) $firstProduct->name;
        }
        if ($expected === '' && $firstProduct) {
            $expected = (string) $firstProduct->name;
        }

        $checks = [
            ['Agent active', $agent->is_active, $agent->is_active ? 'yes' : 'no'],
            ['Active products', $activeProducts > 0, (string) $activeProducts],
            ['Products with verified price', $pricedProducts > 0, (string) $pricedProducts],
            ['Website widget', true, $agent->websiteWidgetEnabled() ? 'ON' : 'OFF (safe for testing)'],
        ];

        if ($this->option('require-commerce')) {
            $checks[] = ['Commerce connection active', $connection?->status === 'active', $connection?->status ?? 'missing'];
            $checks[] = ['Commerce sync completed', $connection?->last_sync_at !== null, $connection?->last_sync_at?->toDateTimeString() ?? 'never'];
            $checks[] = ['Live commerce products', $commerceProducts > 0, (string) $commerceProducts];
        }

        $reply = null;
        $replyError = null;
        if ($query !== '') {
            DB::beginTransaction();
            try {
                $conversation = $agent->conversations()->create([
                    'visitor_id' => 'preflight-'.Str::uuid(),
                    'channel' => 'eval',
                    'status' => 'ai',
                    'last_message_at' => now(),
                ]);
                $reply = $catalog->respond($agent, $conversation, $query);
            } catch (Throwable $exception) {
                $replyError = $exception->getMessage();
            } finally {
                DB::rollBack();
            }
        }

        $replyProducts = collect($reply['products'] ?? []);
        $searchableReply = Str::lower(collect([
            $reply['text'] ?? '',
            ...$replyProducts->flatMap(fn ($product) => [
                $product->name ?? data_get($product, 'name'),
                $product->sku ?? data_get($product, 'sku'),
                data_get($product, 'metadata.author'),
                $product->search_text ?? data_get($product, 'search_text'),
            ])->all(),
        ])->filter()->implode(' '));

        $checks[] = ['Catalogue question recognized', is_array($reply), $replyError ?: ($query !== '' ? $query : 'no query')];
        $checks[] = ['No false handoff', is_array($reply) && ! ($reply['handoff'] ?? true), ($reply['handoff'] ?? true) ? 'failed' : 'passed'];
        $checks[] = ['Matching products returned', $replyProducts->isNotEmpty(), (string) $replyProducts->count()];
        if ($expected !== '') {
            $checks[] = ['Expected result present', Str::contains($searchableReply, Str::lower($expected)), $expected];
        }

        $this->table(
            ['Check', 'Result', 'Evidence'],
            collect($checks)->map(fn (array $check): array => [$check[0], $check[1] ? 'PASS' : 'FAIL', $check[2]])->all(),
        );
        if (is_array($reply)) {
            $this->line('Reply: '.(string) ($reply['text'] ?? ''));
        }

        $healthy = collect($checks)->every(fn (array $check): bool => (bool) $check[1]);
        $this->{$healthy ? 'info' : 'error'}($healthy
            ? 'Catalog golden path passed. It is safe to proceed to controlled widget testing.'
            : 'Catalog golden path failed. Keep the website widget OFF and fix the failed checks.');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    private function resolveAgent(): Agent
    {
        $selector = (string) $this->argument('agent');

        return ctype_digit($selector)
            ? Agent::findOrFail((int) $selector)
            : Agent::where('slug', $selector)->firstOrFail();
    }
}
