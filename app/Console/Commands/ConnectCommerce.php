<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Services\CommerceCatalogSyncService;
use App\Services\CommerceOriginValidator;
use Illuminate\Console\Command;

class ConnectCommerce extends Command
{
    protected $signature = 'legatus:connect-commerce
        {agent : Agent slug}
        {base-url : HTTPS origin of the store connector}
        {key-id : Public connector key identifier}
        {--name= : Display name shown as the data source}';

    protected $description = 'Securely connect an agent to a Universal Commerce API';

    public function handle(CommerceCatalogSyncService $sync, CommerceOriginValidator $origins): int
    {
        $agent = Agent::where('slug', $this->argument('agent'))->first();
        if (! $agent) {
            $this->error('Agent not found.');

            return self::FAILURE;
        }

        try {
            $baseUrl = $origins->normalize((string) $this->argument('base-url'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $secret = (string) $this->secret('Shared secret (input is hidden)');
        if (strlen($secret) < 32) {
            $this->error('The shared secret must contain at least 32 characters.');

            return self::FAILURE;
        }

        $attributes = [
            'provider' => 'universal_api',
            'name' => $this->option('name') ?: parse_url($baseUrl, PHP_URL_HOST).' live catalog',
            'base_url' => $baseUrl,
            'key_id' => (string) $this->argument('key-id'),
            'secret' => $secret,
            'status' => 'active',
            'last_error' => null,
        ];

        try {
            $result = $sync->connect($agent, $attributes);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('Connection verification failed; the previous setup was left unchanged: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Connected and verified: {$result['received']} products received.");

        return self::SUCCESS;
    }
}
