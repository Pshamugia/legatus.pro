<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->string('source_scope', 30)->nullable()->after('type')->index();
            $table->string('taxonomy_label', 150)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->dropIndex(['source_scope']);
            $table->dropColumn(['source_scope', 'taxonomy_label']);
        });
    }
};
