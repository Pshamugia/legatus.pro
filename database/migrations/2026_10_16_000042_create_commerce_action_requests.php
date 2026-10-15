<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_action_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('action_type', 80)->index();
            $table->string('status', 30)->default('pending_confirmation')->index();
            $table->json('summary');
            $table->text('payload');
            $table->uuid('idempotency_key')->unique();
            $table->string('connector_reference', 191)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_action_requests');
    }
};
