<?php

namespace App\Console\Commands;

use App\Models\CommerceConnection;
use App\Services\CommerceCatalogSyncService;
use App\Services\CommerceWebhookProvisioner;
use Illuminate\Console\Command;

class SyncCommerceCatalogs extends Command
{
    protected $signature = 'legatus:sync-commerce {--connection=}';

    protected $description = 'Synchronize connected commerce catalogs into Legatus';

    public function handle(CommerceCatalogSyncService $sync, CommerceWebhookProvisioner $webhooks): int
    {
        $failed = false;
        $connections = CommerceConnection::query()
            ->when($this->option('connection'), fn ($query, $id) => $query->whereKey($id))
            ->where('status', 'active')
            ->get();

        foreach ($connections as $connection) {
            try {
                $result = $sync->sync($connection);
                if (in_array($connection->provider, ['woocommerce', 'shopify'], true)
                    && data_get($connection->fresh()->settings, 'attribution_status') !== 'active') {
                    try {
                        $webhooks->provision($connection->fresh());
                    } catch (\Throwable $webhookException) {
                        report($webhookException);
                        $webhooks->markPending($connection->fresh());
                    }
                }
                $this->info("{$connection->name}: {$result['received']} received, {$result['created']} created, {$result['updated']} updated");
            } catch (\Throwable $exception) {
                $failed = true;
                report($exception);
                $this->error(($connection->name ?: "Connection {$connection->id}").': '.$exception->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
