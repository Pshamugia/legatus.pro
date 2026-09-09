<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->longText('crawl_state')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->dropColumn('crawl_state');
        });
    }
};
