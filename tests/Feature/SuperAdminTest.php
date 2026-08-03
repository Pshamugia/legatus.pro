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
}
