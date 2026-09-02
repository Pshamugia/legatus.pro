<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->string('copy_mode', 24)->default('original')->after('template_snapshots');
            $table->string('ai_tone', 24)->nullable()->after('copy_mode');
        });

        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->timestamp('ai_generation_attempted_at')->nullable()->after('caption');
            $table->timestamp('ai_generated_at')->nullable()->after('ai_generation_attempted_at');
            $table->string('ai_model', 80)->nullable()->after('ai_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', fn (Blueprint $table) => $table->dropColumn(['ai_generation_attempted_at', 'ai_generated_at', 'ai_model']));
        Schema::table('social_media_schedules', fn (Blueprint $table) => $table->dropColumn(['copy_mode', 'ai_tone']));
    }
};
