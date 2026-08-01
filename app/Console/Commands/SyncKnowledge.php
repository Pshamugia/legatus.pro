<?php

namespace App\Console\Commands;

use App\Jobs\CrawlPublicWebsite;
use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use Illuminate\Console\Command;

class SyncKnowledge extends Command
{
    protected $signature = 'legatus:sync-knowledge {--source=} {--url=}';

    protected $description = 'Synchronize refreshable Legatus knowledge sources';

    public function handle(KnowledgeIngestionService $service): int
    {
        $query = KnowledgeSource::whereIn('status', ['ready', 'failed']);

        if ($this->option('source')) {
            $query->whereKey($this->option('source'));
        }
        if ($this->option('url')) {
            $query->where('url', $this->option('url'));
        }

        $failed = 0;
        $query->each(function ($source) use ($service, &$failed) {
            if (! $source->isRefreshable()) {
                $this->line("Skipped snapshot #{$source->id} {$source->name}");

                return;
            }

            try {
                if ($source->type === 'url') {
                    $source->update(['status' => 'processing', 'progress' => 1, 'error' => null]);
                    CrawlPublicWebsite::dispatch($source->id);
                    $this->info("Queued #{$source->id} {$source->name}");

                    return;
                } else {
                    $service->ingest($source);
                }
                $this->info("Synced #{$source->id} {$source->name}");
            } catch (\Throwable $exception) {
                $failed++;
                $this->error("Failed #{$source->id}: {$exception->getMessage()}");
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
