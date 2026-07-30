<?php

namespace App\Console\Commands;

use App\Models\ChannelConnection;
use App\Services\MetaInboxReconciler;
use Illuminate\Console\Command;

class ReconcileMetaInbox extends Command
{
    protected $signature = 'legatus:reconcile-meta-inbox {--lookback=5 : First-run lookback in minutes}';

    protected $description = 'Recover recent Facebook messages missed by Meta webhook delivery';

    public function handle(MetaInboxReconciler $reconciler): int
    {
        $lookback = max(1, min(60, (int) $this->option('lookback')));
        $imported = 0;

        ChannelConnection::query()
            ->where('provider', 'facebook')
            ->where('status', 'active')
            ->orderBy('id')
            ->eachById(function (ChannelConnection $connection) use ($reconciler, $lookback, &$imported): void {
                $imported += $reconciler->reconcile($connection, $lookback);
            });

        $this->info("Meta inbox reconciliation complete: {$imported} new message(s).");

        return self::SUCCESS;
    }
}
