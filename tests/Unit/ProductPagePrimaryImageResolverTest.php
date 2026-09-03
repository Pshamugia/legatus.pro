<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\KnowledgeIngestionService;
use App\Services\ProductPagePrimaryImageResolver;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class ProductPagePrimaryImageResolverTest extends TestCase
{
    public function test_it_resolves_only_the_exact_same_origin_storefront_primary_image_contract(): void
    {
        Cache::flush();
        $product = new Product([
            'metadata' => ['product_url' => 'https://shop.example/products/book'],
        ]);
        $knowledge = Mockery::mock(KnowledgeIngestionService::class);
        $knowledge->shouldReceive('fetchPublicUrl')
            ->once()
            ->andReturn(new Response(new PsrResponse(200, [], '<img id="thumbnailImage" src="/storage/books/thumb.jpg">')));

        $this->assertSame(
            'https://shop.example/storage/books/thumb.jpg',
            (new ProductPagePrimaryImageResolver($knowledge))->resolve($product),
        );
    }

    public function test_it_does_not_opt_other_storefront_markup_into_the_exception(): void
    {
        Cache::flush();
        $product = new Product([
            'metadata' => ['product_url' => 'https://other.example/products/book'],
        ]);
        $knowledge = Mockery::mock(KnowledgeIngestionService::class);
        $knowledge->shouldReceive('fetchPublicUrl')
            ->once()
            ->andReturn(new Response(new PsrResponse(200, [], '<img class="product-image" src="/storage/books/thumb.jpg">')));

        $this->assertNull((new ProductPagePrimaryImageResolver($knowledge))->resolve($product));
    }
}
