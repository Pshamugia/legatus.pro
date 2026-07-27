<?php

namespace App\Console\Commands;

use App\Jobs\EmbedKnowledgeSource;
use App\Models\KnowledgeSource;
use Illuminate\Console\Command;

class ResumeKnowledge extends Command
{
    protected $signature = 'legatus:resume-knowledge {--host=}';

    protected $description = 'Resume semantic indexing for an interrupted public website crawl';

    public function handle(): int
    {
        $query = KnowledgeSource::query()
            ->where('type', 'url')
            ->where('status', 'processing')
            ->where('progress', '>', 1);

        if ($host = trim((string) $this->option('host'))) {
            $query->where('url', 'like', '%://'.$host.'/%');
        }

        $count = 0;
        $query->each(function (KnowledgeSource $source) use (&$count): void {
            $products = $source->agent->products()
                ->where('metadata->source_id', $source->id)
                ->where('is_active', true)
                ->count();
            $source->update([
                'items_found' => $products,
                'progress' => 96,
                'error' => null,
            ]);
            EmbedKnowledgeSource::dispatch($source->id);
            $this->info("Resumed #{$source->id} {$source->name}: {$products} products, {$source->chunks()->count()} passages");
            $count++;
        });

        if ($count === 0) {
            $this->warn('No interrupted public website crawl matched.');
        }

        return self::SUCCESS;
    }
}
