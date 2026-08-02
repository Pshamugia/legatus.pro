<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('knowledge_sources')
            ->where('source_scope', 'category')
            ->where('index_version', '<', 2)
            ->update([
                'status' => 'pending',
                'progress' => 0,
                'items_found' => 0,
                'error' => 'Legacy category count retired; a lightweight scoped rebuild is pending.',
            ]);
    }

    public function down(): void
    {
        // Retired legacy counts were not trustworthy and must not be restored.
    }
};
