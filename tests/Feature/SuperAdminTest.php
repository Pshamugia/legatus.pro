<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaddleSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_configured_email_can_open_super_admin(): void
    {
        $admin = User::factory()->create(['email' => 'pshamugia@gmail.com']);
        $regular = User::factory()->create(['email' => 'owner@example.com']);

        $this->get(route('super-admin.index'))->assertRedirect(route('login'));
        $this->actingAs($regular)->get(route('super-admin.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('super-admin.index'))->assertOk();
    }

    public function test_super_admin_sees_business_owner_package_and_subscription_dates(): void
    {
        config([
            'paddle.environment' => 'live',
            'paddle.prices.yearly' => 'pri_yearly',
        ]);
        $admin = User::factory()->create(['email' => 'pshamugia@gmail.com']);
        $owner = User::factory()->create(['name' => 'Business Owner', 'email' => 'owner@example.com']);
        $organization = Organization::create(['name' => 'Acme Store', 'slug' => 'acme-store']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        PaddleSubscription::create([
            'organization_id' => $organization->id,
            'environment' => 'live',
            'paddle_subscription_id' => 'sub_live_123',
            'paddle_price_id' => 'pri_yearly',
            'status' => 'active',
            'current_period_ends_at' => now()->addYear(),
            'paddle_occurred_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('super-admin.index'))
            ->assertOk()
            ->assertSee('Acme Store')
            ->assertSee('Business Owner')
            ->assertSee('owner@example.com')
            ->assertSee('$288 / year')
            ->assertSee('Active')
            ->assertSee('sub_live_123');
    }

    public function test_super_admin_search_filters_businesses(): void
    {
        $admin = User::factory()->create(['email' => 'pshamugia@gmail.com']);
        Organization::create(['name' => 'Visible Store', 'slug' => 'visible-store']);
        Organization::create(['name' => 'Hidden Store', 'slug' => 'hidden-store']);

        $this->actingAs($admin)->get(route('super-admin.index', ['search' => 'Visible']))
            ->assertOk()
            ->assertSee('Visible Store')
            ->assertDontSee('Hidden Store');
    }

    public function test_super_admin_can_grant_and_revoke_complimentary_business_access(): void
    {
        config([
            'paddle.billing_enforced' => true,
            'paddle.client_token' => 'live_token',
            'paddle.prices' => ['monthly' => 'pri_1', 'six_months' => 'pri_2', 'yearly' => 'pri_3'],
        ]);
        $admin = User::factory()->create(['email' => 'pshamugia@gmail.com']);
        $owner = User::factory()->create(['email' => 'friend@example.com']);
        $organization = Organization::create(['name' => 'Gift Store', 'slug' => 'gift-store']);
        $organization->users()->attach($owner, ['role' => 'owner']);
        $organization->agents()->create(['name' => 'Gift Assistant', 'slug' => 'gift-assistant', 'business_name' => 'Gift Store', 'channels' => ['web'], 'settings' => []]);

        $this->actingAs($owner)->get('/app')->assertRedirect(route('billing.index'));
        $this->actingAs($admin)->post(route('super-admin.access.grant', $organization), [
            'duration' => '6_months',
            'reason' => 'Gift from Legatus',
        ])->assertRedirect();

        $grant = $organization->billingAccessGrants()->active()->firstOrFail();
        $this->assertSame('Gift from Legatus', $grant->reason);
        $this->assertTrue($grant->expires_at->between(now()->addMonths(6)->subMinute(), now()->addMonths(6)->addMinute()));
        $this->actingAs($owner)->get('/app')->assertOk();
        $this->actingAs($admin)->get(route('super-admin.index'))->assertSee('Gift from Legatus')->assertSee('Complimentary');

        $this->actingAs($admin)->delete(route('super-admin.access.revoke', $organization))->assertRedirect();
        $this->assertNull($organization->billingAccessGrants()->active()->first());
        $this->actingAs($owner)->get('/app')->assertRedirect(route('billing.index'));
    }

    public function test_regular_user_cannot_manage_complimentary_access(): void
    {
        $regular = User::factory()->create(['email' => 'owner@example.com']);
        $organization = Organization::create(['name' => 'Protected Store', 'slug' => 'protected-store']);

        $this->actingAs($regular)->post(route('super-admin.access.grant', $organization), [
            'duration' => 'lifetime',
        ])->assertForbidden();
        $this->assertDatabaseCount('billing_access_grants', 0);
    }
}
