<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransferWorkspaceOwnershipCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_transfers_the_exact_workspace_and_preserves_its_data(): void
    {
        $source = User::factory()->create(['email' => 'demo@legatus.ai']);
        $target = User::factory()->create(['email' => 'pshamugia@gmail.com']);
        $organization = Organization::create(['name' => 'bukinistebi.ge', 'slug' => 'bukinistebi-demo']);
        $organization->users()->attach($source, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Nia', 'slug' => 'nia-books', 'business_name' => 'bukinistebi.ge',
            'channels' => ['web'], 'settings' => [],
        ]);
        $agent->knowledgeSources()->create([
            'type' => 'url', 'name' => 'Full catalog', 'url' => 'https://bukinistebi.ge/books',
            'status' => 'ready', 'progress' => 100,
        ]);

        $this->artisan('legatus:transfer-workspace', [
            'from-email' => 'demo@legatus.ai',
            'to-email' => 'pshamugia@gmail.com',
            '--business' => 'bukinistebi.ge',
        ])->assertSuccessful();

        $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $target->id, 'role' => 'owner']);
        $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id, 'user_id' => $source->id]);
        $this->assertDatabaseHas('knowledge_sources', ['agent_id' => $agent->id, 'name' => 'Full catalog']);
        $this->assertNotNull($organization->billingAccessGrants()->active()->first());
    }

    public function test_it_refuses_ambiguous_workspaces_without_changes(): void
    {
        $source = User::factory()->create(['email' => 'demo@legatus.ai']);
        $target = User::factory()->create(['email' => 'pshamugia@gmail.com']);
        foreach (['first', 'second'] as $slug) {
            $organization = Organization::create(['name' => 'bukinistebi.ge', 'slug' => $slug]);
            $organization->users()->attach($source, ['role' => 'owner']);
        }

        $this->artisan('legatus:transfer-workspace', [
            'from-email' => 'demo@legatus.ai', 'to-email' => 'pshamugia@gmail.com',
        ])->assertFailed();
        $this->assertDatabaseMissing('organization_user', ['user_id' => $target->id]);
    }
}
