<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Tests\TestCase;

class WorkspaceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_actions_require_an_authenticated_session(): void
    {
        $workspace = Organization::create(['name' => 'Private', 'slug' => 'private']);

        $this->get(route('workspaces.index'))->assertRedirect(route('login'));
        $this->post(route('workspaces.store'), ['business_name' => 'New'])->assertRedirect(route('login'));
        $this->post(route('workspaces.switch', $workspace))->assertRedirect(route('login'));

        $router = app('router');
        foreach (['workspaces.index', 'workspaces.store', 'workspaces.switch'] as $routeName) {
            $middleware = $router->gatherRouteMiddleware($router->getRoutes()->getByName($routeName));
            $this->assertContains(EncryptCookies::class, $middleware);
            $this->assertContains(StartSession::class, $middleware);
            $this->assertContains(ValidateCsrfToken::class, $middleware);
            $this->assertContains('auth', $router->getRoutes()->getByName($routeName)->gatherMiddleware());
        }
    }

    public function test_list_contains_only_memberships_and_marks_the_active_business(): void
    {
        $user = User::factory()->create();
        $first = $this->workspace($user, 'First Business', 'owner');
        $second = $this->workspace($user, 'Second Business', 'viewer');
        $outsider = User::factory()->create();
        $foreign = $this->workspace($outsider, 'Foreign Secret Business', 'owner');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $second->id])
            ->get(route('workspaces.index'))
            ->assertOk()
            ->assertSee('First Business')
            ->assertSee('Second Business')
            ->assertSee('Viewer')
            ->assertSee('data-workspace-id="'.$second->id.'"', false)
            ->assertDontSee('Foreign Secret Business')
            ->assertDontSee('data-workspace-id="'.$foreign->id.'"', false)
            ->assertSessionHas(TenantContext::SESSION_KEY, $second->id);

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_user_can_create_an_isolated_second_business_and_continue_to_onboarding(): void
    {
        $user = User::factory()->create();
        $first = $this->workspace($user, 'First Business', 'owner');
        $organizationsBefore = Organization::count();
        $agentsBefore = Agent::count();

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $first->id])
            ->post(route('workspaces.store'), ['business_name' => " \u{00A0}მეორე ბიზნესი\u{2003} "])
            ->assertRedirect(route('onboarding'));

        $second = Organization::where('name', 'მეორე ბიზნესი')->firstOrFail();
        $secondAgent = $second->agents()->firstOrFail();
        $this->assertSame($organizationsBefore + 1, Organization::count());
        $this->assertSame($agentsBefore + 1, Agent::count());
        $this->assertSame('owner', $second->users()->whereKey($user->id)->firstOrFail()->pivot->role);
        $this->assertSame('AI Assistant', $secondAgent->name);
        $this->assertSame('მეორე ბიზნესი', $secondAgent->business_name);
        $this->assertSame(['web'], $secondAgent->channels);
        $this->assertSame($second->id, session(TenantContext::SESSION_KEY));
        $this->assertDatabaseHas('organizations', ['id' => $first->id, 'name' => 'First Business']);

        $this->get(route('onboarding'))
            ->assertOk()
            ->assertSee('value="მეორე ბიზნესი"', false)
            ->assertDontSee('value="First Business"', false);
    }

    public function test_switch_changes_all_tenant_scoped_pages_to_the_selected_membership(): void
    {
        $user = User::factory()->create();
        $first = $this->workspace($user, 'First Business', 'owner', 'First Assistant');
        $second = $this->workspace($user, 'Second Business', 'admin', 'Second Assistant');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $first->id])
            ->post(route('workspaces.switch', $second))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas(TenantContext::SESSION_KEY, $second->id)
            ->assertSessionHas('status', 'Switched to Second Business.');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Second Business')
            ->assertSee('Second Assistant')
            ->assertDontSee('First Assistant');
        $this->assertSame($second->id, app(TenantContext::class)->organization($user)->id);
    }

    public function test_switching_to_another_users_business_is_not_found_and_keeps_current_context(): void
    {
        $user = User::factory()->create();
        $current = $this->workspace($user, 'Current Business', 'owner');
        $outsider = User::factory()->create();
        $foreign = $this->workspace($outsider, 'Foreign Business', 'owner');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $current->id])
            ->post(route('workspaces.switch', $foreign))
            ->assertNotFound()
            ->assertSessionHas(TenantContext::SESSION_KEY, $current->id);

        $this->assertSame($current->id, app(TenantContext::class)->organization($user)->id);
    }

    public function test_fresh_or_stale_session_falls_back_to_lowest_member_organization_id(): void
    {
        $user = User::factory()->create();
        $first = Organization::create(['name' => 'Deterministic First', 'slug' => 'deterministic-first']);
        $second = Organization::create(['name' => 'Attached First But Higher ID', 'slug' => 'higher-id']);
        $second->users()->attach($user, ['role' => 'admin']);
        $first->users()->attach($user, ['role' => 'viewer']);
        $this->agent($first, 'First Agent');
        $this->agent($second, 'Second Agent');
        $foreign = Organization::create(['name' => 'Not a Member', 'slug' => 'not-a-member']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Deterministic First')
            ->assertSessionHas(TenantContext::SESSION_KEY, $first->id);

        $this->withSession([TenantContext::SESSION_KEY => $foreign->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Deterministic First')
            ->assertSessionHas(TenantContext::SESSION_KEY, $first->id);
    }

    public function test_logout_invalidates_active_workspace_and_next_login_uses_fresh_fallback(): void
    {
        $user = User::factory()->create([
            'email' => 'multi@example.com',
            'password' => 'password123',
        ]);
        $first = $this->workspace($user, 'First After Login', 'owner');
        $second = $this->workspace($user, 'Selected Before Logout', 'owner');

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $second->id])
            ->post(route('logout'))
            ->assertRedirect(route('landing'))
            ->assertSessionMissing(TenantContext::SESSION_KEY);
        $this->assertGuest();

        $this->post(route('login.store'), [
            'email' => 'multi@example.com',
            'password' => 'password123',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('First After Login')
            ->assertSessionHas(TenantContext::SESSION_KEY, $first->id);
    }

    public function test_invalid_creation_is_atomic_and_does_not_change_the_active_business(): void
    {
        $user = User::factory()->create();
        $active = $this->workspace($user, 'Safe Business', 'owner');
        $organizationCount = Organization::count();
        $agentCount = Agent::count();

        $this->actingAs($user)->withSession([TenantContext::SESSION_KEY => $active->id])
            ->post(route('workspaces.store'), ['business_name' => "Unsafe\nBusiness"])
            ->assertRedirect()
            ->assertSessionHasErrors('business_name')
            ->assertSessionHas(TenantContext::SESSION_KEY, $active->id);

        $this->assertSame($organizationCount, Organization::count());
        $this->assertSame($agentCount, Agent::count());
    }

    private function workspace(User $user, string $name, string $role, string $agentName = 'AI Assistant'): Organization
    {
        $organization = Organization::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.str()->lower(str()->random(6)),
        ]);
        $organization->users()->attach($user, ['role' => $role]);
        $this->agent($organization, $agentName);

        return $organization;
    }

    private function agent(Organization $organization, string $name): Agent
    {
        return $organization->agents()->create([
            'name' => $name,
            'slug' => str($organization->slug)->append('-agent-', str()->lower(str()->random(5))),
            'business_name' => $organization->name,
            'channels' => ['web'],
            'settings' => ['handoff_threshold' => .72, 'discount_limit' => 10],
        ]);
    }
}
