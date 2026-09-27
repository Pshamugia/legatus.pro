<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->boolean('ai_photo_editor')->default(false)->after('ai_tone');
        });

        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->timestamp('ai_image_generation_attempted_at')->nullable()->after('ai_model');
            $table->timestamp('ai_image_generated_at')->nullable()->after('ai_image_generation_attempted_at');
            $table->string('ai_image_model', 160)->nullable()->after('ai_image_generated_at');
            $table->text('ai_image_source_url')->nullable()->after('ai_image_model');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', fn (Blueprint $table) => $table->dropColumn([
            'ai_image_generation_attempted_at',
            'ai_image_generated_at',
            'ai_image_model',
            'ai_image_source_url',
        ]));
        Schema::table('social_media_schedules', fn (Blueprint $table) => $table->dropColumn('ai_photo_editor'));
    }
};
