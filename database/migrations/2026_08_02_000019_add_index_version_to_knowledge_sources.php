<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_sources', function (Blueprint $table): void {
            $table->unsignedSmallInteger('index_version')->default(0)->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_sources', fn (Blueprint $table) => $table->dropColumn('index_version'));
    }
};
