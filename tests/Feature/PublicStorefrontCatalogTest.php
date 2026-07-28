<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\KnowledgeIngestionService;
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
        $this->assertStringContainsString('ხელმისაწვდომია', $reply['text']);
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
          <button class="toggle-cart-btn" data-product-id="42">კალათაში</button>
        </div>
        </body></html>
        HTML;
    }
}
