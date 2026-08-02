<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Models\MetaOAuthSelection;
use App\Services\MetaGraphClient;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MetaConnectionController extends Controller
{
    public function connect(string $provider, Request $request, TenantContext $tenant, MetaGraphClient $meta): RedirectResponse
    {
        $this->provider($provider);
        $tenant->authorize(['owner', 'admin']);
        abort_if(! config('meta.app_id') || ! config('meta.app_secret'), 503, 'Meta connection is not configured.');

        $agent = $tenant->agent();
        MetaOAuthSelection::query()->where('expires_at', '<=', now())->delete();
        MetaOAuthSelection::query()
            ->where('agent_id', $agent->id)
            ->where('user_id', $request->user()->id)
            ->where('provider', $provider)
            ->delete();

        $state = Str::random(64);
        $oauth = [
            'state' => $state,
            'provider' => $provider,
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(20)->timestamp,
        ];
        $request->session()->put('meta_oauth', $oauth);
        Cache::put($this->oauthStateKey($state), $oauth, now()->addMinutes(20));

        return redirect()->away($meta->authorizationUrl($provider, $state, $this->redirectUri($provider)));
    }

    public function callback(string $provider, Request $request, TenantContext $tenant, MetaGraphClient $meta): RedirectResponse
    {
        $this->provider($provider);
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        $state = is_string($request->query('state')) ? (string) $request->query('state') : '';
        $sessionOauth = $request->session()->pull('meta_oauth');
        $cachedOauth = $state !== '' ? Cache::pull($this->oauthStateKey($state)) : null;
        $oauth = collect([$sessionOauth, $cachedOauth])->first(
            fn (mixed $candidate): bool => $this->validOauthState(
                $candidate,
                $state,
                $provider,
                $agent->id,
                (int) $request->user()->id
            )
        );

        abort_unless(is_array($oauth), 403, 'The Meta authorization request is invalid or expired.');

        if ($request->filled('error')) {
            return to_route('channels.index')->with('error', 'Meta authorization was cancelled or denied.');
        }

        $code = (string) $request->query('code', '');
        abort_if($code === '', 422, 'Meta did not return an authorization code.');

        try {
            $token = $meta->exchangeCode($code, $this->redirectUri($provider));
            $accounts = $meta->managedAccounts($token['access_token']);
            $candidates = $this->eligibleCandidates($provider, $accounts, $agent->id);
        } catch (\Throwable) {
            return to_route('channels.index')->with('error', 'Meta could not be connected. Please try again.');
        }

        if ($candidates->isEmpty()) {
            return to_route('channels.index')->with('error', $provider === 'instagram'
                ? 'No eligible Instagram Professional account was found.'
                : 'No eligible Facebook Page was found.');
        }

        if ($candidates->count() === 1) {
            try {
                $connections = DB::transaction(fn () => $this->persistCandidateSet($provider, $candidates->first(), $agent->id));
            } catch (\Throwable) {
                return to_route('channels.index')->with('error', 'That Meta account is already connected elsewhere or is no longer eligible.');
            }

            $this->subscribeAll($connections, $meta);

            return $this->connectionResult($provider, $connections);
        }

        $selectionToken = Str::random(64);
        MetaOAuthSelection::query()->create([
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'provider' => $provider,
            'selector_hash' => hash('sha256', $selectionToken),
            'candidates' => $candidates->values()->all(),
            'expires_at' => now()->addMinutes(10),
        ]);

        return to_route('channels.meta.selection', [
            'provider' => $provider,
            'selection' => $selectionToken,
        ]);
    }

    public function selection(string $provider, string $selection, Request $request, TenantContext $tenant): View|RedirectResponse
    {
        $this->provider($provider);
        $tenant->authorize(['owner', 'admin']);
        $pending = $this->pendingSelection($provider, $selection, $request, $tenant);
        if (! $pending) {
            return to_route('channels.index')->with('error', 'This Meta account selection expired. Connect again.');
        }

        $accounts = collect($pending->candidates)->map(fn (array $candidate): array => [
            'candidate_id' => $candidate['candidate_id'],
            'name' => $candidate['name'],
            'description' => match ($provider) {
                'instagram' => 'Instagram Professional account'.(! empty($candidate['page_name']) ? ' · linked to '.$candidate['page_name'] : ''),
                'meta' => ! empty($candidate['instagram'])
                    ? 'Facebook Page · includes Instagram @'.($candidate['instagram']['name'] ?? 'account')
                    : 'Facebook Page · no linked Instagram Professional account found',
                default => 'Facebook Page',
            },
        ])->values();

        return view('meta-account-selection', [
            'provider' => $provider,
            'selectionToken' => $selection,
            'accounts' => $accounts,
            'expiresAt' => $pending->expires_at,
        ]);
    }

    public function select(string $provider, string $selection, Request $request, TenantContext $tenant, MetaGraphClient $meta): RedirectResponse
    {
        $this->provider($provider);
        $tenant->authorize(['owner', 'admin']);
        $data = $request->validate(['candidate_id' => 'required|string|max:128']);
        $agent = $tenant->agent();

        try {
            $connections = DB::transaction(function () use ($provider, $selection, $request, $agent, $data): Collection {
                $pending = MetaOAuthSelection::query()
                    ->where('selector_hash', hash('sha256', $selection))
                    ->where('provider', $provider)
                    ->where('agent_id', $agent->id)
                    ->where('user_id', $request->user()->id)
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->firstOrFail();
                $candidate = collect($pending->candidates)->firstWhere('candidate_id', $data['candidate_id']);
                abort_unless(is_array($candidate), 422, 'Choose an eligible Meta account.');

                $connections = $this->persistCandidateSet($provider, $candidate, $agent->id);
                $pending->delete();

                return $connections;
            });
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return to_route('channels.index')->with('error', 'That Meta account could not be connected. Please connect again.');
        }

        $this->subscribeAll($connections, $meta);

        return $this->connectionResult($provider, $connections);
    }

    public function disconnect(ChannelConnection $connection, TenantContext $tenant, MetaGraphClient $meta): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($connection->agent_id === $tenant->agent()->id, 404);
        $provider = $connection->provider;
        $graphAccountId = $connection->graphAccountId();
        $activeSiblingExists = ChannelConnection::query()
            ->whereKeyNot($connection->id)
            ->where('status', 'active')
            ->get()
            ->contains(fn (ChannelConnection $candidate): bool => $candidate->isActive() && hash_equals($graphAccountId, $candidate->graphAccountId()));

        if (! $activeSiblingExists) {
            try {
                $meta->unsubscribe($connection);
            } catch (\Throwable) {
                // Deleting the encrypted local credential remains authoritative.
            }
        }
        $connection->delete();

        return to_route('channels.index')->with('success', ucfirst($provider).' disconnected.');
    }

    private function eligibleCandidates(string $provider, array $accounts, int $agentId)
    {
        if ($provider === 'meta') {
            return $this->eligibleMetaCandidates($accounts, $agentId);
        }

        $current = ChannelConnection::query()->where('agent_id', $agentId)->where('provider', $provider)->first();

        return collect($accounts)->map(function (array $account) use ($provider): ?array {
            $target = $provider === 'instagram' ? ($account['instagram_business_account'] ?? null) : $account;
            $externalId = is_array($target) ? (string) ($target['id'] ?? '') : '';
            $accessToken = (string) ($account['access_token'] ?? '');
            if ($externalId === '' || $accessToken === '') {
                return null;
            }

            $tokenExpiresAt = $this->pageTokenExpiresAt($account);
            if ($tokenExpiresAt === false) {
                return null;
            }

            $name = trim((string) ($provider === 'instagram'
                ? ($target['username'] ?? $target['name'] ?? '')
                : ($target['name'] ?? '')));

            return [
                'candidate_id' => Str::random(40),
                'external_account_id' => $externalId,
                'name' => $name !== '' ? $name : ucfirst($provider).' account',
                'access_token' => $accessToken,
                // The user-token expires_in returned by OAuth does not describe
                // this Page access token. Only retain expiry Meta explicitly
                // returned on the Page account itself.
                'token_expires_at' => $tokenExpiresAt,
                'page_id' => $provider === 'instagram' ? (string) ($account['id'] ?? '') : null,
                'page_name' => $provider === 'instagram' ? ($account['name'] ?? null) : null,
            ];
        })->filter(function (?array $candidate) use ($provider, $agentId, $current): bool {
            if (! $candidate) {
                return false;
            }
            if ($current && $current->external_account_id !== $candidate['external_account_id']) {
                return false;
            }

            return ! ChannelConnection::query()
                ->where('provider', $provider)
                ->where('external_account_id', $candidate['external_account_id'])
                ->where('agent_id', '!=', $agentId)
                ->exists();
        })->unique('external_account_id')->values();
    }

    private function eligibleMetaCandidates(array $accounts, int $agentId): Collection
    {
        $current = ChannelConnection::query()
            ->where('agent_id', $agentId)
            ->whereIn('provider', ['facebook', 'instagram'])
            ->get()
            ->keyBy('provider');

        return collect($accounts)->map(function (array $account): ?array {
            $pageId = (string) ($account['id'] ?? '');
            $accessToken = (string) ($account['access_token'] ?? '');
            if ($pageId === '' || $accessToken === '') {
                return null;
            }

            $tokenExpiresAt = $this->pageTokenExpiresAt($account);
            if ($tokenExpiresAt === false) {
                return null;
            }

            $instagram = $account['instagram_business_account'] ?? null;
            $instagramId = is_array($instagram) ? (string) ($instagram['id'] ?? '') : '';
            $instagramName = is_array($instagram)
                ? trim((string) ($instagram['username'] ?? $instagram['name'] ?? ''))
                : '';

            return [
                'candidate_id' => Str::random(40),
                'external_account_id' => $pageId,
                'name' => trim((string) ($account['name'] ?? '')) ?: 'Facebook Page',
                'access_token' => $accessToken,
                'token_expires_at' => $tokenExpiresAt,
                'instagram' => $instagramId !== '' ? [
                    'external_account_id' => $instagramId,
                    'name' => $instagramName !== '' ? $instagramName : 'Instagram account',
                ] : null,
            ];
        })->filter(function (?array $candidate) use ($agentId, $current): bool {
            if (! $candidate) {
                return false;
            }

            $facebook = $current->get('facebook');
            if ($facebook && $facebook->external_account_id !== $candidate['external_account_id']) {
                return false;
            }
            if ($this->ownedElsewhere('facebook', $candidate['external_account_id'], $agentId)) {
                return false;
            }

            $instagram = $candidate['instagram'];
            if (! is_array($instagram)) {
                return true;
            }

            $currentInstagram = $current->get('instagram');

            return ! ($currentInstagram && $currentInstagram->external_account_id !== $instagram['external_account_id'])
                && ! $this->ownedElsewhere('instagram', $instagram['external_account_id'], $agentId);
        })->unique('external_account_id')->values();
    }

    /**
     * @return string|false|null false means Meta explicitly returned an expiry
     *                           that is already invalid.
     */
    private function pageTokenExpiresAt(array $account): string|false|null
    {
        $absolute = $account['access_token_expires_at']
            ?? $account['token_expires_at']
            ?? $account['expires_at']
            ?? null;

        if ($absolute !== null && $absolute !== '') {
            try {
                $expiresAt = is_numeric($absolute)
                    ? Carbon::createFromTimestamp(
                        ((int) $absolute) > 9999999999 ? intdiv((int) $absolute, 1000) : (int) $absolute,
                        config('app.timezone'),
                    )
                    : Carbon::parse((string) $absolute);

                return $expiresAt->isFuture() ? $expiresAt->toIso8601String() : false;
            } catch (\Throwable) {
                return false;
            }
        }

        if (array_key_exists('expires_in', $account)) {
            $seconds = filter_var($account['expires_in'], FILTER_VALIDATE_INT);
            if ($seconds === false || $seconds <= 0) {
                return false;
            }

            return now()->addSeconds($seconds)->toIso8601String();
        }

        return null;
    }

    private function persistCandidate(string $provider, array $candidate, int $agentId): ChannelConnection
    {
        $ownedElsewhere = ChannelConnection::query()
            ->where('provider', $provider)
            ->where('external_account_id', $candidate['external_account_id'])
            ->where('agent_id', '!=', $agentId)
            ->lockForUpdate()
            ->exists();
        throw_if($ownedElsewhere, new \RuntimeException('Meta account already connected.'));

        $connection = ChannelConnection::query()
            ->where('agent_id', $agentId)
            ->where('provider', $provider)
            ->lockForUpdate()
            ->first();
        if ($connection && $connection->external_account_id !== $candidate['external_account_id']) {
            throw new \RuntimeException('Disconnect the current account before switching.');
        }

        $values = [
            'agent_id' => $agentId,
            'provider' => $provider,
            'status' => 'active',
            'external_account_id' => $candidate['external_account_id'],
            'external_account_name' => $candidate['name'],
            'access_token' => $candidate['access_token'],
            'token_expires_at' => $candidate['token_expires_at'],
            'metadata' => array_filter([
                'facebook_page_id' => $provider === 'instagram' ? $candidate['page_id'] : null,
                'facebook_page_name' => $provider === 'instagram' ? $candidate['page_name'] : null,
            ]),
            'connected_at' => now(),
            'last_error' => null,
        ];

        if ($connection) {
            $connection->update($values);

            return $connection->fresh();
        }

        return ChannelConnection::query()->create($values);
    }

    private function persistCandidateSet(string $provider, array $candidate, int $agentId): Collection
    {
        if ($provider !== 'meta') {
            return collect([$this->persistCandidate($provider, $candidate, $agentId)]);
        }

        $connections = collect([
            $this->persistCandidate('facebook', $candidate, $agentId),
        ]);

        if (is_array($candidate['instagram'] ?? null)) {
            $connections->push($this->persistCandidate('instagram', [
                'external_account_id' => $candidate['instagram']['external_account_id'],
                'name' => $candidate['instagram']['name'],
                'access_token' => $candidate['access_token'],
                'token_expires_at' => $candidate['token_expires_at'],
                'page_id' => $candidate['external_account_id'],
                'page_name' => $candidate['name'],
            ], $agentId));
        }

        return $connections;
    }

    private function ownedElsewhere(string $provider, string $externalAccountId, int $agentId): bool
    {
        return ChannelConnection::query()
            ->where('provider', $provider)
            ->where('external_account_id', $externalAccountId)
            ->where('agent_id', '!=', $agentId)
            ->exists();
    }

    private function pendingSelection(string $provider, string $selection, Request $request, TenantContext $tenant): ?MetaOAuthSelection
    {
        return MetaOAuthSelection::query()
            ->where('selector_hash', hash('sha256', $selection))
            ->where('provider', $provider)
            ->where('agent_id', $tenant->agent()->id)
            ->where('user_id', $request->user()->id)
            ->where('expires_at', '>', now())
            ->first();
    }

    private function subscribe(ChannelConnection $connection, MetaGraphClient $meta): void
    {
        try {
            $meta->subscribe($connection);
        } catch (\Throwable) {
            $connection->update([
                'status' => 'needs_attention',
                'last_error' => 'Meta webhook subscription could not be completed. Reconnect and verify permissions.',
            ]);
        }
    }

    private function subscribeAll(Collection $connections, MetaGraphClient $meta): void
    {
        $connections
            ->unique(fn (ChannelConnection $connection): string => $connection->graphAccountId())
            ->each(fn (ChannelConnection $connection) => $this->subscribe($connection, $meta));

        foreach ($connections as $connection) {
            $samePage = $connections->first(
                fn (ChannelConnection $candidate): bool => $candidate->graphAccountId() === $connection->graphAccountId()
            );
            if ($samePage && $samePage->status !== 'active' && $connection->status === 'active') {
                $connection->update([
                    'status' => 'needs_attention',
                    'last_error' => $samePage->last_error,
                ]);
            }
        }
    }

    private function connectionResult(string $provider, Collection $connections): RedirectResponse
    {
        $connections->each->refresh();
        $active = $connections->every(fn (ChannelConnection $connection): bool => $connection->status === 'active');
        if (! $active) {
            return to_route('channels.index')->with(
                'error',
                'The account was selected, but Meta webhook subscription needs attention.'
            );
        }

        if ($provider !== 'meta') {
            $connection = $connections->first();

            return to_route('channels.index')->with(
                'success',
                ucfirst($provider).' connected to '.$connection->external_account_name.'.'
            );
        }

        $facebook = $connections->firstWhere('provider', 'facebook');
        $instagram = $connections->firstWhere('provider', 'instagram');
        $message = 'Facebook Messenger connected to '.$facebook->external_account_name.'.';
        $message .= $instagram
            ? ' Instagram Direct connected to @'.$instagram->external_account_name.'.'
            : ' No linked Instagram Professional account was found; you can link one in Meta and reconnect later.';

        return to_route('channels.index')->with('success', $message);
    }

    private function redirectUri(string $provider): string
    {
        $configured = (string) config('meta.redirect_uri');

        return $configured !== ''
            ? str_replace('{provider}', $provider, $configured)
            : route('channels.meta.callback', ['provider' => $provider]);
    }

    private function oauthStateKey(string $state): string
    {
        return 'meta_oauth_state:'.hash('sha256', $state);
    }

    private function validOauthState(
        mixed $oauth,
        string $state,
        string $provider,
        int $agentId,
        int $userId
    ): bool {
        return is_array($oauth)
            && $state !== ''
            && ($oauth['provider'] ?? null) === $provider
            && (int) ($oauth['agent_id'] ?? 0) === $agentId
            && (int) ($oauth['user_id'] ?? 0) === $userId
            && (int) ($oauth['expires_at'] ?? 0) >= now()->timestamp
            && hash_equals((string) ($oauth['state'] ?? ''), $state);
    }

    private function provider(string $provider): void
    {
        abort_unless(in_array($provider, ['meta', 'facebook', 'instagram'], true), 404);
    }
}
