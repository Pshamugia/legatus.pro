<?php

namespace App\Http\Controllers;

use App\Models\ChannelConnection;
use App\Services\TenantContext;
use App\Services\ThreadsClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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
            return to_route('channels.index')->with('channel_error', 'Threads authorization was cancelled or denied.');
        }

        $stage = 'exchange_code';
        try {
            $short = $threads->exchangeCode((string) $request->query('code'), $this->redirectUri());
            $stage = 'exchange_long_lived_token';
            $long = $threads->exchangeLongLivedToken((string) ($short['access_token'] ?? ''));
            $token = (string) ($long['access_token'] ?? '');
            $stage = 'load_profile';
            $profile = $threads->profile($token);
            throw_if($token === '' || blank($profile['id'] ?? null), new \RuntimeException('Threads account details were incomplete.'));

            $stage = 'save_connection';
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
        } catch (Throwable $exception) {
            $this->logSafeFailure($stage, $tenant->agent()->id, $request->user()->id, $exception);

            return to_route('channels.index')->with('channel_error', $this->failureMessage($exception));
        }

        return to_route('channels.index')->with('channel_success', 'Threads connected to @'.($profile['username'] ?? $profile['name'] ?? 'profile').'.');
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

    private function failureMessage(Throwable $exception): string
    {
        if ($exception instanceof RequestException) {
            $providerMessage = strtolower((string) data_get($exception->response->json(), 'error.message', ''));

            if (str_contains($providerMessage, 'threads_basic') || str_contains($providerMessage, 'permission')) {
                return 'This Threads profile is not authorized for Legatus yet. While the Meta app is in testing, add this exact profile as a Threads Tester and accept the invitation in Threads Website permissions, then reconnect. Public connections require Meta App Review approval.';
            }

            if (in_array($exception->response->status(), [401, 403], true)) {
                return 'Threads rejected this authorization. Remove Legatus from Threads Website permissions and reconnect the profile.';
            }

            if ($exception->response->serverError()) {
                return 'Threads is temporarily unavailable. Your existing connections were not changed; please try again shortly.';
            }
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'already connected to another business')) {
            return 'This Threads profile is already connected to another business in Legatus. Disconnect it there before connecting it here.';
        }
        if (str_contains($message, 'account details were incomplete')) {
            return 'Threads authorized the request but did not return a complete profile. Remove Legatus from Threads Website permissions and reconnect.';
        }

        return 'Threads could not be connected. No existing connection was changed. Verify the profile authorization and try again.';
    }

    private function logSafeFailure(string $stage, int $agentId, int $userId, Throwable $exception): void
    {
        $error = $exception instanceof RequestException ? $exception->response->json('error') : null;

        Log::warning('Threads connection failed.', [
            'stage' => $stage,
            'agent_id' => $agentId,
            'user_id' => $userId,
            'exception_type' => $exception::class,
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'meta_error_code' => is_array($error) ? ($error['code'] ?? null) : null,
            'meta_error_subcode' => is_array($error) ? ($error['error_subcode'] ?? null) : null,
            'meta_error_type' => is_array($error) ? ($error['type'] ?? null) : null,
        ]);
    }
}
