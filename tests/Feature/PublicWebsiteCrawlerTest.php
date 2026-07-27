<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\PublicWebsiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicWebsiteCrawlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_follows_pagination_and_product_pages_across_a_public_website(): void
    {
        $this->seed();
        config(['services.openai.key' => null, 'legatus.public_crawl_max_pages' => 20]);
        $agent = Agent::firstOrFail();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Public shop',
            'url' => 'https://bukinistebi.ge/books',
        ]);

        Http::fake(function ($request) {
            return match ($request->url()) {
                'https://bukinistebi.ge/books' => Http::response(
                    $this->catalogPage('First Book', 'B-1', 14, '/books/first/1', '/books?page=2'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://bukinistebi.ge/books?page=2' => Http::response(
                    $this->catalogPage('Second Book', 'B-2', 22, '/books/second/2'),
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://bukinistebi.ge/books/first/1' => Http::response(
                    '<html><title>First Book</title><main><h1>First Book</h1><p>A philosophical novel about memory, identity, and freedom.</p></main></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                'https://bukinistebi.ge/books/second/2' => Http::response(
                    '<html><title>Second Book</title><main><h1>Second Book</h1><p>A detailed description of the second public catalog product.</p></main></html>',
                    200,
                    ['Content-Type' => 'text/html'],
                ),
                default => Http::response('', 404, ['Content-Type' => 'text/html']),
            };
        });

        app(PublicWebsiteCrawler::class)->crawl($source);

        $source->refresh();
        $this->assertSame('ready', $source->status);
        $this->assertSame(100, $source->progress);
        $this->assertSame(2, $source->items_found);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'B-1', 'price' => 14]);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'B-2', 'price' => 22]);
        $this->assertStringContainsString(
            'memory, identity, and freedom',
            (string) $agent->products()->where('sku', 'B-1')->value('description'),
        );
        $this->assertTrue($source->chunks()->where('content', 'like', '%memory, identity, and freedom%')->exists());
    }

    private function catalogPage(string $name, string $sku, int $price, string $productUrl, ?string $next = null): string
    {
        $nextLink = $next ? '<a rel="next" href="'.$next.'">Next</a>' : '';
        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'sku' => $sku,
            'url' => 'https://bukinistebi.ge'.$productUrl,
            'description' => 'Public product description',
            'offers' => [
                '@type' => 'Offer',
                'price' => $price,
                'priceCurrency' => 'GEL',
                'availability' => 'https://schema.org/InStock',
            ],
        ], JSON_UNESCAPED_SLASHES);

        return '<html><head><script type="application/ld+json">'.$json.'</script></head><body>'
            .$nextLink.'<a href="'.$productUrl.'">'.$name.'</a></body></html>';
    }
}
