<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Services\OpenAiSalesOrchestrator;
use App\Services\SalesAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class TenantWidgetBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_launcher_uses_the_tenant_business_name_instead_of_the_internal_agent_name(): void
    {
        $agent = $this->brandedAgent();

        $response = $this->get("/widget/{$agent->slug}.js")
            ->assertOk()
            ->assertSee('businessName="Bukinistebi.ge"', false)
            ->assertSee("label.textContent='Ask '+businessName", false)
            ->assertSee("frame.title=businessName+' AI shopping assistant'", false)
            ->assertDontSee('Ask Legatus')
            ->assertDontSee('Open Legatus shopping assistant');

        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_tenant_name_is_encoded_as_data_in_the_widget_script(): void
    {
        $agent = $this->brandedAgent();
        $agent->update(['business_name' => 'Shop </script><script>alert(1)</script>']);

        $content = (string) $this->get("/widget/{$agent->slug}.js")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $content);
        $this->assertStringContainsString('Shop \\u003C\/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C\/script\\u003E', $content);
    }

    public function test_widget_frame_and_public_chat_present_the_business_as_the_chat_identity(): void
    {
        $agent = $this->brandedAgent();

        $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->assertSee('<title>Bukinistebi.ge · AI assistant</title>', false)
            ->assertSee('<b>Bukinistebi.ge</b>', false)
            ->assertSee('მე ვარ Bukinistebi.ge-ის AI ასისტენტი')
            ->assertSee('Powered by Legatus')
            ->assertDontSee('Internal Concierge');

        $this->get(route('chat.show', $agent))
            ->assertOk()
            ->assertSee('<strong>Bukinistebi.ge</strong>', false)
            ->assertSee('მე ვარ Bukinistebi.ge-ის AI ასისტენტი')
            ->assertSee('Powered by Legatus')
            ->assertSee('ვამოწმებ გადამოწმებულ მონაცემებს')
            ->assertDontSee('Internal Concierge')
            ->assertDontSee('Legatus is checking verified data');
    }

    public function test_generated_and_fallback_replies_use_the_tenant_identity(): void
    {
        $agent = $this->brandedAgent();

        config([
            'services.openai.key' => null,
            'legatus.offline_fallback_enabled' => true,
        ]);

        $reply = app(SalesAgentService::class)->reply($agent, 'Hello');
        $this->assertStringContainsString('Bukinistebi.ge-ის AI ასისტენტი', $reply['text']);
        $this->assertStringNotContainsString('Internal Concierge', $reply['text']);

        $method = new ReflectionMethod(OpenAiSalesOrchestrator::class, 'instructions');
        $instructions = $method->invoke(app(OpenAiSalesOrchestrator::class), $agent);

        $this->assertStringContainsString('representing Bukinistebi.ge', $instructions);
        $this->assertStringContainsString("identify yourself as Bukinistebi.ge's AI assistant", $instructions);
        $this->assertStringNotContainsString('You are Internal Concierge', $instructions);
    }

    private function brandedAgent(): Agent
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update([
            'name' => 'Internal Concierge',
            'business_name' => 'Bukinistebi.ge',
        ]);

        return $agent->fresh();
    }
}
