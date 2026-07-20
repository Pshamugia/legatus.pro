<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Services\EmbeddingService;
use App\Services\KnowledgeIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.key' => null]);
    }

    public function test_csv_catalog_is_normalized_and_deduplicated(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $file = UploadedFile::fake()->createWithContent('catalog.csv', "name,sku,category,description,price,stock\nNew Book,NEW-1,Fiction,A thoughtful novel,24.90,8\nPiranesi,BK-1001,Magic,Updated copy,28.00,9\n");
        $service = app(KnowledgeIngestionService::class);
        $source = $agent->knowledgeSources()->create(['type' => 'csv', 'name' => 'Test catalog', 'file_path' => $service->storeFile($file, 'csv')]);
        $service->ingest($source);
        $this->assertSame('ready', $source->fresh()->status);
        $this->assertSame(1, $source->fresh()->items_created);
        $this->assertSame(1, $source->fresh()->items_updated);
        $this->assertDatabaseHas('products', ['sku' => 'NEW-1', 'stock' => 8]);
        $this->assertDatabaseHas('products', ['sku' => 'BK-1001', 'stock' => 9]);
        $this->assertSame(2, $source->chunks()->count());
    }

    public function test_csv_handles_utf8_bom_semicolon_delimiter_and_localized_numbers(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $csv = "\xEF\xBB\xBFname;sku;price;stock\nEuropean Format;EU-1;\"1.299,50 ₾\";\"1 200\"\nUS Format;US-1;\"$1,299.50\";5\n";
        $file = UploadedFile::fake()->createWithContent('localized.csv', $csv);
        $service = app(KnowledgeIngestionService::class);
        $source = $agent->knowledgeSources()->create(['type' => 'csv', 'name' => 'Localized catalog', 'file_path' => $service->storeFile($file, 'csv')]);

        $service->ingest($source);

        $this->assertDatabaseHas('products', ['sku' => 'EU-1', 'price' => 1299.50, 'stock' => 1200]);
        $this->assertDatabaseHas('products', ['sku' => 'US-1', 'price' => 1299.50, 'stock' => 5]);
        $this->assertSame(2, $source->chunks()->count());
    }

    public function test_csv_rejects_non_positive_prices_and_normalizes_negative_stock(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $file = UploadedFile::fake()->createWithContent('validated.csv', "name,sku,price,stock\nMissing Price,BAD-0,0,5\nNegative Price,BAD-1,-10,2\nValid Product,GOOD-1,19.90,-3\n");
        $service = app(KnowledgeIngestionService::class);
        $source = $agent->knowledgeSources()->create(['type' => 'csv', 'name' => 'Validated catalog', 'file_path' => $service->storeFile($file, 'csv')]);

        $service->ingest($source);

        $this->assertSame(1, $source->fresh()->items_found);
        $this->assertDatabaseMissing('products', ['sku' => 'BAD-0']);
        $this->assertDatabaseMissing('products', ['sku' => 'BAD-1']);
        $this->assertDatabaseHas('products', ['sku' => 'GOOD-1', 'price' => 19.90, 'stock' => 0]);
    }

    public function test_failed_refresh_preserves_last_known_good_source_and_chunks(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $lastSyncedAt = now()->subHour()->startOfSecond();
        $source = $agent->knowledgeSources()->create([
            'type' => 'csv',
            'name' => 'Stable catalog',
            'file_path' => 'knowledge/csv/missing.csv',
            'status' => 'ready',
            'progress' => 100,
            'items_found' => 1,
            'content_hash' => 'known-good',
            'last_synced_at' => $lastSyncedAt,
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id,
            'kind' => 'policy',
            'title' => 'Known good',
            'content' => 'This trusted content must remain searchable after a failed refresh.',
            'content_hash' => hash('sha256', 'known-good-chunk'),
        ]);

        try {
            app(KnowledgeIngestionService::class)->ingest($source);
            $this->fail('A missing source file should fail ingestion.');
        } catch (\Throwable) {
            // The refresh failed as expected; assertions below verify rollback behavior.
        }

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(100, $source->progress);
        $this->assertSame('known-good', $source->content_hash);
        $this->assertTrue($lastSyncedAt->equalTo($source->last_synced_at));
        $this->assertNotNull($source->error);
        $this->assertSame(1, $source->chunks()->count());
        $this->assertSame('Known good', $source->chunks()->value('title'));
    }

    public function test_header_only_csv_refresh_rolls_back_the_last_known_good_catalog(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $service = app(KnowledgeIngestionService::class);
        $file = UploadedFile::fake()->createWithContent('empty.csv', "name,sku,price,stock\n");
        $source = $agent->knowledgeSources()->create([
            'type' => 'csv',
            'name' => 'Stable catalog',
            'file_path' => $service->storeFile($file, 'csv'),
            'status' => 'ready',
            'progress' => 100,
            'items_found' => 1,
            'content_hash' => 'stable-catalog',
            'last_synced_at' => now()->subHour(),
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id,
            'kind' => 'product',
            'title' => 'Existing product',
            'content' => 'Existing product costs 19.90 GEL and has five units.',
            'content_hash' => hash('sha256', 'existing-product'),
        ]);
        $product = $agent->products()->create([
            'name' => 'Existing product',
            'sku' => 'STABLE-1',
            'price' => 19.90,
            'stock' => 5,
            'is_active' => true,
            'metadata' => ['source_id' => $source->id],
        ]);

        try {
            $service->ingest($source);
            $this->fail('A header-only CSV must not replace a working catalog.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('valid product rows', $exception->getMessage());
        }

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(100, $source->progress);
        $this->assertSame('stable-catalog', $source->content_hash);
        $this->assertSame(1, $source->chunks()->count());
        $this->assertSame('Existing product', $source->chunks()->value('title'));
        $this->assertTrue($product->fresh()->is_active);
        $this->assertNotNull($source->error);
    }

    public function test_blank_url_refresh_is_rejected_without_losing_the_last_known_good_content(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Stable website',
            'url' => null,
            'status' => 'ready',
            'progress' => 100,
            'items_found' => 1,
            'content_hash' => 'stable-website',
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id,
            'kind' => 'webpage',
            'title' => 'Existing website knowledge',
            'content' => 'Existing verified website content remains available.',
            'content_hash' => hash('sha256', 'existing-website-content'),
        ]);

        try {
            app(KnowledgeIngestionService::class)->ingest($source);
            $this->fail('A blank website URL must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('URL is required', $exception->getMessage());
        }

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame('stable-website', $source->content_hash);
        $this->assertSame('Existing website knowledge', $source->chunks()->value('title'));
        $this->assertNotNull($source->error);
    }

    public function test_empty_website_refresh_rolls_back_instead_of_publishing_zero_useful_content(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        Http::fake(['https://example.com/empty' => Http::response('<html><body> </body></html>')]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Stable website',
            'url' => 'https://example.com/empty',
            'status' => 'ready',
            'progress' => 100,
            'items_found' => 1,
            'content_hash' => 'known-good-website',
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id,
            'kind' => 'webpage',
            'title' => 'Known good page',
            'content' => 'This useful page must survive an empty refresh response.',
            'content_hash' => hash('sha256', 'known-good-page'),
        ]);

        try {
            app(KnowledgeIngestionService::class)->ingest($source);
            $this->fail('An empty website must not replace the last-known-good version.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('accepted products or searchable content', $exception->getMessage());
        }

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame('known-good-website', $source->content_hash);
        $this->assertSame('Known good page', $source->chunks()->value('title'));
        $this->assertNotNull($source->error);
    }

    public function test_url_ingestion_counts_only_json_ld_products_that_are_accepted(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $html = <<<'HTML'
<html><body><p>Orders can be returned within fourteen days after delivery.</p>
<script type="application/ld+json">
[
  {"@type":"Product","name":"Accepted Book","sku":"JSON-OK","offers":{"price":"21.90","priceCurrency":"GEL","availability":"https://schema.org/InStock"}},
  {"@type":"Product","name":"Wrong Currency","sku":"JSON-USD","offers":{"price":"18.00","priceCurrency":"USD","availability":"https://schema.org/InStock"}},
  {"@type":"Product","name":"Missing Price","sku":"JSON-NO-PRICE","offers":{"priceCurrency":"GEL","availability":"https://schema.org/InStock"}}
]
</script></body></html>
HTML;
        Http::fake(['https://example.com/catalog' => Http::response($html)]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'JSON-LD catalog',
            'url' => 'https://example.com/catalog',
        ]);

        app(KnowledgeIngestionService::class)->ingest($source);

        $source->refresh();
        $this->assertSame(1, $source->items_found);
        $this->assertSame(1, $source->items_created);
        $this->assertSame(0, $source->items_updated);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'JSON-OK']);
        $this->assertDatabaseMissing('products', ['agent_id' => $agent->id, 'sku' => 'JSON-USD']);
        $this->assertDatabaseMissing('products', ['agent_id' => $agent->id, 'sku' => 'JSON-NO-PRICE']);
        $this->assertSame(2, $source->chunks()->count());
    }

    public function test_json_catalog_url_imports_universal_commerce_envelope_and_safe_fields(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        Http::fake(['https://example.com/products.json' => Http::response([
            'data' => [
                [
                    'id' => 77,
                    'title' => 'Universal Catalog Book',
                    'category' => ['name' => 'Books'],
                    'description' => '<script>bad()</script><b>A verified description</b>',
                    'price' => ['amount' => '27.50', 'currency' => 'GEL'],
                    'quantity' => '7',
                    'image' => ['url' => 'https://example.com/book.jpg'],
                    'url' => 'https://example.com/books/77',
                ],
                [
                    'id' => 'BOOK-78',
                    'sku' => 'SKU-78',
                    'name' => 'Availability Book',
                    'price' => 19.90,
                    'currency' => 'gel',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
            'meta' => [
                'sync_mode' => 'authoritative_snapshot',
                'current_page' => 1,
                'last_page' => 1,
                'total' => 2,
            ],
        ])]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'JSON catalog',
            'url' => 'https://example.com/products.json',
        ]);

        app(KnowledgeIngestionService::class)->ingest($source);

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(2, $source->items_found);
        $this->assertSame(2, $source->items_created);
        $this->assertSame(2, $source->chunks()->where('kind', 'product')->count());
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'sku' => '77',
            'name' => 'Universal Catalog Book',
            'category' => 'Books',
            'price' => 27.50,
            'stock' => 7,
            'image' => 'https://example.com/book.jpg',
        ]);
        $product = $agent->products()->where('sku', '77')->firstOrFail();
        $this->assertSame('A verified description', $product->description);
        $this->assertSame('https://example.com/books/77', $product->metadata['product_url']);
        $this->assertSame('untrusted_catalog_data', $product->metadata['text_trust']);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'SKU-78', 'stock' => 1]);
    }

    public function test_json_catalog_skips_missing_prices_invalid_rows_and_other_currencies(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        Http::fake(['https://example.com/items.json' => Http::response([
            'products' => [
                ['id' => 'GOOD-1', 'name' => 'Valid Product', 'price' => '24,90 ₾', 'stock' => 4],
                ['id' => 'NO-PRICE', 'name' => 'Missing Price', 'quantity' => 2],
                ['id' => 'USD-1', 'name' => 'Wrong Currency', 'price' => 18, 'currency' => 'USD', 'stock' => 1],
                ['id' => 'BAD-CURRENCY', 'name' => 'Invalid Currency', 'price' => 18, 'currency' => 'GE', 'stock' => 1],
                ['id' => 'BAD-STOCK', 'name' => 'Invalid Stock', 'price' => 18, 'currency' => 'GEL', 'stock' => 'many'],
                ['id' => 'SYMBOL-USD', 'name' => 'Dollar Price', 'price' => '$19.90', 'stock' => 1],
            ],
        ])]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Validated JSON catalog',
            'url' => 'https://example.com/items.json',
        ]);

        app(KnowledgeIngestionService::class)->ingest($source);

        $this->assertSame(1, $source->fresh()->items_found);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'GOOD-1', 'price' => 24.90, 'stock' => 4]);
        foreach (['NO-PRICE', 'USD-1', 'BAD-CURRENCY', 'BAD-STOCK', 'SYMBOL-USD'] as $sku) {
            $this->assertDatabaseMissing('products', ['agent_id' => $agent->id, 'sku' => $sku]);
        }
    }

    public function test_json_catalog_refresh_updates_products_deactivates_missing_rows_and_rolls_back_failures(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        Http::fakeSequence()
            ->push(['items' => [
                ['id' => 'REFRESH-A', 'name' => 'Refresh A', 'price' => 20, 'quantity' => 2],
                ['id' => 'REFRESH-B', 'name' => 'Refresh B', 'price' => 30, 'quantity' => 3],
            ]])
            ->push(['products' => [
                ['id' => 'REFRESH-A', 'name' => 'Refresh A updated', 'price' => 22.50, 'quantity' => 8],
            ]])
            ->push('{"items":', 200, ['Content-Type' => 'application/json']);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Refreshable JSON catalog',
            'url' => 'https://example.com/refresh.json',
        ]);
        $service = app(KnowledgeIngestionService::class);

        $service->ingest($source);
        $service->ingest($source->fresh());

        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'sku' => 'REFRESH-A',
            'name' => 'Refresh A updated',
            'price' => 22.50,
            'stock' => 8,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'REFRESH-B', 'is_active' => false]);
        $this->assertSame(0, $source->fresh()->items_created);
        $this->assertSame(1, $source->fresh()->items_updated);
        $lastKnownHash = $source->fresh()->content_hash;

        try {
            $service->ingest($source->fresh());
            $this->fail('Malformed JSON must not replace a working catalog.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid JSON', $exception->getMessage());
        }

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame($lastKnownHash, $source->content_hash);
        $this->assertNotNull($source->error);
        $this->assertTrue($agent->products()->where('sku', 'REFRESH-A')->firstOrFail()->is_active);
        $this->assertFalse($agent->products()->where('sku', 'REFRESH-B')->firstOrFail()->is_active);
        $this->assertSame('Refresh A updated', $source->chunks()->where('kind', 'product')->value('title'));
    }

    public function test_json_catalog_enforces_the_configured_item_limit(): void
    {
        $this->seed();
        config(['legatus.commerce_max_catalog_products' => 1]);
        $agent = Agent::firstOrFail();
        Http::fake(['https://example.com/large.json' => Http::response(['data' => [
            ['id' => 'LIMIT-1', 'name' => 'First', 'price' => 10],
            ['id' => 'LIMIT-2', 'name' => 'Second', 'price' => 12],
        ]])]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Too large JSON catalog',
            'url' => 'https://example.com/large.json',
        ]);

        try {
            app(KnowledgeIngestionService::class)->ingest($source);
            $this->fail('An oversized catalog must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('safe limit is 1', $exception->getMessage());
        }

        $this->assertSame('failed', $source->fresh()->status);
        $this->assertSame(0, $agent->products()->whereIn('sku', ['LIMIT-1', 'LIMIT-2'])->count());
    }

    public function test_embedding_batches_do_not_skip_chunks_after_updates(): void
    {
        $this->seed();
        config(['services.openai.key' => 'test-key', 'services.openai.embedding_model' => 'test-embedding-model']);
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create(['type' => 'pdf', 'name' => 'Large source']);
        foreach (range(1, 125) as $index) {
            $source->chunks()->create([
                'agent_id' => $agent->id,
                'kind' => 'policy',
                'title' => "Chunk {$index}",
                'content' => "Searchable content for chunk {$index}.",
                'content_hash' => hash('sha256', "chunk-{$index}"),
            ]);
        }
        Http::fake(function ($request) {
            $inputs = $request->data()['input'];

            return Http::response([
                'data' => collect($inputs)->values()->map(fn ($input, $index) => [
                    'index' => $index,
                    'embedding' => [1.0, (float) mb_strlen($input)],
                ])->all(),
            ]);
        });

        app(EmbeddingService::class)->embedSource($source);

        $this->assertSame(125, $source->chunks()->whereNotNull('embedding')->count());
        Http::assertSentCount(3);
    }

    public function test_private_network_url_is_rejected(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create(['type' => 'url', 'name' => 'Unsafe', 'url' => 'http://127.0.0.1/private']);
        $this->expectException(\InvalidArgumentException::class);
        app(KnowledgeIngestionService::class)->ingest($source);
    }

    public function test_public_url_cannot_redirect_to_a_private_network(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        Http::fake(['https://example.com/start' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        $source = $agent->knowledgeSources()->create(['type' => 'url', 'name' => 'Redirect attack', 'url' => 'https://example.com/start']);

        $this->expectException(\InvalidArgumentException::class);
        app(KnowledgeIngestionService::class)->ingest($source);
    }

    public function test_knowledge_screen_is_available(): void
    {
        $this->seed();
        $this->actingAs(User::first());
        $this->get('/app/knowledge')->assertOk()->assertSee('Knowledge sources');
    }

    public function test_deleting_uploaded_source_removes_only_its_managed_file(): void
    {
        Storage::fake('local');
        $this->seed();
        $user = User::firstOrFail();
        $agent = Agent::firstOrFail();
        Storage::disk('local')->put('knowledge/csv/catalog.csv', 'name,price');
        Storage::disk('local')->put('outside.csv', 'must remain');
        $uploaded = $agent->knowledgeSources()->create([
            'type' => 'csv',
            'name' => 'Uploaded catalog',
            'file_path' => 'knowledge/csv/catalog.csv',
        ]);
        $unsafe = $agent->knowledgeSources()->create([
            'type' => 'csv',
            'name' => 'Unsafe path',
            'file_path' => '../outside.csv',
        ]);

        $this->actingAs($user)->delete(route('knowledge.destroy', $uploaded))->assertRedirect();
        Storage::disk('local')->assertMissing('knowledge/csv/catalog.csv');

        $this->actingAs($user)->delete(route('knowledge.destroy', $unsafe))->assertRedirect();
        Storage::disk('local')->assertExists('outside.csv');
        $this->assertDatabaseMissing('knowledge_sources', ['id' => $uploaded->id]);
        $this->assertDatabaseMissing('knowledge_sources', ['id' => $unsafe->id]);
    }

    public function test_sync_command_skips_synthetic_sources_without_payload(): void
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->knowledgeSources()->delete();
        $source = $agent->knowledgeSources()->create([
            'type' => 'csv',
            'name' => 'Generated demo snapshot',
            'status' => 'ready',
            'progress' => 100,
        ]);

        $this->artisan('legatus:sync-knowledge')
            ->expectsOutput("Skipped snapshot #{$source->id} {$source->name}")
            ->assertSuccessful();

        $this->assertSame('ready', $source->fresh()->status);
    }

    public function test_seeded_fixture_sources_are_static_labeled_and_embedding_honest(): void
    {
        $this->seed();
        $agent = Agent::where('slug', 'legatus-demo')->firstOrFail();
        $user = User::where('email', 'demo@legatus.ai')->firstOrFail();
        $sources = $agent->knowledgeSources()->with('chunks')->get();

        $this->assertCount(3, $sources);
        $this->assertTrue($sources->every(fn ($source) => ! $source->isRefreshable()));
        $this->assertSame(0, $sources->flatMap->chunks->whereNotNull('embedding')->count());

        $response = $this->actingAs($user)->get(route('knowledge.index'))
            ->assertOk()
            ->assertSee('Demo fixture snapshot')
            ->assertSee('Static fixture · no source payload')
            ->assertSee('Lexical search only · no embeddings stored')
            ->assertSee('0 embedded')
            ->assertDontSee('↻ Sync')
            ->assertDontSee('<span class="pill">ready</span>', false);

        foreach ($sources as $source) {
            $response->assertDontSee(route('knowledge.sync', $source), false);
        }

        $source = $sources->first();
        $this->post(route('knowledge.sync', $source))
            ->assertRedirect()
            ->assertSessionHas('success')
            ->assertSessionMissing('error');
        $this->assertSame('ready', $source->fresh()->status);
        $this->assertNull($source->fresh()->error);
    }
}
