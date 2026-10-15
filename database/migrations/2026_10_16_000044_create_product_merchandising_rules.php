<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_merchandising_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('recommendation_status', 20)->default('normal');
            $table->smallInteger('priority')->default(0);
            $table->string('campaign_label', 120)->nullable();
            $table->string('proactive_message', 240)->nullable();
            $table->string('proactive_prompt', 120)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->unique(['agent_id', 'product_id']);
            $table->index(['agent_id', 'recommendation_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_merchandising_rules');
    }
};
