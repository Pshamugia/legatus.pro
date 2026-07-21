<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\KnowledgeIngestionService;
use App\Services\SalesAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicStorefrontCatalogTest extends TestCase
{
    use RefreshDatabase;

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
