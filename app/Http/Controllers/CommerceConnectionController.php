<?php

namespace App\Http\Controllers;

use App\Services\CommerceCatalogSyncService;
use App\Services\CommerceOriginValidator;
use App\Services\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class CommerceConnectionController extends Controller
{
    public function connect(
        Request $request,
        TenantContext $tenant,
        CommerceCatalogSyncService $sync,
        CommerceOriginValidator $origins,
    ): RedirectResponse {
        $tenant->authorize(['owner', 'admin']);
        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            'base_url' => ['required', 'string', 'max:500'],
            'key_id' => ['required', 'string', 'max:120'],
            'secret' => ['required', 'string', 'min:32', 'max:512'],
        ], [
            'secret.min' => 'The shared secret must contain at least 32 characters.',
        ]);

        // Do not use withInput() here: credentials must never be flashed to the
        // session or rendered back into the form after validation fails.
        if ($validator->fails()) {
            return to_route('channels.index')
                ->withErrors($validator, 'commerce')
                ->with('commerce_error', 'Check the store connection fields and try again.');
        }

        $data = $validator->validated();
        try {
            $baseUrl = $origins->normalize($data['base_url']);
        } catch (\InvalidArgumentException $exception) {
            return to_route('channels.index')
                ->withErrors(['base_url' => $exception->getMessage()], 'commerce')
                ->with('commerce_error', 'Use the public HTTPS address of your store, without a path.');
        }

        $agent = $tenant->agent();
        $name = trim((string) ($data['name'] ?? ''));

        try {
            $result = $sync->connect($agent, [
                'provider' => 'universal_api',
                'name' => $name !== '' ? $name : parse_url($baseUrl, PHP_URL_HOST).' live catalog',
                'base_url' => $baseUrl,
                'key_id' => trim($data['key_id']),
                'secret' => $data['secret'],
                'status' => 'active',
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $this->logSafeFailure('connect', (int) $agent->id, $exception);

            return to_route('channels.index')->with('commerce_error', $this->failureMessage($exception, true));
        }

        return to_route('channels.index')->with(
            'commerce_success',
            "Store connected and {$result['received']} products verified.",
        );
    }

    public function sync(TenantContext $tenant, CommerceCatalogSyncService $sync): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();
        $connection = $agent->commerceConnection()->first();
        if (! $connection) {
            return to_route('channels.index')->with('commerce_error', 'Connect a store before starting a catalog sync.');
        }

        try {
            $result = $sync->sync($connection);
        } catch (Throwable $exception) {
            $this->logSafeFailure('sync', (int) $agent->id, $exception);

            return to_route('channels.index')->with('commerce_error', $this->failureMessage($exception));
        }

        return to_route('channels.index')->with(
            'commerce_success',
            "Catalog synced: {$result['received']} received, {$result['created']} new, {$result['updated']} updated.",
        );
    }

    public function disconnect(TenantContext $tenant, CommerceCatalogSyncService $sync): RedirectResponse
    {
        $tenant->authorize(['owner', 'admin']);
        $agent = $tenant->agent();

        try {
            $result = $sync->disconnect($agent);
        } catch (Throwable $exception) {
            $this->logSafeFailure('disconnect', (int) $agent->id, $exception);

            return to_route('channels.index')->with('commerce_error', $this->failureMessage($exception));
        }

        if (! $result['disconnected']) {
            return to_route('channels.index')->with('commerce_error', 'No connected store was found.');
        }

        return to_route('channels.index')->with(
            'commerce_success',
            "Store disconnected. {$result['deactivated']} imported products were taken offline.",
        );
    }

    private function failureMessage(Throwable $exception, bool $connecting = false): string
    {
        if ($exception instanceof ConnectionException) {
            return 'Legatus could not reach the store. Check its HTTPS address and make sure the connector is enabled.';
        }

        if ($exception instanceof RequestException) {
            return match ($exception->response->status()) {
                401, 403 => 'The store rejected these credentials. Check the key ID and shared secret.',
                404 => 'The store is reachable, but its Legatus connector endpoints were not found.',
                408, 504 => 'The store took too long to respond. Try again after checking its server.',
                429 => 'The store is temporarily rate-limiting catalog requests. Wait a moment and try again.',
                default => $exception->response->serverError()
                    ? 'The store connector is temporarily unavailable. Check the store and try again.'
                    : 'The store rejected the catalog request. Check its connector configuration.',
            };
        }

        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'already in progress')) {
            return 'A catalog sync is already running. Wait for it to finish and refresh this page.';
        }
        if (str_contains($message, 'not active')) {
            return 'This store connection needs attention. Verify and reconnect it before syncing.';
        }
        if (str_contains($message, 'curl extension')) {
            return 'This server needs its PHP cURL extension enabled before a store can be connected.';
        }
        if (str_contains($message, 'catalog') || str_contains($message, 'snapshot') || str_contains($message, 'response')) {
            return 'The store responded, but its catalog is not in the supported Universal Commerce format.';
        }

        return $connecting
            ? 'The connection could not be verified. Your previous store connection was left unchanged.'
            : 'The store action could not be completed safely. Your last verified catalog was preserved.';
    }

    private function logSafeFailure(string $action, int $agentId, Throwable $exception): void
    {
        Log::warning('Universal Commerce action failed.', [
            'action' => $action,
            'agent_id' => $agentId,
            'exception_type' => $exception::class,
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
        ]);
    }
}
