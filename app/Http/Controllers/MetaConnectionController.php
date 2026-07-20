<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Models\MetaOAuthSelection;
use App\Services\MetaGraphClient;
use App\Services\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $request->session()->put('meta_oauth', [
            'state' => $state,
            'provider' => $provider,
            'agent_id' => $agent->id,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away($meta->authorizationUrl($provider, $state, $this->redirectUri($provider)));
    }

    public function callback(string $provider, Request $request, TenantContext $tenant, MetaGraphClient $meta): RedirectResponse
    {
        $this->provider($provider);
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        $oauth = $request->session()->pull('meta_oauth');

        abort_unless(
            is_array($oauth)
            && ($oauth['provider'] ?? null) === $provider
            && (int) ($oauth['agent_id'] ?? 0) === $agent->id
            && (int) ($oauth['user_id'] ?? 0) === $request->user()->id
            && (int) ($oauth['expires_at'] ?? 0) >= now()->timestamp
            && is_string($request->query('state'))
            && hash_equals((string) ($oauth['state'] ?? ''), (string) $request->query('state')),
            403,
            'The Meta authorization request is invalid or expired.'
        );

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
                $connection = DB::transaction(fn () => $this->persistCandidate($provider, $candidates->first(), $agent->id));
            } catch (\Throwable) {
                return to_route('channels.index')->with('error', 'That Meta account is already connected elsewhere or is no longer eligible.');
            }

            $this->subscribe($connection, $meta);

            return to_route('channels.index')->with(
                $connection->status === 'active' ? 'success' : 'error',
                $connection->status === 'active'
                    ? ucfirst($provider).' connected to '.$connection->external_account_name.'.'
                    : 'The account was selected, but Meta webhook subscription needs attention.'
            );
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
            'description' => $provider === 'instagram'
                ? 'Instagram Professional account'.(! empty($candidate['page_name']) ? ' · linked to '.$candidate['page_name'] : '')
                : 'Facebook Page',
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
            $connection = DB::transaction(function () use ($provider, $selection, $request, $agent, $data): ChannelConnection {
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

                $connection = $this->persistCandidate($provider, $candidate, $agent->id);
                $pending->delete();

                return $connection;
            });
        } catch (HttpException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return to_route('channels.index')->with('error', 'That Meta account could not be connected. Please connect again.');
        }

        $this->subscribe($connection, $meta);

        return to_route('channels.index')->with(
            $connection->status === 'active' ? 'success' : 'error',
            $connection->status === 'active'
                ? ucfirst($provider).' connected to '.$connection->external_account_name.'.'
                : 'The account was selected, but Meta webhook subscription needs attention.'
        );
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
                    ? Carbon::createFromTimestamp(((int) $absolute) > 9999999999 ? intdiv((int) $absolute, 1000) : (int) $absolute)
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

    private function redirectUri(string $provider): string
    {
        $configured = (string) config('meta.redirect_uri');

        return $configured !== ''
            ? str_replace('{provider}', $provider, $configured)
            : route('channels.meta.callback', ['provider' => $provider]);
    }

    private function provider(string $provider): void
    {
        abort_unless(in_array($provider, ['facebook', 'instagram'], true), 404);
    }
}
