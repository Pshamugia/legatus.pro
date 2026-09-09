<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->string('story_status', 24)->nullable()->after('provider_post_id');
            $table->string('provider_story_id')->nullable()->after('story_status');
            $table->timestamp('story_published_at')->nullable()->after('provider_story_id');
            $table->unsignedSmallInteger('story_attempts')->default(0)->after('story_published_at');
            $table->text('story_failure_reason')->nullable()->after('story_attempts');
            $table->index(['agent_id', 'provider', 'story_status'], 'social_posts_story_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->dropIndex('social_posts_story_status_index');
            $table->dropColumn(['story_status', 'provider_story_id', 'story_published_at', 'story_attempts', 'story_failure_reason']);
        });
    }
};
