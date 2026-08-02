<?php

namespace App\Console\Commands;

use App\Jobs\CrawlPublicWebsite;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildCategoryIndexes extends Command
{
    protected $signature = 'legatus:rebuild-category-indexes';

    protected $description = 'Queue one legacy category index rebuild without overlapping knowledge work';

    public function handle(): int
    {
        if (DB::table('jobs')->where('queue', 'knowledge')->exists()) {
            $this->info('Knowledge work is already queued; no additional crawl was added.');

            return self::SUCCESS;
        }

        $source = KnowledgeSource::query()
            ->where('type', 'url')
            ->where('source_scope', 'category')
            ->where('index_version', '<', 2)
            ->whereNotNull('url')
            ->orderByRaw('last_synced_at is not null')
            ->orderBy('last_synced_at')
            ->orderBy('id')
            ->first();

        if (! $source) {
            $this->info('All category indexes are current.');

            return self::SUCCESS;
        }

        $source->update([
            'status' => 'processing',
            'progress' => 1,
            'items_found' => 0,
            'error' => null,
        ]);
        CrawlPublicWebsite::dispatch($source->id);
        $this->info("Queued category index #{$source->id}: {$source->taxonomy_label}");

        return self::SUCCESS;
    }
}
