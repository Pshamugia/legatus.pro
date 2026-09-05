<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceNavigationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_authenticated_workspace_screen_exposes_the_active_business_and_account_controls(): void
    {
        $user = User::factory()->create(['name' => 'Workspace Owner']);
        $active = $this->workspace($user, 'bukinistebi.ge');
        $other = $this->workspace($user, 'Second Store');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $active->id]);

        foreach ([
            'dashboard',
            'onboarding',
            'knowledge.index',
            'inbox.index',
            'analytics.index',
            'settings.index',
            'workspaces.index',
        ] as $routeName) {
            $response = $this->get(route($routeName));

            $response->assertOk()
                ->assertSee('Active business')
                ->assertSee('bukinistebi.ge')
                ->assertSee('data-active-business="bukinistebi.ge"', false)
                ->assertSee('data-workspace-switcher="bukinistebi.ge"', false)
                ->assertSee('Workspace on Legatus')
                ->assertSee('Business setup')
                ->assertDontSee('>Overview<', false)
                ->assertDontSee('>Channels<', false)
                ->assertDontSee('>Products<', false)
                ->assertSee('+ Add business')
                ->assertSee('Manage businesses')
                ->assertSee(route('workspaces.index'), false)
                ->assertSee('method="post" action="'.route('logout').'"', false)
                ->assertSee(route('workspaces.switch', $other), false);
        }
    }

    public function test_business_setup_uses_workspace_navigation_and_omits_duplicate_knowledge_editor(): void
    {
        $user = User::factory()->create(['name' => 'Workspace Owner']);
        $active = $this->workspace($user, 'bukinistebi.ge');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $active->id])
            ->get(route('onboarding'))
            ->assertOk()
            ->assertSee('aria-label="Workspace navigation"', false)
            ->assertSee('Customer channels')
            ->assertSee('Website chat')
            ->assertSee('Facebook and Instagram')
            ->assertDontSee('Catalog and business knowledge')
            ->assertDontSee('Previously connected URL')
            ->assertDontSee('Connected data');
    }

    public function test_guest_authentication_screens_keep_the_public_navigation_visible(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('aria-label="Public navigation"', false)
            ->assertSee('Product')
            ->assertSee('How it works');

        config(['legatus.registration_enabled' => true]);

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('aria-label="Public navigation"', false)
            ->assertSee('Product')
            ->assertSee('How it works');
    }

    public function test_navigation_uses_the_active_workspace_name_instead_of_a_stale_agent_brand(): void
    {
        $user = User::factory()->create();
        $active = $this->workspace($user, 'bukinistebi.ge');
        $active->agents()->update(['business_name' => 'Outdated imported brand']);

        $response = $this->actingAs($user)
            ->withSession([TenantContext::SESSION_KEY => $active->id])
            ->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('data-active-business="bukinistebi.ge"', false)
            ->assertSee('data-workspace-switcher="bukinistebi.ge"', false)
            ->assertDontSee('data-active-business="Outdated imported brand"', false)
            ->assertDontSee('data-workspace-switcher="Outdated imported brand"', false);
    }

    public function test_mobile_inbox_exposes_a_list_then_a_full_operator_workspace(): void
    {
        $user = User::factory()->create(['name' => 'Mobile Operator']);
        $organization = $this->workspace($user, 'Mobile Store');
        $agent = $organization->agents()->firstOrFail();
        $conversation = $agent->conversations()->create([
            'visitor_id' => 'mobile-customer',
            'customer_name' => 'Mobile Customer',
            'status' => 'ai',
            'channel' => 'web',
            'last_message_at' => now(),
        ]);
        $conversation->messages()->create(['role' => 'customer', 'content' => 'I need help on mobile.']);

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $organization->id])
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSee('class="inbox-layout"', false)
            ->assertSee('class="inbox-list"', false)
            ->assertSee('Mobile Customer');

        $this->get(route('inbox.index', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertSee('class="inbox-layout has-mobile-selection"', false)
            ->assertSee('class="inbox-mobile-back"', false)
            ->assertSee('id="operator-reply"', false)
            ->assertSee('Reply as a human operator...');

        $conversation->refresh();
        $this->assertSame('human', $conversation->status);
        $this->assertSame('Mobile Operator', $conversation->assigned_to);
    }

    private function workspace(User $user, string $name): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
        ]);
        $organization->users()->attach($user, ['role' => 'owner']);
        Agent::create([
            'organization_id' => $organization->id,
            'name' => $name.' Assistant',
            'slug' => str($organization->slug)->append('-agent'),
            'business_name' => $name,
            'channels' => ['web'],
            'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10],
        ]);

        return $organization;
    }
}
