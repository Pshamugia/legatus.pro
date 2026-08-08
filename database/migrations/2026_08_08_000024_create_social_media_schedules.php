<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('posts_per_day');
            $table->json('categories')->nullable();
            $table->json('providers');
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 24)->default('active');
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status', 'starts_on', 'ends_on']);
        });

        Schema::create('social_media_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_media_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->string('status', 24)->default('scheduled');
            $table->timestamp('scheduled_for');
            $table->timestamp('published_at')->nullable();
            $table->string('provider_post_id')->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->text('product_url');
            $table->text('image_url')->nullable();
            $table->text('caption');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['social_media_schedule_id', 'provider', 'scheduled_for'], 'social_posts_schedule_slot_unique');
            $table->index(['status', 'scheduled_for']);
            $table->index(['agent_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_posts');
        Schema::dropIfExists('social_media_schedules');
    }
};
