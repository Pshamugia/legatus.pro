<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->string('original_video_path')->nullable()->after('video_path');
            $table->unsignedInteger('video_duration_ms')->nullable()->after('original_video_path');
        });
    }

    public function down(): void
    {
        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->dropColumn(['original_video_path', 'video_duration_ms']);
        });
    }
};
