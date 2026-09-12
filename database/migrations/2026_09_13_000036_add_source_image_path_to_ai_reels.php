<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->string('source_image_path')->nullable()->after('source_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->dropColumn('source_image_path');
        });
    }
};
