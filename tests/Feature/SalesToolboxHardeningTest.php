<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Reservation;
use App\Jobs\CrawlPublicWebsite;
use App\Services\SalesToolbox;
use App\Support\PrivacyRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_published_website_delivery_policy_overrides_seeded_date_estimates(): void
    {
        [$agent, , $conversation] = $this->context();
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Store website',
            'url' => 'https://store.example',
            'status' => 'ready',
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id,
            'kind' => 'policy',
            'title' => 'მიწოდების პირობები',
            'content' => 'თბილისში მიწოდება სრულდება 2-3 სამუშაო დღეში. ზუსტ დროს ოპერატორი ადასტურებს.',
            'content_hash' => hash('sha256', 'published-delivery-policy'),
            'metadata' => ['url' => 'https://store.example/delivery'],
        ]);

        $result = app(SalesToolbox::class)->execute('calculate_delivery', [
            'city' => 'თბილისი',
            'language' => 'ka',
        ], $agent, $conversation);

        $this->assertTrue($result['ok']);
        $this->assertSame('website_policy', $result['source']['type']);
        $this->assertSame('https://store.example/delivery', $result['source']['url']);
        $this->assertStringContainsString('2-3 სამუშაო დღეში', $result['customer_message']);
        $this->assertArrayNotHasKey('earliest', $result);
        $this->assertStringNotContainsString('2026-', $result['customer_message']);
    }

    public function test_manual_delivery_knowledge_has_priority_over_website_policy(): void
    {
        [$agent, , $conversation] = $this->context();
        $website = $agent->knowledgeSources()->create([
            'type' => 'url', 'name' => 'Website', 'url' => 'https://store.example', 'status' => 'ready',
        ]);
        $website->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'policy', 'title' => 'Delivery',
            'content' => 'Delivery takes 9 days.', 'content_hash' => hash('sha256', 'website-delivery'),
        ]);
        $manual = $agent->knowledgeSources()->create([
            'type' => 'text', 'source_scope' => 'delivery', 'name' => 'Delivery information', 'status' => 'ready',
        ]);
        $manual->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'policy', 'title' => 'ინფორმაცია მომხმარებლისთვის',
            'content' => 'თბილისის მასშტაბით მომსახურება უფასოა და სრულდება 1-2 სამუშაო დღეში.',
            'content_hash' => hash('sha256', 'manual-delivery'),
        ]);

        $result = app(SalesToolbox::class)->execute('calculate_delivery', [
            'city' => 'თბილისი', 'language' => 'ka',
        ], $agent, $conversation);

        $this->assertTrue($result['ok']);
        $this->assertSame('manual_policy', $result['source']['type']);
        $this->assertStringContainsString('1-2 სამუშაო დღეში', $result['customer_message']);
        $this->assertStringNotContainsString('9 days', $result['customer_message']);
        $this->assertArrayNotHasKey('earliest', $result);
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
        $this->assertSame($product->id, $search['unavailable_products'][0]['id']);
        $this->assertFalse($search['unavailable_products'][0]['available']);
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

    public function test_product_search_restores_vowel_final_surname_from_colloquial_genitive(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'name' => 'არასრულწლოვანი',
            'description' => 'პაატა შამუგიას პოეზია',
            'search_text' => 'არასრულწლოვანი პაატა შამუგია პოეზია',
            'metadata' => ['author' => 'პაატა შამუგია'],
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'შამუგიასი რა გაქვთ?',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([$product->id], collect($result['products'])->pluck('id')->all());
        $this->assertNull($result['did_you_mean']);
    }

    public function test_product_search_suggests_the_nearest_tenant_catalog_author_for_a_real_typo(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'name' => 'არასრულწლოვანი',
            'description' => 'პაატა შამუგიას პოეზია',
            'search_text' => 'არასრულწლოვანი პაატა შამუგია პოეზია',
            'metadata' => ['author' => 'პაატა შამუგია'],
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'შამუგიასს რა გაქვთ?',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertSame('პაატა შამუგია', $result['did_you_mean']);
        $this->assertTrue($result['suggestion_requires_confirmation']);
    }

    public function test_product_search_never_suggests_an_unrelated_title_from_one_similar_first_name(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'name' => 'იოანე ბატონიშვილი',
            'description' => 'საქართველოს ისტორიის შესახებ',
            'search_text' => 'იოანე ბატონიშვილი საქართველოს ისტორია',
        ]);

        foreach ([
            'კაზიმერ ვალიშევსკის ივანე მრისხანე გაქვთ?',
            'რუსეთის მოსკოვის მეფეების ბიოგრაფიები და ისტორია მინდა',
        ] as $query) {
            $result = app(SalesToolbox::class)->execute('search_products', [
                'query' => $query, 'category' => null, 'max_price' => null,
            ], $agent, $conversation);

            $this->assertSame([], $result['products']);
            $this->assertNull($result['did_you_mean'], $query);
            $this->assertFalse($result['suggestion_requires_confirmation']);
        }
    }

    public function test_product_search_suggests_a_long_georgian_title_with_one_inserted_letter(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 4);
        $product->update([
            'name' => 'ვეფხისტყაოსანი',
            'description' => 'შოთა რუსთაველის პოემა',
            'search_text' => 'ვეფხისტყაოსანი შოთა რუსთაველი',
            'metadata' => ['author' => 'შოთა რუსთაველი'],
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/search/suggest')) {
                return Http::response(['items' => [], 'didYouMean' => 'ვეფხისტყაოსნის საკითხები']);
            }

            return Http::response('<html><body>No matching products</body></html>');
        });

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'ვეფხვისტყაოსანი გაქვთ?',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertSame('ვეფხისტყაოსანი', $result['did_you_mean']);
        $this->assertTrue($result['suggestion_requires_confirmation']);

        $recommendation = app(SalesToolbox::class)->execute('recommend_products', [
            'query' => 'ვეფხვისტყაოსანი',
            'budget' => null,
            'category' => null,
            'mood' => null,
            'occasion' => null,
            'limit' => 3,
        ], $agent, $conversation);

        $this->assertSame([], $recommendation['recommendations']);
        $this->assertSame('ვეფხისტყაოსანი', $recommendation['did_you_mean']);
        $this->assertTrue($recommendation['suggestion_requires_confirmation']);
    }

    public function test_local_typo_suggestions_are_near_match_only_and_tenant_isolated(): void
    {
        [$agent, , $conversation] = $this->context(stock: 4);
        $other = Agent::create([
            'name' => 'Other assistant',
            'slug' => 'other-local-suggestion',
            'business_name' => 'Other Store',
        ]);
        $other->products()->create([
            'name' => 'არასრულწლოვანი',
            'search_text' => 'პაატა შამუგია',
            'price' => 8,
            'stock' => 1,
            'is_active' => true,
            'metadata' => ['author' => 'პაატა შამუგია'],
        ]);

        $isolated = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'შამუგიასს რა გაქვთ?',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);
        $unrelated = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'სრულიადუცნობი',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertNull($isolated['did_you_mean']);
        $this->assertNull($unrelated['did_you_mean']);
    }

    public function test_product_search_restores_e_stem_georgian_surnames_without_a_typo_prompt(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 5);
        $product->update([
            'name' => 'ქართული წიგნის ისტორია',
            'description' => 'გურამ ინასარიძე',
            'search_text' => 'ქართული წიგნის ისტორია გურამ ინასარიძე ბიბლიოგრაფია',
        ]);

        foreach (['ინასარიძის რა გაქვთ?', 'ინასარიძეს რა აქვს გამოცემული?', 'ინასარიძემ რა დაწერა?'] as $query) {
            $result = app(SalesToolbox::class)->execute('search_products', [
                'query' => $query,
                'category' => null,
                'max_price' => null,
            ], $agent, $conversation);

            $this->assertSame([$product->id], collect($result['products'])->pluck('id')->all(), $query);
            $this->assertNull($result['did_you_mean'], $query);
        }
    }

    public function test_availability_only_catalog_never_exposes_its_internal_sentinel_as_exact_stock(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 1);
        $product->update(['metadata' => ['stock_precision' => 'availability_only']]);

        $search = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'Verified Product',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);
        $check = app(SalesToolbox::class)->execute('check_stock', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], $agent, $conversation);

        $this->assertTrue(data_get($search, 'products.0.available'));
        $this->assertSame('availability_only', data_get($search, 'products.0.stock_precision'));
        $this->assertArrayNotHasKey('stock', $search['products'][0]);
        $this->assertArrayNotHasKey('available_stock', $search['products'][0]);
        $this->assertTrue($check['available']);
        $this->assertSame('availability_only', $check['stock_precision']);
        $this->assertArrayNotHasKey('stock', $check);
        $this->assertArrayNotHasKey('available_stock', $check);
        $this->assertArrayNotHasKey('catalog_stock', $check);
    }

    public function test_recommendations_require_real_thematic_relevance_and_understand_georgian_adjectives(): void
    {
        [$agent, $philosophy, $conversation] = $this->context(stock: 5);
        $philosophy->update([
            'name' => 'ფილოსოფიური ეტიუდები',
            'category' => 'ფილოსოფია',
            'description' => 'ეთიკისა და აზროვნების ისტორია',
            'search_text' => 'ფილოსოფიური ეტიუდები ფილოსოფია ეთიკა აზროვნება',
        ]);
        $irrelevant = $agent->products()->create([
            'name' => 'სამზარეულოს რეცეპტები',
            'category' => 'კულინარია',
            'description' => 'ტრადიციული კერძები',
            'price' => 12,
            'stock' => 4,
            'is_active' => true,
        ]);

        $result = app(SalesToolbox::class)->execute('recommend_products', [
            'query' => 'ფილოსოფიური წიგნები მირჩიე',
            'budget' => null,
            'category' => null,
            'mood' => null,
            'occasion' => null,
            'limit' => 5,
        ], $agent, $conversation);

        $this->assertSame([$philosophy->id], collect($result['recommendations'])->pluck('id')->all());
        $this->assertNotContains($irrelevant->id, collect($result['recommendations'])->pluck('id')->all());
        $this->assertContains('ფილოსოფიური', $result['recommendations'][0]['matched_signals']);
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

    public function test_static_demo_products_are_excluded_after_a_real_catalog_is_connected(): void
    {
        [$agent, $existing, $conversation] = $this->context(stock: 4);
        Http::fake(['*' => Http::response('<html><body>No matching products</body></html>')]);

        $fixture = $agent->knowledgeSources()->create([
            'type' => 'csv',
            'name' => 'Verified product catalog',
            'status' => 'ready',
        ]);
        $real = $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Real business catalog',
            'url' => 'https://shop.example/products',
            'status' => 'ready',
        ]);
        $existing->update(['metadata' => ['source_id' => $fixture->id]]);
        $realProduct = $agent->products()->create([
            'name' => 'Real Catalog Book',
            'sku' => 'REAL-1',
            'category' => 'Mystery',
            'description' => 'A mysterious modern novel',
            'price' => 19,
            'stock' => 5,
            'is_active' => true,
            'metadata' => ['source_id' => $real->id],
        ]);

        $result = app(SalesToolbox::class)->execute('recommend_products', [
            'query' => 'mysterious modern novel',
            'budget' => 30,
            'category' => null,
            'mood' => 'mysterious',
            'occasion' => null,
            'limit' => 5,
        ], $agent, $conversation);

        $this->assertSame([$realProduct->id], collect($result['recommendations'])->pluck('id')->all());
        $this->assertFalse($agent->customerProducts()->whereKey($existing->id)->exists());
    }

    public function test_verified_business_category_index_is_used_before_polluted_general_catalog_text(): void
    {
        [$agent, $unrelated, $conversation] = $this->context(stock: 3);
        $unrelated->update([
            'name' => 'Fictional dystopia',
            'search_text' => 'fictional dystopia biography',
            'metadata' => ['taxonomy' => ['Biography']],
        ]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url',
            'source_scope' => 'category',
            'taxonomy_label' => 'Biography',
            'name' => 'Category: Biography',
            'url' => 'https://bukinistebi.ge/categories/biography',
            'status' => 'ready',
            'progress' => 100,
            'index_version' => 2,
            'last_synced_at' => now(),
        ]);
        $verified = $agent->products()->create([
            'name' => 'Verified life story', 'sku' => 'BIO-1', 'search_text' => 'Verified life story Biography',
            'price' => 25, 'stock' => 2, 'is_active' => true, 'metadata' => ['taxonomy' => ['Biography']],
        ]);
        $source->chunks()->create([
            'agent_id' => $agent->id, 'kind' => 'product', 'title' => $verified->name,
            'content' => '{"name":"Verified life story"}', 'content_hash' => hash('sha256', 'verified-biography'),
            'metadata' => ['product_id' => $verified->id],
        ]);
        Http::fake();

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'Biography books',
            'category' => null,
            'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame(['Verified life story'], collect($result['products'])->pluck('name')->all());
        $this->assertNotContains($unrelated->id, collect($result['products'])->pluck('id')->all());
        $this->assertSame(2, $source->fresh()->index_version);
        Http::assertNothingSent();
    }

    public function test_unbuilt_category_queues_its_url_without_blocking_chat_or_returning_unrelated_products(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('<html><body>No matching products</body></html>')]);
        [$agent, $unrelated, $conversation] = $this->context(stock: 3);
        $unrelated->update(['search_text' => 'fiction biography', 'metadata' => ['taxonomy' => ['Biography']]]);
        $source = $agent->knowledgeSources()->create([
            'type' => 'url', 'source_scope' => 'category', 'taxonomy_label' => 'Biography',
            'name' => 'Category: Biography', 'url' => 'https://bukinistebi.ge/categories/biography',
            'status' => 'ready', 'index_version' => 0,
        ]);

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'Biography books', 'category' => null, 'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertTrue($result['category_index_pending']);
        $this->assertSame('processing', $source->fresh()->status);
        Queue::assertPushed(CrawlPublicWebsite::class, fn ($job): bool => $job->sourceId === $source->id);
    }

    public function test_generic_georgian_follow_up_never_matches_a_product_by_substring(): void
    {
        [$agent, $product, $conversation] = $this->context(stock: 3);
        $product->update([
            'name' => 'დრამები',
            'search_text' => 'დრამები ჰენრიკ იბსენი',
        ]);
        Http::fake();

        $result = app(SalesToolbox::class)->execute('search_products', [
            'query' => 'რამე გაქვთ საერთოდ?', 'category' => null, 'max_price' => null,
        ], $agent, $conversation);

        $this->assertSame([], $result['products']);
        $this->assertSame([], $result['unavailable_products']);
        Http::assertNothingSent();
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
