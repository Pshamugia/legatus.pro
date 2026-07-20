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
        Http::fake(['https://example.com/store' => Http::response('<html><body><h1>Returns and delivery</h1><p>Orders confirmed before six are delivered on the next business day. Returns are accepted within fourteen days.</p><script type="application/ld+json">{"@type":"Product","name":"Website Product","sku":"WEB-1","category":"Gift","description":"A verified website product","offers":{"price":"19.90","availability":"https://schema.org/InStock"}}</script></body></html>')]);
        $catalog = UploadedFile::fake()->createWithContent('catalog.csv', "name,sku,category,description,price,stock\nCatalog Product,CSV-1,Books,Imported during onboarding,24.90,8\n");

        $this->actingAs($user)->post('/onboarding', ['business_name' => 'Golden Store', 'website' => 'https://example.com/store', 'description' => 'A polished demo business.', 'catalog' => $catalog])->assertRedirect('/app/channels');

        $agent = Agent::findOrFail($agentId);
        $this->assertSame('Legatus', $agent->name);
        $this->assertSame('Golden Store', $agent->business_name);
        $this->assertDatabaseCount('agents', 1);
        $this->assertSame(2, $agent->knowledgeSources()->where('status', 'ready')->count());
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'WEB-1', 'stock' => 1]);
        $this->assertDatabaseHas('products', ['agent_id' => $agent->id, 'sku' => 'CSV-1', 'stock' => 8]);
        $this->assertSame(
            ['https://example.com', 'https://www.example.com'],
            $agent->settings['widget_allowed_origins'],
        );
    }
}
