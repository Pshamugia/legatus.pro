<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\CustomerImageCatalogMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerImageCatalogMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_photo_is_confirmed_only_after_catalog_search_visual_match_and_stock_check(): void
    {
        [$agent, $conversation] = $this->tenant();
        $product = $agent->products()->create([
            'name' => 'არტისტული ყვავილები',
            'sku' => 'ART-2052',
            'category' => 'პოეზია',
            'description' => 'გალაკტიონ ტაბიძის პოეზიის კრებული.',
            'search_text' => 'არტისტული ყვავილები გალაკტიონ ტაბიძე პოეზია',
            'price' => 14,
            'stock' => 25,
            'image' => 'https://catalog.example/artistuli-yvavilebi.jpg',
            'metadata' => ['author' => 'გალაკტიონ ტაბიძე', 'product_url' => 'https://shop.example/books/2052'],
            'is_active' => true,
        ]);
        $this->fakeVision([
            $this->identity('არტისტული ყვავილები', 'გალაკტიონ ტაბიძე'),
            ['same_product' => true, 'matched_product_id' => $product->id, 'reason' => 'Visible title, author, and cover agree.'],
        ]);

        $result = app(CustomerImageCatalogMatcher::class)->resolve(
            $agent,
            $conversation,
            'https://scontent.xx.fbcdn.net/customer-photo.jpg',
            'ეს წიგნი გაქვთ?',
        );

        $this->assertSame('available', $result['status']);
        $this->assertSame([$product->id], $result['product_ids']);
        $this->assertStringContainsString('ზუსტად ეს პროდუქტი გადავამოწმე', $result['text']);
        $this->assertStringContainsString('ხელმისაწვდომია', $result['text']);
        $this->assertSame(['search_products', 'search_products', 'check_stock'], collect($result['tools_used'])->pluck('name')->all());

        $openAiRequests = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($request): bool => str_ends_with($request->url(), '/responses'))->values();
        $this->assertCount(2, $openAiRequests);
        $this->assertSame('original', data_get($openAiRequests[0]->data(), 'input.0.content.1.detail'));
        $this->assertStringStartsWith('data:image/', (string) data_get($openAiRequests[0]->data(), 'input.0.content.1.image_url'));
        $this->assertTrue(collect(data_get($openAiRequests[1]->data(), 'input.0.content', []))
            ->contains(fn ($item): bool => data_get($item, 'image_url') === $product->image));
    }

    public function test_similar_catalog_item_is_not_presented_when_visual_verifier_cannot_confirm_identity(): void
    {
        [$agent, $conversation] = $this->tenant();
        $agent->products()->create([
            'name' => 'არტისტული ყვავილები', 'sku' => 'ART-OTHER', 'category' => 'პოეზია',
            'search_text' => 'არტისტული ყვავილები გალაკტიონ ტაბიძე', 'price' => 14, 'stock' => 5,
            'image' => 'https://catalog.example/different-edition.jpg',
            'metadata' => ['author' => 'გალაკტიონ ტაბიძე', 'product_url' => 'https://shop.example/books/other'],
            'is_active' => true,
        ]);
        $this->fakeVision([
            $this->identity('არტისტული ყვავილები', 'გალაკტიონ ტაბიძე'),
            ['same_product' => false, 'matched_product_id' => null, 'reason' => 'The edition and cover do not match.'],
        ]);

        $result = app(CustomerImageCatalogMatcher::class)->resolve(
            $agent,
            $conversation,
            'https://scontent.xx.fbcdn.net/customer-photo.jpg',
            'ეს წიგნი გაქვთ?',
        );

        $this->assertSame('uncertain', $result['status']);
        $this->assertSame([], $result['products']);
        $this->assertStringContainsString('ზუსტი იდენტობა ვერ დავადასტურე', $result['text']);
        $this->assertStringNotContainsString('ხელმისაწვდომია', $result['text']);
    }

    public function test_readable_photo_identity_with_no_catalog_match_returns_honest_not_found_answer(): void
    {
        [$agent, $conversation] = $this->tenant();
        $this->fakeVision([$this->identity('არარსებული პროდუქტი', 'უცნობი ავტორი')]);

        $result = app(CustomerImageCatalogMatcher::class)->resolve(
            $agent,
            $conversation,
            'https://scontent.xx.fbcdn.net/customer-photo.jpg',
            'ეს გაქვთ?',
        );

        $this->assertSame('not_found', $result['status']);
        $this->assertSame([], $result['products']);
        $this->assertStringContainsString('ზუსტი დამთხვევა ვერ ვიპოვე', $result['text']);
        $this->assertStringContainsString('ვერ დაგიდასტურებთ', $result['text']);
    }

    public function test_unique_exact_identifier_can_confirm_the_catalog_item_without_a_second_visual_guess(): void
    {
        [$agent, $conversation] = $this->tenant();
        $product = $agent->products()->create([
            'name' => 'Exact identifier product',
            'sku' => '9781234567890',
            'category' => 'Books',
            'search_text' => 'Exact identifier product 9781234567890',
            'price' => 20,
            'stock' => 3,
            'image' => 'https://catalog.example/exact.jpg',
            'metadata' => ['isbn' => '978-1-2345-6789-0', 'product_url' => 'https://shop.example/exact'],
            'is_active' => true,
        ]);
        $identity = $this->identity('Exact identifier product');
        $identity['isbn'] = '978-1-2345-6789-0';
        $this->fakeVision([$identity]);

        $result = app(CustomerImageCatalogMatcher::class)->resolve(
            $agent,
            $conversation,
            'https://scontent.xx.fbcdn.net/customer-photo.jpg',
            'Do you have this product?',
        );

        $this->assertSame('available', $result['status']);
        $this->assertSame([$product->id], $result['product_ids']);
        $this->assertSame(1, $result['usage']['requests']);
        $this->assertSame('check_stock', collect($result['tools_used'])->last()['name']);
        $this->assertCount(1, collect(Http::recorded())->filter(
            fn ($pair): bool => str_ends_with($pair[0]->url(), '/responses')
        ));
    }

    public function test_non_meta_image_url_is_rejected_before_any_network_or_ai_request(): void
    {
        [$agent, $conversation] = $this->tenant();
        config(['services.openai.key' => 'test-key']);
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('approved Meta media host');

        try {
            app(CustomerImageCatalogMatcher::class)->resolve(
                $agent,
                $conversation,
                'https://internal.example/private.jpg',
                'Do you have this?',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    private function tenant(): array
    {
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Image shop', 'slug' => 'image-shop']);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Assistant', 'slug' => 'image-agent', 'business_name' => 'Image shop',
            'channels' => ['facebook', 'instagram'], 'settings' => [], 'is_active' => true,
        ]);
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'image-customer', 'channel' => 'facebook', 'status' => 'ai',
        ]);

        return [$agent, $conversation];
    }

    private function identity(string $name, ?string $creator = null): array
    {
        return [
            'image_is_product' => true,
            'identifying_text_readable' => true,
            'exact_name' => $name,
            'creator' => $creator,
            'brand' => null,
            'model' => null,
            'isbn' => null,
            'barcode' => null,
            'variant' => null,
            'visible_text' => array_values(array_filter([$name, $creator])),
            'uncertainty_reason' => '',
        ];
    }

    private function fakeVision(array $structuredResponses): void
    {
        config(['services.openai.key' => 'test-key', 'services.openai.image_recognition_model' => 'gpt-5.6-sol']);
        $image = UploadedFile::fake()->image('customer.jpg', 900, 1200);
        $bytes = file_get_contents($image->getRealPath());
        $responses = collect($structuredResponses);

        Http::fake(function ($request) use ($bytes, $responses) {
            if (str_ends_with($request->url(), 'customer-photo.jpg')) {
                return Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']);
            }
            if (str_ends_with($request->url(), '/responses')) {
                $data = $responses->shift();

                return Http::response([
                    'id' => 'vision-'.str()->random(6),
                    'output' => [[
                        'type' => 'message',
                        'content' => [['type' => 'output_text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE)]],
                    ]],
                    'usage' => ['input_tokens' => 120, 'output_tokens' => 30],
                ]);
            }

            return Http::response([], 404);
        });
    }
}
