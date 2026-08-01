<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\KnowledgeIngestionService;
use App\Services\PublicStorefrontCatalog;
use App\Services\SalesAgentService;
use App\Services\SalesToolbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicStorefrontCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['legatus.semantic_orchestration_enabled' => false]);
    }

    public function test_catalog_url_ingestion_accepts_public_product_cards_with_prices(): void
    {
        [$agent] = $this->context();
        $source = $agent->knowledgeSources()->firstOrFail();
        Http::fake([
            'https://bukinistebi.ge/books' => Http::response($this->searchCardsHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        app(KnowledgeIngestionService::class)->ingest($source);

        $this->assertSame('ready', $source->fresh()->status);
        $this->assertSame(1, $source->fresh()->items_found);
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'name' => 'საიუბილეო საარქივო გამოცემა',
            'price' => 14,
            'stock' => 1,
            'is_active' => true,
        ]);
    }

    public function test_named_taxonomy_source_enriches_every_imported_product_for_category_search(): void
    {
        [$agent] = $this->context();
        $source = $agent->knowledgeSources()->firstOrFail();
        $source->update(['name' => 'ჟანრი: თრილერი, მისტიკა']);
        Http::fake([
            'https://bukinistebi.ge/books' => Http::response($this->searchCardsHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        app(KnowledgeIngestionService::class)->ingest($source);

        $product = $agent->products()->firstOrFail();
        $this->assertSame([], data_get($product->metadata, 'genres'));
        $this->assertSame(['თრილერი', 'მისტიკა'], data_get($product->metadata, 'taxonomy'));
        $this->assertStringContainsString('თრილერი', $product->search_text);
        $this->assertStringContainsString('მისტიკა', $product->search_text);

        $categorySource = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'კატეგორია: ცომეული',
            'url' => 'https://bukinistebi.ge/books',
            'status' => 'ready',
        ]);
        app(KnowledgeIngestionService::class)->ingest($categorySource);

        $this->assertEqualsCanonicalizing(
            ['თრილერი', 'მისტიკა', 'ცომეული'],
            data_get($product->fresh()->metadata, 'taxonomy'),
        );
    }

    public function test_public_storefront_url_discovers_and_verifies_a_product_without_store_code(): void
    {
        [$agent, $conversation] = $this->context();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/search?title=')) {
                $this->assertStringContainsString('title=%E1%83%98%E1%83%90%E1%83%A8%E1%83%95%E1%83%98%E1%83%9A%E1%83%98', $request->url());

                return Http::response($this->searchCardsHtml(), 200, ['Content-Type' => 'text/html']);
            }

            if (str_contains($request->url(), '/books/paolo-iashvili/42')) {
                return Http::response('<div class="product-price"><strong>14 ₾</strong><span class="old-price">20 ₾</span></div>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response([], 404);
        });
        config(['services.openai.key' => 'must-not-be-called']);

        $reply = app(SalesAgentService::class)->reply($agent, 'იაშვილის რა გაქვთ?', $conversation);

        $this->assertFalse($reply['handoff']);
        $this->assertCount(1, $reply['products']);
        $this->assertStringContainsString('პაოლო იაშვილი', $reply['text']);
        $this->assertStringContainsString('20.00 ₾-ის ნაცვლად 14.00 ₾', $reply['text']);
        $this->assertStringContainsString('30% ფასდაკლება', $reply['text']);
        $this->assertStringContainsString('მარაგშია', $reply['text']);
        $this->assertStringNotContainsString('მარაგში 1 ც.', $reply['text']);
        $product = $agent->products()->firstOrFail();
        $check = app(SalesToolbox::class)->execute('check_stock', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $agent, $conversation);
        $this->assertSame('availability_only', $check['stock_precision']);
        $this->assertArrayNotHasKey('stock', $check);
        $this->assertDatabaseHas('products', [
            'agent_id' => $agent->id,
            'name' => 'საიუბილეო საარქივო გამოცემა',
            'price' => 14,
            'stock' => 1,
            'is_active' => true,
        ]);
        Http::assertSentCount(2);
    }

    public function test_public_search_never_follows_a_cross_origin_product_url(): void
    {
        [$agent, $conversation] = $this->context();
        Http::fake([
            'https://bukinistebi.ge/search?title=*' => Http::response('<html></html>'),
            'https://bukinistebi.ge/search/suggest*' => Http::response(['items' => [[
                'title' => 'Untrusted result',
                'author' => 'Unknown',
                'url' => 'https://attacker.example/books/stolen/1',
                'sold' => false,
            ]], 'didYouMean' => null]),
            '*' => Http::response([], 500),
        ]);

        $reply = app(SalesAgentService::class)->reply($agent, 'უცნობი წიგნი გაქვთ?', $conversation);

        $this->assertFalse($reply['handoff']);
        $this->assertSame([], $reply['products']);
        $this->assertDatabaseCount('products', 0);
        Http::assertSentCount(2);
    }

    public function test_public_search_relaxes_a_natural_question_to_its_strongest_product_phrase(): void
    {
        [$agent, $conversation] = $this->context();
        Http::fake(function ($request) {
            $url = rawurldecode($request->url());
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
            if (($parameters['title'] ?? null) === 'ივანე ჯავახიშვილი') {
                return Http::response(str_replace('პაოლო იაშვილი', 'ივანე ჯავახიშვილი', $this->searchCardsHtml()), 200, ['Content-Type' => 'text/html']);
            }
            if (str_contains($url, '/books/paolo-iashvili/42')) {
                return Http::response('<div class="product-price"><strong>14 ₾</strong></div>', 200, ['Content-Type' => 'text/html']);
            }

            return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
        });
        config(['services.openai.key' => 'must-not-be-called']);

        $reply = app(SalesAgentService::class)->reply($agent, 'ივანე ჯავახიშვილის მარტო ეს გაქვთ?', $conversation);

        $this->assertFalse($reply['handoff']);
        $this->assertCount(1, $reply['products']);
        $this->assertStringContainsString('საიუბილეო საარქივო გამოცემა', $reply['text']);
        $this->assertGreaterThanOrEqual(2, Http::recorded()->count());
    }

    public function test_public_search_rejects_unrelated_recommendation_cards_on_an_empty_result_page(): void
    {
        [$agent, $conversation] = $this->context();
        Http::fake([
            'https://bukinistebi.ge/search?title=*' => Http::response($this->searchCardsHtml(), 200, ['Content-Type' => 'text/html']),
            'https://bukinistebi.ge/search/suggest*' => Http::response(['items' => [], 'didYouMean' => null]),
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'პაულო კოელიოს',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertDatabaseMissing('products', [
            'agent_id' => $agent->id,
            'name' => 'საიუბილეო საარქივო გამოცემა',
        ]);
    }

    public function test_public_search_imports_dedicated_results_but_does_not_show_them_without_indexed_relevance(): void
    {
        [$agent, $conversation] = $this->context();
        $actualResult = str_replace(
            ['საიუბილეო საარქივო გამოცემა', 'პაოლო იაშვილი'],
            ['ნომერი პირველი', 'ოლივერ კანი'],
            $this->searchCardsHtml(),
        );
        $unrelatedRecommendation = str_replace(
            ['საიუბილეო საარქივო გამოცემა', 'პაოლო იაშვილი'],
            ['დათა თუთაშხია', 'ჭაბუა ამირეჯიბი'],
            $this->searchCardsHtml(),
        );
        Http::fake([
            'https://bukinistebi.ge/search?title=*' => Http::response(
                '<div id="search-results">'.$actualResult.'</div>'
                .'<section class="recommendations">'.$unrelatedRecommendation.'</section>',
                200,
                ['Content-Type' => 'text/html'],
            ),
            'https://bukinistebi.ge/books/paolo-iashvili/42' => Http::response('<div class="product-price"><strong>14 ₾</strong></div>'),
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'სპორტი',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'name' => 'ნომერი პირველი']);
        $this->assertDatabaseMissing('products', ['agent_id' => $agent->id, 'name' => 'დათა თუთაშხია']);
    }

    public function test_recommendation_searches_the_live_storefront_instead_of_using_only_the_cached_first_page(): void
    {
        [$agent, $conversation] = $this->context();
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
            if (str_contains($request->url(), '/search?title=')
                && in_array($parameters['title'] ?? null, ['ფილოსოფიური', 'ფილოსოფი', 'ფილოსოფია'], true)) {
                return Http::response(
                    str_replace(
                        ['საიუბილეო საარქივო გამოცემა', 'პაოლო იაშვილი'],
                        ['ფილოსოფიური ეტიუდები', 'შალვა ნუცუბიძე'],
                        $this->searchCardsHtml(),
                    ),
                    200,
                    ['Content-Type' => 'text/html'],
                );
            }
            if (str_contains($request->url(), '/books/paolo-iashvili/42')) {
                return Http::response('<div class="product-price"><strong>14 ₾</strong></div>');
            }

            return Http::response('<html></html>', 200, ['Content-Type' => 'text/html']);
        });

        $result = app(SalesToolbox::class)->execute('recommend_products', [
            'query' => 'ფილოსოფიური წიგნები მირჩიე',
            'budget' => null,
            'category' => null,
            'mood' => null,
            'occasion' => null,
            'limit' => 3,
        ], $agent, $conversation);

        $this->assertSame('ფილოსოფიური ეტიუდები', data_get($result, 'recommendations.0.name'));
        $this->assertSame('შალვა ნუცუბიძე', data_get($result, 'recommendations.0.author'));
        $this->assertGreaterThan(0, data_get($result, 'recommendations.0.score'));
    }

    public function test_unbuilt_named_category_reads_its_authoritative_url_before_general_site_search(): void
    {
        [$agent, $conversation] = $this->context();
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'source_scope' => 'category',
            'taxonomy_label' => 'დეტექტივი',
            'name' => 'Category: დეტექტივი',
            'url' => 'https://bukinistebi.ge/categories/detective',
            'status' => 'ready',
            'index_version' => 0,
        ]);
        $categoryHtml = str_replace(
            ['საიუბილეო საარქივო გამოცემა', 'პაოლო იაშვილი'],
            ['დეტექტიური ამბავი', 'ტესტ ავტორი'],
            $this->searchCardsHtml(),
        );
        Http::fake([
            'https://bukinistebi.ge/categories/detective' => Http::response($categoryHtml, 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $result = app(PublicStorefrontCatalog::class)->discoverCategory($agent, 'დეტექტივები შემომთავაზე');

        $this->assertGreaterThan(0, $result['imported'], json_encode($result, JSON_UNESCAPED_UNICODE));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://bukinistebi.ge/categories/detective');
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/search?title='));
    }

    public function test_live_text_search_never_replaces_complete_genre_matches_with_unrelated_cards(): void
    {
        [$agent, $conversation] = $this->context();
        $firstThriller = $agent->products()->create([
            'name' => 'პირველი თრილერი',
            'sku' => 'THRILLER-1',
            'category' => 'წიგნები',
            'search_text' => 'პირველი თრილერი',
            'price' => 20,
            'stock' => 2,
            'is_active' => true,
            'metadata' => ['genres' => ['თრილერი']],
        ]);
        $secondThriller = $agent->products()->create([
            'name' => 'მეორე თრილერი',
            'sku' => 'THRILLER-2',
            'category' => 'წიგნები',
            'search_text' => 'მეორე თრილერი',
            'price' => 22,
            'stock' => 3,
            'is_active' => true,
            'metadata' => ['genres' => ['თრილერი']],
        ]);
        $schoolCard = str_replace(
            ['საიუბილეო საარქივო გამოცემა', 'პაოლო იაშვილი'],
            ['სასკოლო მოთხრობები', 'სხვა ავტორი'],
            $this->searchCardsHtml(),
        );
        Http::fake(function ($request) use ($schoolCard) {
            if (str_contains($request->url(), '/search?title=')) {
                return Http::response('<div id="search-results">'.$schoolCard.'</div>');
            }
            if (str_contains($request->url(), '/books/paolo-iashvili/42')) {
                return Http::response('<div class="product-price"><strong>14 ₾</strong></div>');
            }

            return Http::response([], 404);
        });

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'თრილერი',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertEqualsCanonicalizing(
            [$firstThriller->id, $secondThriller->id],
            collect($result['products'])->pluck('id')->all(),
        );
        $this->assertNotContains('სასკოლო მოთხრობები', collect($result['products'])->pluck('name')->all());
    }

    public function test_natural_lookup_uses_store_suggestions_and_hides_sold_out_duplicate_when_available_exists(): void
    {
        [$agent, $conversation] = $this->context();
        config([
            'legatus.semantic_orchestration_enabled' => false,
            'services.openai.key' => null,
        ]);
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);

            if ($path === '/search') {
                return Http::response('<html><body><div id="search-results"></div></body></html>');
            }
            if ($path === '/search/suggest') {
                return Http::response([
                    'items' => str_contains((string) ($parameters['q'] ?? ''), 'დუბლინელ') ? [
                        [
                            'title' => 'დუბლინელები',
                            'author' => 'ჯეიმზ ჯოისი',
                            'url' => 'https://bukinistebi.ge/books/dublinelebi/2387',
                            'sold' => false,
                        ],
                        [
                            'title' => 'დუბლინელები',
                            'author' => 'ჯეიმზ ჯოისი',
                            'url' => 'https://bukinistebi.ge/books/dublinelebi/1621',
                            'sold' => true,
                        ],
                    ] : [],
                    'didYouMean' => null,
                ]);
            }
            if (in_array($path, ['/books/dublinelebi/2387', '/books/dublinelebi/1621'], true)) {
                $price = $path === '/books/dublinelebi/2387' ? '9.00' : '7.00';

                return Http::response(
                    '<script type="application/ld+json">'.json_encode([
                        '@context' => 'https://schema.org',
                        '@type' => 'Product',
                        'name' => 'დუბლინელები',
                        'offers' => [
                            '@type' => 'Offer',
                            'price' => $price,
                            // Deliberately stale: the suggestion's sold flag
                            // must override this for the second listing.
                            'availability' => 'https://schema.org/InStock',
                        ],
                    ], JSON_UNESCAPED_UNICODE).'</script>',
                );
            }

            return Http::response([], 404);
        });

        $result = app(SalesAgentService::class)->reply(
            $agent,
            'ჯოისის დუბლინელები გაქვთ?',
            $conversation,
        );

        $this->assertSame(
            'დუბლინელები',
            data_get($result, 'products.0.name'),
            json_encode([
                'result' => $result,
                'database' => $agent->products()->get()->toArray(),
                'requests' => collect(Http::recorded())->map(fn (array $record): string => $record[0]->url())->all(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
        $this->assertSame(9.0, (float) data_get($result, 'products.0.price'));
        $this->assertCount(1, $result['products']);
        $this->assertStringContainsString('მარაგშია', $result['text']);
        $this->assertStringNotContainsString('მარაგში არ არის', $result['text']);
        $this->assertStringNotContainsString('მარაგში 1 ც.', $result['text']);
        $this->assertSame(['search_products', 'check_stock'], $result['tools_used']);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('agent_runs', [
            'conversation_id' => $conversation->id,
            'provider' => 'local',
            'model' => 'verified-catalog-responder',
        ]);
    }

    /** @return array{Agent, Conversation} */
    private function context(): array
    {
        $agent = Agent::create([
            'name' => 'ანასტასია',
            'slug' => 'public-storefront-test',
            'business_name' => 'bukinistebi.ge',
            'channels' => ['web'],
            'settings' => ['catalog_url' => 'https://bukinistebi.ge/books'],
            'is_active' => true,
        ]);
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Bukinistebi public catalog',
            'url' => 'https://bukinistebi.ge/books',
            'status' => 'ready',
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'public-storefront-customer',
            'channel' => 'widget',
            'status' => 'ai',
        ]);

        return [$agent, $conversation];
    }

    private function searchCardsHtml(): string
    {
        return <<<'HTML'
        <!doctype html><html><body>
        <div class="card book-card">
          <a class="card-link" href="https://bukinistebi.ge/books/paolo-iashvili/42">
            <img src="https://bukinistebi.ge/storage/paolo.webp">
          </a>
          <h2 class="book-title-strong" title="საიუბილეო საარქივო გამოცემა">საიუბილეო საარქივო გამოცემა</h2>
          <a class="book-author-link">პაოლო იაშვილი</a>
          <p>₾ <span>14.00</span></p>
          <input class="quantity" name="quantity" type="number" value="1">
          <button class="toggle-cart-btn" data-product-id="42">კალათაში</button>
        </div>
        </body></html>
        HTML;
    }
}
