<?php

namespace App\Console\Commands;

use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use Illuminate\Console\Command;

class SyncKnowledge extends Command
{
    protected $signature = 'legatus:sync-knowledge {--source=}';

    protected $description = 'Synchronize refreshable Legatus knowledge sources';

    public function handle(KnowledgeIngestionService $service): int
    {
        $query = KnowledgeSource::whereIn('status', ['ready', 'failed']);

        if ($this->option('source')) {
            $query->whereKey($this->option('source'));
        }

        $failed = 0;
        $query->each(function ($source) use ($service, &$failed) {
            if (! $source->isRefreshable()) {
                $this->line("Skipped snapshot #{$source->id} {$source->name}");

                return;
            }

            try {
                $service->ingest($source);
                $this->info("Synced #{$source->id} {$source->name}");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Failed #{$source->id}: {$exception->getMessage()}");
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
