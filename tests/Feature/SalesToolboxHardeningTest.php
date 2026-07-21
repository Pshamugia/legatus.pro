<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Reservation;
use App\Services\SalesToolbox;
use App\Support\PrivacyRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesToolboxHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_is_calculated_from_tenant_policy_and_server_time(): void
    {
        [$agent, , $conversation] = $this->context();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Asia/Tbilisi'));

        $english = app(SalesToolbox::class)->execute('calculate_delivery', ['city' => 'Tbilisi', 'language' => 'en'], $agent, $conversation);
        $georgian = app(SalesToolbox::class)->execute('calculate_delivery', ['city' => 'თბილისი', 'language' => 'ka'], $agent, $conversation);

        $this->assertTrue($english['ok']);
        $this->assertTrue($english['order_before_cutoff']);
        $this->assertSame('Asia/Tbilisi', $english['timezone']);
        $this->assertSame($english['earliest'], $georgian['earliest']);
        $this->assertStringContainsString('Tbilisi', $english['customer_message']);
        $this->assertStringContainsString('თბილისი', $georgian['customer_message']);
    }

    public function test_delivery_fails_closed_when_tenant_policy_is_missing(): void
    {
        [$agent, , $conversation] = $this->context();
        $agent->update(['settings' => ['handoff_threshold' => .72, 'discount_limit' => 10]]);

        $result = app(SalesToolbox::class)->execute('calculate_delivery', ['city' => 'Tbilisi', 'language' => 'en'], $agent->fresh(), $conversation);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not configured', $result['error']);
    }

    public function test_lead_requires_and_records_server_verified_consent_message(): void
    {
        [$agent, , $conversation] = $this->context();
        $consent = $conversation->messages()->create(['role' => 'customer', 'content' => 'I consent: email me at buyer@example.com and store my contact.']);

        $result = app(SalesToolbox::class)->execute('create_lead', [
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'phone' => null,
            'intent' => 'wholesale',
            'notes' => 'Call buyer@example.com',
            'consent' => true,
        ], $agent, $conversation);

        $this->assertTrue($result['ok']);
        $this->assertDatabaseHas('leads', ['conversation_id' => $conversation->id, 'consent_message_id' => $consent->id]);

        $trace = PrivacyRedactor::toolTrace([['name' => 'create_lead', 'arguments' => ['name' => 'Buyer', 'email' => 'buyer@example.com', 'phone' => '+995555123456'], 'result' => $result]]);
        $this->assertSame('[redacted]', $trace[0]['arguments']['name']);
        $this->assertSame('[redacted]', $trace[0]['arguments']['email']);
        $this->assertSame('[redacted]', $trace[0]['arguments']['phone']);
    }

    public function test_privacy_redaction_does_not_mistake_iso_timestamps_for_phone_numbers(): void
    {
        $redacted = PrivacyRedactor::text('Updated 2026-07-20 01:30:20; call +995 555 123 456.');

        $this->assertStringContainsString('2026-07-20 01:30:20', $redacted);
        $this->assertStringContainsString('[phone redacted]', $redacted);
        $this->assertSame([], PrivacyRedactor::contactEvidence('Updated 2026-07-20 01:30:20')['phone_hashes']);
    }

    public function test_offer_rejects_empty_duplicate_overstock_and_inactive_items(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 5);
        $toolbox = app(SalesToolbox::class);

        $this->assertFalse($toolbox->execute('build_offer', ['items' => [], 'discount_percent' => 0], $agent, $conversation)['ok']);
        $duplicates = $toolbox->execute('build_offer', ['items' => [
            ['product_id' => $product->id, 'quantity' => 3],
            ['product_id' => $product->id, 'quantity' => 3],
        ], 'discount_percent' => 0], $agent, $conversation);
        $this->assertFalse($duplicates['ok']);
        $this->assertSame(6, $duplicates['requested']);

        $product->update(['is_active' => false]);
        $inactive = $toolbox->execute('build_offer', ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'discount_percent' => 0], $agent, $conversation);
        $this->assertFalse($inactive['ok']);
    }

    public function test_lead_contact_must_match_the_exact_consent_message(): void
    {
        [$agent, , $conversation] = $this->context();
        $conversation->messages()->create([
            'role' => 'customer',
            'content' => 'I consent to storing buyer@example.com and contacting me there.',
        ]);

        $hallucinated = app(SalesToolbox::class)->execute('create_lead', [
            'name' => 'Buyer',
            'email' => 'different@example.com',
            'phone' => null,
            'intent' => 'wholesale',
            'notes' => null,
            'consent' => true,
        ], $agent, $conversation);
        $invalidPhone = app(SalesToolbox::class)->execute('create_lead', [
            'name' => 'Buyer',
            'email' => null,
            'phone' => '1',
            'intent' => 'wholesale',
            'notes' => null,
            'consent' => true,
        ], $agent, $conversation);

        $this->assertFalse($hallucinated['ok']);
        $this->assertFalse($invalidPhone['ok']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_reservations_are_idempotent_and_reduce_available_stock(): void
    {
        [$agent, $product, $first] = $this->context(stock: 5);
        $second = $agent->conversations()->create(['visitor_id' => 'second', 'status' => 'ai']);
        $toolbox = app(SalesToolbox::class);

        $one = $toolbox->execute('reserve_product', ['product_id' => $product->id, 'quantity' => 4], $agent, $first);
        $this->assertTrue($one['ok']);
        $retry = $toolbox->execute('reserve_product', ['product_id' => $product->id, 'quantity' => 3], $agent, $first);
        $this->assertTrue($retry['ok']);
        $this->assertSame(1, Reservation::where('conversation_id', $first->id)->count());

        $blocked = $toolbox->execute('reserve_product', ['product_id' => $product->id, 'quantity' => 3], $agent, $second);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(2, $blocked['available_stock']);

        $secondProduct = $agent->products()->create(['name' => 'Second Hold', 'sku' => 'SAFE-2', 'price' => 15, 'stock' => 2, 'is_active' => true]);
        $laterHold = Reservation::create([
            'conversation_id' => $first->id,
            'product_id' => $secondProduct->id,
            'quantity' => 1,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
        ]);
        Reservation::whereKey($one['reservation_id'])->update(['expires_at' => now()->subMinute()]);
        $this->artisan('legatus:expire-reservations')->assertSuccessful();
        $this->assertDatabaseHas('reservations', ['id' => $one['reservation_id'], 'status' => 'expired']);
        $this->assertSame('pending_reservation', $first->fresh()->outcome);
        $this->assertSame('ai', $first->fresh()->status);

        $laterHold->update(['expires_at' => now()->subMinute()]);
        $this->artisan('legatus:expire-reservations')->assertSuccessful();
        $first->refresh();
        $this->assertSame('reservation_expired', $first->outcome);
        $this->assertSame('0.00', $first->outcome_value);
        $this->assertSame('closed', $first->status);
        $this->assertNotNull($first->resolved_at);
    }

    public function test_search_and_recommendations_use_reservation_aware_stock_but_owner_can_use_its_hold(): void
    {
        [$agent, $product, $owner] = $this->context(stock: 1);
        $shopper = $agent->conversations()->create(['visitor_id' => 'shopper', 'status' => 'ai']);
        $toolbox = app(SalesToolbox::class);

        $this->assertTrue($toolbox->execute('reserve_product', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $agent, $owner)['ok']);

        $search = $toolbox->execute('search_products', [
            'query' => 'Verified',
            'category' => null,
            'max_price' => null,
        ], $agent, $shopper);
        $recommendations = $toolbox->execute('recommend_products', [
            'query' => 'Verified',
            'budget' => null,
            'category' => null,
            'mood' => null,
            'occasion' => null,
            'limit' => 3,
        ], $agent, $shopper);
        $ownerStock = $toolbox->execute('check_stock', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $agent, $owner);
        $ownerOffer = $toolbox->execute('build_offer', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'discount_percent' => 0,
        ], $agent, $owner);

        $this->assertSame([], $search['products']);
        $this->assertSame([], $recommendations['recommendations']);
        $this->assertSame(1, $ownerStock['available_stock']);
        $this->assertTrue($ownerOffer['ok']);
    }

    public function test_product_discovery_is_case_insensitive_for_names_categories_and_ranking(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'category' => 'Premium Books',
            'description' => 'A Quiet Literary Mystery',
        ]);
        $toolbox = app(SalesToolbox::class);

        $search = $toolbox->execute('search_products', [
            'query' => 'verified product',
            'category' => 'premium books',
            'max_price' => null,
        ], $agent, $conversation);
        $recommendations = $toolbox->execute('recommend_products', [
            'query' => 'VERIFIED PRODUCT',
            'budget' => null,
            'category' => 'PREMIUM BOOKS',
            'mood' => null,
            'occasion' => null,
            'limit' => 3,
        ], $agent, $conversation);

        $this->assertSame($product->id, $search['products'][0]['id']);
        $this->assertSame($product->id, $recommendations['recommendations'][0]['id']);
        $this->assertContains('verified', $recommendations['recommendations'][0]['matched_signals']);
    }

    public function test_product_search_matches_common_georgian_author_inflections(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'name' => 'ქართველი ერის ისტორია',
            'description' => 'ივანე ჯავახიშვილი',
        ]);
        $agent->products()->create([
            'name' => 'თარგმანები',
            'description' => 'ივანე მაჩაბელი',
            'price' => 15,
            'stock' => 2,
            'is_active' => true,
        ]);
        $toolbox = app(SalesToolbox::class);

        foreach (['ივანე ჯავახიშვილს', 'ივანე ჯავახიშვილის'] as $query) {
            $result = $toolbox->execute('search_products', [
                'query' => $query,
                'category' => null,
                'max_price' => null,
            ], $agent, $conversation);

            $this->assertSame([$product->id], collect($result['products'])->pluck('id')->all());
        }
    }

    public function test_product_search_relaxes_conversational_words_and_ranks_the_strongest_identity_match(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'name' => 'ქართველი ერის ისტორია',
            'description' => 'ივანე ჯავახიშვილი',
            'search_text' => 'ქართველი ერის ისტორია ივანე ჯავახიშვილი',
        ]);
        $agent->products()->create([
            'name' => 'მარტოობის ასი წელიწადი',
            'description' => 'სხვა ავტორი',
            'search_text' => 'მარტოობის ასი წელიწადი სხვა ავტორი',
            'price' => 18,
            'stock' => 2,
            'is_active' => true,
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'ივანე ჯავახიშვილის მარტო ეს გაქვთ?',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame($product->id, data_get($result, 'products.0.id'));
        $this->assertSame('ქართველი ერის ისტორია', data_get($result, 'products.0.name'));
    }

    public function test_product_search_treats_wildcards_as_literal_text(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update(['name' => 'Save 100% Today']);
        $agent->products()->create([
            'name' => 'Save 1000 Today',
            'price' => 15,
            'stock' => 2,
            'is_active' => true,
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => '100%',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([$product->id], collect($result['products'])->pluck('id')->all());
    }

    public function test_connected_product_search_exposes_only_a_safe_confirmable_typo_suggestion(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $agent->commerceConnection()->create([
            'provider' => 'universal_api',
            'name' => 'Bukinistebi live catalogue',
            'base_url' => 'https://8.8.8.8',
            'key_id' => 'bukinistebi-test',
            'secret' => str_repeat('s', 32),
            'status' => 'active',
        ]);
        Http::fake(fn () => Http::response([
            'data' => [],
            'meta' => [
                'total' => 0,
                'did_you_mean' => 'ჯავახიშვილი',
                'suggestion_requires_confirmation' => true,
            ],
        ]));

        $typo = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'ჯახიშვილის',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);
        $this->assertSame([], $typo['products']);
        $this->assertSame('ჯავახიშვილი', $typo['did_you_mean']);
        $this->assertTrue($typo['suggestion_requires_confirmation']);

        $unrelated = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'სრულიადუცნობი',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);
        $this->assertNull($unrelated['did_you_mean']);
        $this->assertFalse($unrelated['suggestion_requires_confirmation']);

        $product->update(['description' => 'ივანე ჯავახიშვილი']);
        $exact = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'ივანე ჯავახიშვილი',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);
        $this->assertSame([$product->id], collect($exact['products'])->pluck('id')->all());
        $this->assertNull($exact['did_you_mean']);
        Http::assertSentCount(2);
    }

    public function test_product_typo_suggestions_are_tenant_isolated(): void
    {
        [$agent, , $conversation] = $this->context(stock: 4);
        $other = Agent::create([
            'name' => 'Other assistant',
            'slug' => 'other-tenant-suggestion',
            'business_name' => 'Other Store',
        ]);
        $other->products()->create([
            'name' => 'ქართველი ერის ისტორია',
            'description' => 'ივანე ჯავახიშვილი',
            'price' => 20,
            'stock' => 2,
            'is_active' => true,
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'ჯახიშვილის',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertNull($result['did_you_mean']);
    }

    private function context(int $stock = 10): array
    {
        $agent = Agent::create([
            'name' => 'Legatus',
            'slug' => 'toolbox-hardening',
            'business_name' => 'Verified Store',
            'settings' => [
                'handoff_threshold' => .72,
                'discount_limit' => 10,
                'delivery_policy' => [
                    'timezone' => 'Asia/Tbilisi',
                    'local_cities' => ['თბილისი', 'Tbilisi'],
                    'cutoff' => '18:00',
                    'local_business_days' => 1,
                    'regional_min_business_days' => 1,
                    'regional_max_business_days' => 3,
                    'source_label' => 'Delivery policy · test',
                ],
            ],
        ]);
        $product = $agent->products()->create(['name' => 'Verified Product', 'sku' => 'SAFE-1', 'price' => 20, 'stock' => $stock, 'is_active' => true]);
        $conversation = $agent->conversations()->create(['visitor_id' => 'first', 'status' => 'ai']);

        return [$agent, $product, $conversation];
    }
}
