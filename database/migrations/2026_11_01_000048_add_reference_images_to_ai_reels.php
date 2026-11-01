<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->json('reference_image_paths')->nullable()->after('source_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->dropColumn('reference_image_paths');
        });
    }
};
