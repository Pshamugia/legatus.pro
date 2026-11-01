<?php

namespace App\Console\Commands;

use App\Jobs\CrawlPublicWebsite;
use App\Models\KnowledgeSource;
use App\Services\KnowledgeIngestionService;
use Illuminate\Console\Command;

class SyncKnowledge extends Command
{
    protected $signature = 'legatus:sync-knowledge {--source=} {--url=} {--auto-catalog : Queue one due public catalog refresh}';

    protected $description = 'Synchronize refreshable Legatus knowledge sources';

    public function handle(KnowledgeIngestionService $service): int
    {
        if ($this->option('auto-catalog')) {
            // Refresh only one complete public catalog at a time. The crawl
            // imports newly discovered listings as it progresses, while the
            // knowledge worker bounds storefront traffic on shared hosting.
            $source = KnowledgeSource::query()
                ->where('type', 'url')
                ->where('source_scope', 'catalog')
                ->where('status', 'ready')
                ->where('progress', 100)
                ->whereNull('error')
                ->whereNotNull('url')
                ->where(function ($query): void {
                    $query->whereNull('last_synced_at')
                        ->orWhere('last_synced_at', '<=', now()->subHour());
                })
                ->orderBy('last_synced_at')
                ->orderBy('id')
                ->first();

            if ($source) {
                $source->update(['status' => 'processing', 'progress' => 1, 'error' => null, 'crawl_state' => null]);
                CrawlPublicWebsite::dispatch($source->id);
                $this->info("Queued catalog refresh #{$source->id} {$source->name}");
            }

            return self::SUCCESS;
        }

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
                    $source->update(['status' => 'processing', 'progress' => 1, 'error' => null, 'crawl_state' => null]);
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
