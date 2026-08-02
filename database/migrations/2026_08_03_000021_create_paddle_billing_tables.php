<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paddle_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('paddle_subscription_id')->unique();
            $table->string('paddle_customer_id')->nullable()->index();
            $table->string('paddle_price_id')->nullable();
            $table->string('status')->index();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->string('scheduled_change_action')->nullable();
            $table->timestamp('scheduled_change_at')->nullable();
            $table->timestamp('paddle_occurred_at')->nullable();
            $table->json('items')->nullable();
            $table->timestamps();
        });

        Schema::create('paddle_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paddle_webhook_events');
        Schema::dropIfExists('paddle_subscriptions');
    }
};
