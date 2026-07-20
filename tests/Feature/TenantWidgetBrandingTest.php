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

    public function test_launcher_uses_the_business_name_while_the_frame_receives_the_chosen_assistant_name(): void
    {
        $agent = $this->brandedAgent();

        $response = $this->get("/widget/{$agent->slug}.js")
            ->assertOk()
            ->assertSee('businessName="Bukinistebi.ge"', false)
            ->assertSee('assistantName="Maya"', false)
            ->assertSee("label.textContent='Ask '+businessName", false)
            ->assertSee("frame.title=assistantName+' · '+businessName+' AI shopping assistant'", false)
            ->assertDontSee('Ask Legatus')
            ->assertDontSee('Open Legatus shopping assistant')
            ->assertDontSee('Ask Maya');

        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    public function test_tenant_and_assistant_names_are_encoded_as_data_in_the_widget_script(): void
    {
        $agent = $this->brandedAgent();
        $agent->update([
            'name' => 'Helper </script><script>alert("assistant")</script>',
            'business_name' => 'Shop </script><script>alert(1)</script>',
        ]);

        $content = (string) $this->get("/widget/{$agent->slug}.js")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $content);
        $this->assertStringNotContainsString('</script><script>alert("assistant")</script>', $content);
        $this->assertStringContainsString('Shop \\u003C\/script\\u003E\\u003Cscript\\u003Ealert(1)\\u003C\/script\\u003E', $content);
        $this->assertStringContainsString('Helper \\u003C\/script\\u003E\\u003Cscript\\u003Ealert(\\u0022assistant\\u0022)\\u003C\/script\\u003E', $content);

        $frame = (string) $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('</script><script>alert("assistant")</script>', $frame);
        $this->assertStringNotContainsString('</script><script>alert(1)</script>', $frame);
        $this->assertStringContainsString('Helper &lt;/script&gt;&lt;script&gt;alert(&quot;assistant&quot;)&lt;/script&gt;', $frame);
    }

    public function test_widget_frame_and_public_chat_present_the_chosen_assistant_and_business_names(): void
    {
        $agent = $this->brandedAgent();

        $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->assertSee('<title>Maya · Bukinistebi.ge</title>', false)
            ->assertSee('<b>Maya · Bukinistebi.ge</b>', false)
            ->assertSee('id="new-conversation"', false)
            ->assertSee('ახალი საუბრის დაწყება')
            ->assertSee("clearToken();\n        window.location.reload();", false)
            ->assertSee('მე ვარ Maya — Bukinistebi.ge-ის AI ასისტენტი')
            ->assertSee('Powered by Legatus')
            ->assertDontSee('Ask Maya');

        $this->get(route('chat.show', $agent))
            ->assertOk()
            ->assertSee('<strong>Maya · Bukinistebi.ge</strong>', false)
            ->assertSee('მე ვარ Maya — Bukinistebi.ge-ის AI ასისტენტი')
            ->assertSee('Powered by Legatus')
            ->assertSee('ვამოწმებ გადამოწმებულ მონაცემებს')
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
        $this->assertStringContainsString('მე ვარ Maya — Bukinistebi.ge-ის AI ასისტენტი', $reply['text']);

        $method = new ReflectionMethod(OpenAiSalesOrchestrator::class, 'instructions');
        $instructions = $method->invoke(app(OpenAiSalesOrchestrator::class), $agent);

        $this->assertStringContainsString('You are Maya, the autonomous but careful AI sales assistant representing Bukinistebi.ge', $instructions);
        $this->assertStringContainsString("identify yourself as Maya, Bukinistebi.ge's AI assistant", $instructions);
    }

    public function test_legacy_legatus_name_falls_back_without_becoming_the_business_chat_identity(): void
    {
        $agent = $this->brandedAgent();
        $agent->update(['name' => 'Legatus']);

        $script = (string) $this->get("/widget/{$agent->slug}.js")
            ->assertOk()
            ->assertSee('assistantName="AI Assistant"', false)
            ->assertDontSee('assistantName="Legatus"', false)
            ->getContent();
        $this->assertStringContainsString("label.textContent='Ask '+businessName", $script);

        $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->assertSee('<b>AI Assistant · Bukinistebi.ge</b>', false)
            ->assertSee('მე ვარ Bukinistebi.ge-ის AI ასისტენტი')
            ->assertDontSee('მე ვარ Legatus');

        config([
            'services.openai.key' => null,
            'legatus.offline_fallback_enabled' => true,
        ]);
        $reply = app(SalesAgentService::class)->reply($agent->fresh(), 'Hello');
        $this->assertStringContainsString('მე ვარ Bukinistebi.ge-ის AI ასისტენტი', $reply['text']);
        $this->assertStringNotContainsString('Legatus', $reply['text']);

        $method = new ReflectionMethod(OpenAiSalesOrchestrator::class, 'instructions');
        $instructions = $method->invoke(app(OpenAiSalesOrchestrator::class), $agent->fresh());
        $this->assertStringNotContainsString('You are Legatus', $instructions);
        $this->assertStringContainsString("identify yourself as Bukinistebi.ge's AI assistant", $instructions);

        $agent->update(['name' => 'AI Assistant']);
        $this->assertFalse($agent->fresh()->hasCustomAssistantName());
        $this->get("/widget/{$agent->slug}")
            ->assertOk()
            ->assertSee('<b>AI Assistant · Bukinistebi.ge</b>', false)
            ->assertSee('მე ვარ Bukinistebi.ge-ის AI ასისტენტი')
            ->assertDontSee('მე ვარ AI Assistant');
    }

    private function brandedAgent(): Agent
    {
        $this->seed();
        $agent = Agent::firstOrFail();
        $agent->update([
            'name' => 'Maya',
            'business_name' => 'Bukinistebi.ge',
        ]);

        return $agent->fresh();
    }
}
