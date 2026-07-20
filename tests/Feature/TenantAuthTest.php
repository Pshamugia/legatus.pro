<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_registration_creates_isolated_workspace_and_owner(): void
    {
        $this->post('/register', ['name' => 'Lia', 'email' => 'lia@example.com', 'business_name' => 'Lia Store', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect('/onboarding');
        $user = User::where('email', 'lia@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $org = $user->organizations()->firstOrFail();
        $this->assertSame('owner', $org->pivot->role);
        $this->assertSame('Lia Store', $org->agents()->first()->business_name);
    }

    public function test_member_cannot_access_another_tenants_conversation(): void
    {
        $this->seed();
        $owner = User::first();
        $other = User::factory()->create();
        $org = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $org->users()->attach($other, ['role' => 'owner']);
        $otherAgent = $org->agents()->create(['name' => 'Other Legatus', 'slug' => 'other-legatus', 'business_name' => 'Other']);
        $conversation = $otherAgent->conversations()->create(['visitor_id' => 'secret', 'status' => 'ai', 'channel' => 'web']);
        $this->actingAs($owner)->post("/app/inbox/{$conversation->id}/close")->assertNotFound();
    }
}
