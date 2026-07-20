<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingGoldenPathTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_updates_the_workspace_and_learns_url_and_catalog(): void
    {
        config(['services.openai.key' => null]);
        $this->post('/register', ['name' => 'Owner', 'email' => 'owner@example.com', 'business_name' => 'Initial Store', 'password' => 'password123', 'password_confirmation' => 'password123']);
        $user = User::where('email', 'owner@example.com')->firstOrFail();
        $agentId = $user->organizations()->firstOrFail()->agents()->value('id');
        Http::fake([
            'https://example.com/store' => Http::response('<html><body><h1>Returns and delivery</h1><p>Orders confirmed before six are delivered on the next business day. Returns are accepted within fourteen days.</p><script type="application/ld+json">{"@type":"Product","name":"Website Product","sku":"WEB-1","category":"Gift","description":"A verified website product","offers":{"price":"19.90","availability":"https://schema.org/InStock"}}</script></body></html>'),
            'https://example.com/all-products' => Http::response('<html><body><h1>Complete catalog</h1><script type="application/ld+json">{"@graph":[{"@type":"Product","name":"Catalog URL Product One","sku":"URL-1","offers":{"price":"31.00","availability":"https://schema.org/InStock"}},{"@type":"Product","name":"Catalog URL Product Two","sku":"URL-2","offers":{"price":"42.00","availability":"https://schema.org/InStock"}}]}</script></body></html>'),
        ]);
        $catalog = UploadedFile::fake()->createWithContent('catalog.csv', "name,sku,category,description,price,stock\nCatalog Product,CSV-1,Books,Imported during onboarding,24.90,8\n");

        $this->actingAs($user)->get('/onboarding')
            ->assertOk()
            ->assertSee('name="business_name"', false)
            ->assertSee('name="agent_name"', false);

        $this->actingAs($user)->post('/onboarding', ['business_name' => 'Golden Store', 'agent_name' => 'ანა', 'website' => 'https://example.com/store', 'catalog_url' => 'https://example.com/all-products', 'description' => 'A polished demo business.', 'catalog' => $catalog])->assertRedirect('/app/channels');

        $agent = Agent::findOrFail($agentId);
        $this->assertSame('ანა', $agent->name);
        $this->assertSame('Golden Store', $agent->business_name);
        $this->assertSame('Golden Store', $agent->organization->name);
        $this->assertDatabaseCount('agents', 1);
        $this->assertSame(3, $agent->knowledgeSources()->where('status', 'ready')->count());
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'WEB-1', 'stock' => 1]);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'URL-1', 'stock' => 1]);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'URL-2', 'stock' => 1]);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'CSV-1', 'stock' => 8]);
        $this->assertSame(
            ['https://example.com', 'https://www.example.com'],
            $agent->settings['widget_allowed_origins'],
        );
        $this->assertSame('https://example.com/all-products', $agent->settings['catalog_url']);

        $this->actingAs($user)->get('/onboarding')
            ->assertOk()
            ->assertSee('Update your business setup.')
            ->assertSee('value="Golden Store"', false)
            ->assertSee('value="ანა"', false)
            ->assertSee('value="https://example.com/store"', false)
            ->assertSee('value="https://example.com/all-products"', false)
            ->assertSee('A polished demo business.')
            ->assertSee('catalog.csv')
            ->assertSee('example.com product catalog')
            ->assertSee('example.com')
            ->assertSee('Browsers cannot prefill a file input');

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('onboarding'), false)
            ->assertSee('Manage setup')
            ->assertDontSee('Configure Legatus');
    }

    public function test_same_website_and_catalog_url_is_learned_only_once(): void
    {
        config(['services.openai.key' => null]);
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'dedupe@example.com',
            'business_name' => 'Dedupe Store',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $user = User::where('email', 'dedupe@example.com')->firstOrFail();
        Http::fake([
            'https://example.com/products' => Http::response('<html><body><h1>All products</h1><script type="application/ld+json">{"@type":"Product","name":"Only Once","sku":"ONE-1","offers":{"price":"25","availability":"https://schema.org/InStock"}}</script></body></html>'),
        ]);

        $this->actingAs($user)->post('/onboarding', [
            'business_name' => 'Dedupe Store',
            'agent_name' => 'თამარი',
            'website' => 'https://example.com/products',
            'catalog_url' => 'https://example.com/products',
        ])->assertRedirect('/app/channels');

        $agent = $user->organizations()->firstOrFail()->agents()->firstOrFail();
        $this->assertSame(1, $agent->knowledgeSources()->count());
        $this->assertSame(1, $agent->products()->where('sku', 'ONE-1')->count());
        Http::assertSentCount(1);
    }

    public function test_failed_catalog_url_is_saved_but_reported_as_needing_attention(): void
    {
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'failed-catalog@example.com',
            'business_name' => 'Catalog Store',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $user = User::where('email', 'failed-catalog@example.com')->firstOrFail();
        Http::fake([
            'https://example.com/broken-catalog' => Http::response('Unavailable', 503),
        ]);

        $this->actingAs($user)->post('/onboarding', [
            'business_name' => 'Catalog Store',
            'agent_name' => 'Nia',
            'catalog_url' => 'https://example.com/broken-catalog',
        ])->assertRedirect('/app/channels')
            ->assertSessionHas('success', 'Setup saved, but one or more sources still need attention.')
            ->assertSessionHas('warnings', fn (array $warnings): bool => count($warnings) === 1 && str_contains($warnings[0], 'URL could not be learned'));

        $agent = $user->organizations()->firstOrFail()->agents()->firstOrFail();
        $this->assertSame('https://example.com/broken-catalog', $agent->settings['catalog_url']);
        $this->assertDatabaseHas('knowledge_sources', [
            'agent_id' => $agent->id,
            'url' => 'https://example.com/broken-catalog',
            'status' => 'failed',
        ]);

        $this->actingAs($user)->get('/app/channels')
            ->assertOk()
            ->assertSee('Source needs attention')
            ->assertSee('URL could not be learned')
            ->assertDontSee('Legatus is ready');
    }

    public function test_setup_shows_legacy_saved_urls_and_rejects_unsafe_catalog_addresses(): void
    {
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'legacy-source@example.com',
            'business_name' => 'Legacy Store',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $user = User::where('email', 'legacy-source@example.com')->firstOrFail();
        $agent = $user->organizations()->firstOrFail()->agents()->firstOrFail();
        $agent->knowledgeSources()->create([
            'type' => 'url',
            'name' => 'Legacy complete list',
            'url' => 'https://example.com/legacy-products',
            'status' => 'ready',
        ]);

        $this->actingAs($user)->get('/onboarding')
            ->assertOk()
            ->assertSee('Previously connected URL')
            ->assertSee('https://example.com/legacy-products')
            ->assertSee('data-catalog-url="https://example.com/legacy-products"', false);

        foreach (['ftp://example.com/catalog', 'https://user:secret@example.com/catalog'] as $unsafeUrl) {
            $this->actingAs($user)->post('/onboarding', [
                'business_name' => 'Legacy Store',
                'agent_name' => 'Nia',
                'catalog_url' => $unsafeUrl,
            ])->assertSessionHasErrors('catalog_url');
        }

        $this->assertSame(1, $agent->knowledgeSources()->count());
    }

    public function test_catalog_page_without_structured_products_is_not_presented_as_a_verified_catalog(): void
    {
        $this->post('/register', [
            'name' => 'Owner',
            'email' => 'unstructured-catalog@example.com',
            'business_name' => 'Unstructured Store',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
        $user = User::where('email', 'unstructured-catalog@example.com')->firstOrFail();
        Http::fake([
            'https://example.com/catalog-page' => Http::response('<html><body><h1>All products</h1><p>This page is readable business knowledge, but it contains no structured product records with verified prices.</p></body></html>'),
        ]);

        $this->actingAs($user)->post('/onboarding', [
            'business_name' => 'Unstructured Store',
            'agent_name' => 'Nia',
            'catalog_url' => 'https://example.com/catalog-page',
        ])->assertRedirect('/app/channels')
            ->assertSessionHas('warnings', fn (array $warnings): bool => count($warnings) === 1 && str_contains($warnings[0], 'did not expose structured products'));

        $agent = $user->organizations()->firstOrFail()->agents()->firstOrFail();
        $this->assertSame(0, $agent->products()->count());
        $this->assertDatabaseHas('knowledge_sources', [
            'agent_id' => $agent->id,
            'url' => 'https://example.com/catalog-page',
            'status' => 'ready',
        ]);
    }
}
