<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteWidgetAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_turn_widget_off_without_removing_the_existing_script_and_turn_it_back_on(): void
    {
        $user = User::factory()->create();
        [$organization, $agent] = $this->workspace($user, 'owner', 'Toggle Store', ['web', 'facebook']);

        $this->actingAs($user)
            ->withSession([TenantContext::SESSION_KEY => $organization->id])
            ->patch(route('channels.widget.update'), ['enabled' => false])
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('channel_success');

        $this->assertSame(['facebook'], $agent->fresh()->channels);
        $this->get(route('channels.index'))
            ->assertOk()
            ->assertSee('data-widget-status="disabled"', false)
            ->assertSee('Website chat is hidden and cannot answer customers')
            ->assertSee('Turn website chat ON')
            ->assertSee('widget/'.$agent->slug.'.js');

        $disabledScript = $this->get(route('widget.script', $agent))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertSee("document.getElementById('legatus-widget-root')", false)
            ->assertDontSee("document.createElement('iframe')", false);
        $this->assertStringContainsString('no-store', (string) $disabledScript->headers->get('Cache-Control'));
        $this->assertFalse($disabledScript->headers->has('Set-Cookie'));

        $this->get(route('widget.frame', $agent))->assertNotFound();
        $this->get(route('chat.show', $agent))->assertNotFound();
        $this->postJson(route('chat.message', $agent), ['message' => 'Are you open?'])->assertNotFound();
        $this->getJson(route('chat.history', $agent))->assertNotFound();
        $this->postJson(route('chat.feedback', [$agent, 'not-a-real-message']), ['feedback' => 'helpful'])->assertNotFound();
        $this->assertDatabaseCount('conversations', 0);

        $this->patch(route('channels.widget.update'), ['enabled' => true])
            ->assertRedirect(route('channels.index'));

        $this->assertSame(['web', 'facebook'], $agent->fresh()->channels);
        $enabledScript = $this->get(route('widget.script', $agent))
            ->assertOk()
            ->assertSee('legatus-widget-root');
        $this->assertStringContainsString('no-store', (string) $enabledScript->headers->get('Cache-Control'));
        $this->get(route('widget.frame', $agent))->assertOk();
        $this->get(route('chat.show', $agent))->assertOk();
    }

    public function test_widget_toggle_is_authorized_and_scoped_to_the_active_business(): void
    {
        $owner = User::factory()->create();
        [$firstOrganization, $firstAgent] = $this->workspace($owner, 'owner', 'First Store', ['web']);
        [$secondOrganization, $secondAgent] = $this->workspace($owner, 'admin', 'Second Store', ['web', 'instagram']);
        $viewer = User::factory()->create();
        $secondOrganization->users()->attach($viewer, ['role' => 'viewer']);

        $this->patch(route('channels.widget.update'), ['enabled' => false])
            ->assertRedirect(route('login'));

        $this->actingAs($viewer)
            ->withSession([TenantContext::SESSION_KEY => $secondOrganization->id])
            ->patch(route('channels.widget.update'), ['enabled' => false])
            ->assertForbidden();
        $this->assertTrue($secondAgent->fresh()->websiteWidgetEnabled());

        $this->actingAs($owner)
            ->withSession([TenantContext::SESSION_KEY => $secondOrganization->id])
            ->patch(route('channels.widget.update'), ['enabled' => false])
            ->assertRedirect(route('channels.index'));

        $this->assertTrue($firstAgent->fresh()->websiteWidgetEnabled());
        $secondAgent->refresh();
        $this->assertFalse($secondAgent->websiteWidgetEnabled());
        $this->assertSame(['instagram'], $secondAgent->channels);

        $this->actingAs($viewer)
            ->withSession([TenantContext::SESSION_KEY => $secondOrganization->id])
            ->get(route('channels.index'))
            ->assertOk()
            ->assertSee('Only an owner or admin can change this.')
            ->assertDontSee('aria-label="Turn website chat on"', false);

        $this->assertNotSame($firstOrganization->id, $secondOrganization->id);
    }

    public function test_configure_save_does_not_silently_reenable_a_disabled_widget(): void
    {
        $user = User::factory()->create();
        [$organization, $agent] = $this->workspace($user, 'owner', 'Configure Store', ['facebook']);

        $this->actingAs($user)
            ->withSession([TenantContext::SESSION_KEY => $organization->id])
            ->post(route('onboarding.store'), [
                'business_name' => 'Configured Store',
                'agent_name' => 'Anna',
                'website' => '',
                'catalog_url' => '',
                'description' => 'Updated safely.',
            ])
            ->assertRedirect(route('channels.index'));

        $agent->refresh();
        $this->assertSame(['facebook'], $agent->channels);
        $this->assertFalse($agent->websiteWidgetEnabled());
    }

    public function test_legacy_null_channels_remain_enabled_until_explicitly_disabled(): void
    {
        $agent = Agent::create([
            'name' => 'Legacy Assistant',
            'slug' => 'legacy-assistant',
            'business_name' => 'Legacy Store',
            'channels' => null,
            'is_active' => true,
        ]);

        $this->assertTrue($agent->websiteWidgetEnabled());
        $this->get(route('widget.script', $agent))->assertOk()->assertSee('legatus-widget-root');
    }

    /**
     * @return array{Organization, Agent}
     */
    private function workspace(User $user, string $role, string $name, array $channels): array
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
        ]);
        $organization->users()->attach($user, ['role' => $role]);
        $agent = $organization->agents()->create([
            'name' => 'AI Assistant',
            'slug' => str($organization->slug)->append('-agent'),
            'business_name' => $name,
            'channels' => $channels,
            'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10],
            'is_active' => true,
        ]);

        return [$organization, $agent];
    }
}
