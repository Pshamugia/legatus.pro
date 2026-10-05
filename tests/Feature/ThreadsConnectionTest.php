<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ThreadsConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'threads.app_id' => 'threads-app',
            'threads.app_secret' => 'threads-secret',
            'threads.authorization_url' => 'https://threads.test',
            'threads.api_url' => 'https://graph.threads.test',
            'threads.redirect_uri' => 'https://legatus.test/auth/threads/callback',
            'threads.scopes' => ['threads_basic', 'threads_content_publish'],
        ]);
    }

    public function test_owner_connects_a_threads_profile_with_a_long_lived_encrypted_token(): void
    {
        [$user, $agent] = $this->tenant('threads-owner');
        Http::fake([
            'https://graph.threads.test/oauth/access_token' => Http::response([
                'access_token' => 'short-token', 'user_id' => '9911',
            ]),
            'https://graph.threads.test/access_token*' => Http::response([
                'access_token' => 'long-secret-token', 'token_type' => 'bearer', 'expires_in' => 5184000,
            ]),
            'https://graph.threads.test/me*' => Http::response([
                'id' => '9911', 'username' => 'legatus_profile', 'name' => 'Legatus',
            ]),
        ]);

        $connect = $this->actingAs($user)->get(route('channels.threads.connect'))->assertRedirect();
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('threads_basic,threads_content_publish', $query['scope']);

        $this->get(route('channels.threads.callback', ['state' => $query['state'], 'code' => 'oauth-code']))
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');

        $connection = $agent->channelConnections()->where('provider', 'threads')->firstOrFail();
        $this->assertSame('9911', $connection->external_account_id);
        $this->assertSame('legatus_profile', $connection->external_account_name);
        $this->assertSame('long-secret-token', $connection->access_token);
        $this->assertStringNotContainsString('long-secret-token', (string) $connection->getRawOriginal('access_token'));
        $this->assertTrue($connection->token_expires_at->isFuture());
    }

    public function test_threads_callback_rejects_a_forged_oauth_state(): void
    {
        [$user] = $this->tenant('threads-state');

        $this->actingAs($user)->get(route('channels.threads.callback', ['state' => 'forged', 'code' => 'code']))
            ->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_business_setup_shows_the_threads_connection_between_meta_and_linkedin(): void
    {
        [$user] = $this->tenant('threads-onboarding');

        $this->actingAs($user)->get(route('onboarding'))
            ->assertOk()
            ->assertSeeInOrder([
                'Facebook and Instagram',
                'Threads publishing',
                'LinkedIn company page',
                'WhatsApp Business',
            ])
            ->assertSee('data-channel="threads" data-status="disconnected"', false)
            ->assertSee('Connect Threads')
            ->assertSee(route('channels.threads.connect'), false);
    }

    private function tenant(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => $slug, 'slug' => $slug]);
        $organization->users()->attach($user, ['role' => 'owner']);
        $agent = $organization->agents()->create([
            'name' => 'Assistant', 'slug' => $slug.'-agent', 'business_name' => $slug,
            'channels' => ['web'], 'settings' => [], 'is_active' => true,
        ]);

        return [$user, $agent];
    }
}
