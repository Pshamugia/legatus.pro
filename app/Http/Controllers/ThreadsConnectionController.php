<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Services\TenantContext;
use App\Services\ThreadsClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ThreadsConnectionController extends Controller
{
    public function connect(Request $request, TenantContext $tenant, ThreadsClient $threads): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_if(! config('threads.app_id') || ! config('threads.app_secret'), 503, 'Threads connection is not configured.');
        $state = Str::random(64);
        $oauth = [
            'state' => $state,
            'agent_id' => $tenant->agent()->id,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(20)->timestamp,
        ];
        $request->session()->put('threads_oauth', $oauth);
        Cache::put('threads_oauth_state:'.hash('sha256', $state), $oauth, now()->addMinutes(20));

        return redirect()->away($threads->authorizationUrl($state, $this->redirectUri()));
    }

    public function callback(Request $request, TenantContext $tenant, ThreadsClient $threads): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $state = (string) $request->query('state', '');
        $oauth = collect([
            $request->session()->pull('threads_oauth'),
            Cache::pull('threads_oauth_state:'.hash('sha256', $state)),
        ])->first(fn ($item): bool => is_array($item)
            && hash_equals((string) ($item['state'] ?? ''), $state)
            && (int) ($item['agent_id'] ?? 0) === $tenant->agent()->id
            && (int) ($item['user_id'] ?? 0) === $request->user()->id
            && (int) ($item['expires_at'] ?? 0) >= now()->timestamp);
        abort_unless(is_array($oauth), 403, 'The Threads authorization request is invalid or expired.');
        if ($request->filled('error')) {
            return to_route('channels.index')->with('error', 'Threads authorization was cancelled or denied.');
        }

        try {
            $short = $threads->exchangeCode((string) $request->query('code'), $this->redirectUri());
            $long = $threads->exchangeLongLivedToken((string) ($short['access_token'] ?? ''));
            $token = (string) ($long['access_token'] ?? '');
            $profile = $threads->profile($token);
            throw_if($token === '' || blank($profile['id'] ?? null), new \RuntimeException('Threads account details were incomplete.'));

            $agentId = $tenant->agent()->id;
            $accountId = (string) $profile['id'];
            throw_if(ChannelConnection::where('provider', 'threads')->where('external_account_id', $accountId)
                ->where('agent_id', '!=', $agentId)->exists(), new \RuntimeException('This Threads profile is already connected to another business.'));
            ChannelConnection::updateOrCreate(['agent_id' => $agentId, 'provider' => 'threads'], [
                'status' => 'active',
                'external_account_id' => $accountId,
                'external_account_name' => (string) ($profile['username'] ?? $profile['name'] ?? 'Threads profile'),
                'access_token' => $token,
                'token_expires_at' => isset($long['expires_in']) ? now()->addSeconds((int) $long['expires_in']) : null,
                'metadata' => ['username' => $profile['username'] ?? null],
                'connected_at' => now(),
                'last_error' => null,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return to_route('channels.index')->with('error', 'Threads could not be connected. Verify app access and try again.');
        }

        return to_route('channels.index')->with('success', 'Threads connected to @'.($profile['username'] ?? $profile['name'] ?? 'profile').'.');
    }

    public function disconnect(ChannelConnection $connection, TenantContext $tenant): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        abort_unless($connection->agent_id === $tenant->agent()->id && $connection->provider === 'threads', 404);
        $connection->delete();

        return to_route('channels.index')->with('success', 'Threads disconnected.');
    }

    private function redirectUri(): string
    {
        return config('threads.redirect_uri') ?: route('channels.threads.callback');
    }
}
