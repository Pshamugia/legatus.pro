<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Models\MetaOAuthSelection;
use App\Services\LinkedInClient;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LinkedInConnectionController extends Controller
{
    public function connect(Request $request, TenantContext $tenant, LinkedInClient $linkedin): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_if(! config('linkedin.client_id') || ! config('linkedin.client_secret'), 503, 'LinkedIn connection is not configured.');
        $state = Str::random(64);
        $oauth = ['state' => $state, 'agent_id' => $tenant->agent()->id, 'user_id' => $request->user()->id, 'expires_at' => now()->addMinutes(20)->timestamp];
        $request->session()->put('linkedin_oauth', $oauth);
        Cache::put('linkedin_oauth_state:'.hash('sha256', $state), $oauth, now()->addMinutes(20));

        return redirect()->away($linkedin->authorizationUrl($state, $this->redirectUri()));
    }

    public function callback(Request $request, TenantContext $tenant, LinkedInClient $linkedin): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $state = (string) $request->query('state', '');
        $oauth = collect([$request->session()->pull('linkedin_oauth'), Cache::pull('linkedin_oauth_state:'.hash('sha256', $state))])
            ->first(fn ($item): bool => is_array($item) && hash_equals((string) ($item['state'] ?? ''), $state)
                && (int) ($item['agent_id'] ?? 0) === $tenant->agent()->id
                && (int) ($item['user_id'] ?? 0) === $request->user()->id
                && (int) ($item['expires_at'] ?? 0) >= now()->timestamp);
        abort_unless(is_array($oauth), 403, 'The LinkedIn authorization request is invalid or expired.');
        if ($request->filled('error')) {
            return to_route('channels.index')->with('error', 'LinkedIn authorization was cancelled or denied.');
        }

        try {
            $token = $linkedin->exchangeCode((string) $request->query('code'), $this->redirectUri());
            $organizations = $linkedin->managedOrganizations((string) $token['access_token']);
        } catch (\Throwable) {
            return to_route('channels.index')->with('error', 'LinkedIn could not be connected. Verify app access and try again.');
        }
        if ($organizations === []) {
            return to_route('channels.index')->with('error', 'No LinkedIn Page you can publish to was found.');
        }
        $candidates = collect($organizations)->map(fn (array $organization): array => $organization + [
            'access_token' => $token['access_token'],
            'token_expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in'])->toIso8601String() : null,
        ])->all();
        if (count($candidates) === 1) {
            $this->persist($tenant->agent()->id, $candidates[0]);
            return to_route('channels.index')->with('success', 'LinkedIn connected to '.$candidates[0]['name'].'.');
        }
        $selection = Str::random(64);
        MetaOAuthSelection::create([
            'agent_id' => $tenant->agent()->id, 'user_id' => $request->user()->id, 'provider' => 'linkedin',
            'selector_hash' => hash('sha256', $selection), 'candidates' => $candidates, 'expires_at' => now()->addMinutes(10),
        ]);
        return to_route('channels.linkedin.selection', ['selection' => $selection]);
    }

    public function selection(string $selection, Request $request, TenantContext $tenant): View|RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $pending = $this->pending($selection, $request, $tenant);
        return $pending ? view('linkedin-account-selection', ['selectionToken' => $selection, 'accounts' => $pending->candidates])
            : to_route('channels.index')->with('error', 'This LinkedIn Page selection expired. Connect again.');
    }

    public function select(string $selection, Request $request, TenantContext $tenant): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate(['organization_id' => 'required|string|max:128']);
        $pending = $this->pending($selection, $request, $tenant);
        abort_unless($pending, 422, 'LinkedIn selection expired.');
        $candidate = collect($pending->candidates)->firstWhere('id', $data['organization_id']);
        abort_unless(is_array($candidate), 422, 'Choose an eligible LinkedIn Page.');
        DB::transaction(function () use ($tenant, $candidate, $pending): void {
            $this->persist($tenant->agent()->id, $candidate);
            $pending->delete();
        });
        return to_route('channels.index')->with('success', 'LinkedIn connected to '.$candidate['name'].'.');
    }

    public function disconnect(ChannelConnection $connection, TenantContext $tenant): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($connection->agent_id === $tenant->agent()->id && $connection->provider === 'linkedin', 404);
        $connection->delete();
        return to_route('channels.index')->with('success', 'LinkedIn disconnected.');
    }

    private function persist(int $agentId, array $candidate): void
    {
        throw_if(ChannelConnection::where('provider', 'linkedin')->where('external_account_id', $candidate['id'])->where('agent_id', '!=', $agentId)->exists(), new \RuntimeException('LinkedIn Page already connected.'));
        ChannelConnection::updateOrCreate(['agent_id' => $agentId, 'provider' => 'linkedin'], [
            'status' => 'active', 'external_account_id' => $candidate['id'], 'external_account_name' => $candidate['name'],
            'access_token' => $candidate['access_token'], 'token_expires_at' => $candidate['token_expires_at'],
            'metadata' => ['organization_urn' => $candidate['urn']], 'connected_at' => now(), 'last_error' => null,
        ]);
    }

    private function pending(string $selection, Request $request, TenantContext $tenant): ?MetaOAuthSelection
    {
        return MetaOAuthSelection::where('selector_hash', hash('sha256', $selection))->where('provider', 'linkedin')
            ->where('agent_id', $tenant->agent()->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->first();
    }

    private function redirectUri(): string
    {
        return config('linkedin.redirect_uri') ?: route('channels.linkedin.callback');
    }
}
