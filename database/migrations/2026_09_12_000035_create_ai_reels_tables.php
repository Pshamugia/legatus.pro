<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reel_credit_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('type', 32);
            $table->string('reference')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('ai_reel_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('reel_count');
            $table->json('categories')->nullable();
            $table->json('languages')->nullable();
            $table->json('providers');
            $table->string('timezone', 80);
            $table->string('timing_mode', 20)->default('auto');
            $table->json('posting_times')->nullable();
            $table->string('ai_tone', 20)->default('creative');
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_reels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_reel_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20);
            $table->string('language', 150)->nullable();
            $table->json('providers');
            $table->text('user_prompt')->nullable();
            $table->text('reference_url')->nullable();
            $table->text('generated_prompt')->nullable();
            $table->text('caption')->nullable();
            $table->text('source_image_url')->nullable();
            $table->string('runway_task_id')->nullable()->unique();
            $table->unsignedSmallInteger('poll_attempts')->default(0);
            $table->string('video_path')->nullable();
            $table->string('status', 32)->default('queued')->index();
            $table->text('last_error')->nullable();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('credit_refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_reel_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_reel_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('status', 32)->default('scheduled')->index();
            $table->string('provider_container_id')->nullable();
            $table->string('provider_post_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['ai_reel_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reel_deliveries');
        Schema::dropIfExists('ai_reels');
        Schema::dropIfExists('ai_reel_schedules');
        Schema::dropIfExists('reel_credit_ledger');
    }
};
