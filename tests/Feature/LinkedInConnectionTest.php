<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'linkedin.client_id' => 'linkedin-client',
            'linkedin.client_secret' => 'linkedin-secret',
            'linkedin.oauth_url' => 'https://www.linkedin.test',
            'linkedin.api_url' => 'https://api.linkedin.test',
            'linkedin.version' => '202606',
            'linkedin.redirect_uri' => 'https://legatus.test/auth/linkedin/callback',
        ]);
    }

    public function test_owner_connects_an_administered_linkedin_page_with_encrypted_credentials(): void
    {
        [$user, $agent] = $this->tenant('linkedin-owner');
        Http::fake([
            'https://www.linkedin.test/oauth/v2/accessToken' => Http::response([
                'access_token' => 'secret-linkedin-token', 'expires_in' => 3600,
            ]),
            'https://api.linkedin.test/rest/organizationAcls*' => Http::response(['elements' => [[
                'role' => 'ADMINISTRATOR', 'state' => 'APPROVED',
                'organizationTarget' => 'urn:li:organization:778899',
            ]]]),
            'https://api.linkedin.test/rest/organizations/778899' => Http::response(['localizedName' => 'Legatus Company']),
        ]);

        $connect = $this->actingAs($user)->get(route('channels.linkedin.connect'))->assertRedirect();
        parse_str((string) parse_url($connect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('w_organization_social', collect(explode(' ', $query['scope']))->last());

        $this->get(route('channels.linkedin.callback', ['state' => $query['state'], 'code' => 'oauth-code']))
            ->assertRedirect(route('channels.index'))
            ->assertSessionHas('success');

        $connection = $agent->channelConnections()->where('provider', 'linkedin')->firstOrFail();
        $this->assertSame('778899', $connection->external_account_id);
        $this->assertSame('Legatus Company', $connection->external_account_name);
        $this->assertSame('secret-linkedin-token', $connection->access_token);
        $this->assertStringNotContainsString('secret-linkedin-token', (string) $connection->getRawOriginal('access_token'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/rest/organizationAcls')
            && $request->hasHeader('LinkedIn-Version', '202606')
            && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0'));
    }

    public function test_linkedin_callback_rejects_an_invalid_oauth_state(): void
    {
        [$user] = $this->tenant('linkedin-state');
        $this->actingAs($user)->get(route('channels.linkedin.callback', ['state' => 'forged', 'code' => 'code']))
            ->assertForbidden();
        Http::assertNothingSent();
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
