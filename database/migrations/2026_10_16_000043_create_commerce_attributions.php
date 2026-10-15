<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_attributions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('initiated')->index();
            $table->string('provider_event_id', 191)->nullable();
            $table->string('order_reference_hash', 64)->nullable();
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->char('currency', 3)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->index(['commerce_connection_id', 'provider_event_id']);
        });
        Schema::create('commerce_attribution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_attribution_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_event_id', 191);
            $table->string('type', 40);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->timestamps();
            $table->unique(['commerce_connection_id', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_attribution_events');
        Schema::dropIfExists('commerce_attributions');
    }
};
