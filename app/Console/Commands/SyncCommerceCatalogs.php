<?php

namespace App\Console\Commands;

use App\Models\CommerceConnection;
use App\Services\CommerceCatalogSyncService;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;

class SyncCommerceCatalogs extends Command
{
    protected $signature = 'legatus:sync-commerce {--connection=}';

    protected $description = 'Synchronize connected commerce catalogs into Legatus';

    public function handle(CommerceCatalogSyncService $sync, ShopifyClient $shopify): int
    {
        $failed = false;
        $connections = CommerceConnection::query()
            ->when($this->option('connection'), fn ($query, $id) => $query->whereKey($id))
            ->where('status', 'active')
            ->get();

        foreach ($connections as $connection) {
            try {
                $result = $sync->sync($connection);
                if ($connection->provider === 'shopify'
                    && ! data_get($connection->fresh()->settings, 'lifecycle_webhook_registered')) {
                    try {
                        $fresh = $connection->fresh();
                        if (data_get($fresh->settings, 'attribution_status') !== 'active') {
                            $shopify->registerLifecycleWebhook($fresh);
                        }
                        $fresh->update(['settings' => array_merge($fresh->settings ?? [], ['lifecycle_webhook_registered' => true])]);
                    } catch (\Throwable $webhookException) {
                        report($webhookException);
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
