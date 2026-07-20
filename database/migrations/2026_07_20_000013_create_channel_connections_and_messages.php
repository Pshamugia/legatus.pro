<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('status', 24)->default('active');
            $table->string('external_account_id');
            $table->string('external_account_name')->nullable();
            $table->text('access_token');
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_account_id']);
            $table->unique(['agent_id', 'provider']);
            $table->index(['agent_id', 'provider', 'status']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('channel_connection_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            $table->string('external_thread_id')->nullable()->after('visitor_id');
            $table->index(['channel_connection_id', 'external_thread_id']);
        });

        Schema::create('channel_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('idempotency_key')->unique();
            $table->foreignId('channel_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('direction', 16);
            $table->string('provider_message_id')->nullable();
            $table->string('provider_sender_id')->nullable();
            $table->string('provider_recipient_id')->nullable();
            $table->string('message_type', 32)->default('text');
            $table->string('status', 24)->default('received');
            // The model encrypts this value before storage. TEXT is required
            // because Laravel encrypted casts are opaque ciphertext, not JSON.
            $table->text('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['channel_connection_id', 'direction', 'provider_message_id'],
                'channel_messages_provider_identity_unique'
            );
            $table->index(['channel_connection_id', 'status']);
            $table->index(['provider_recipient_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_messages');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['channel_connection_id', 'external_thread_id']);
            $table->dropConstrainedForeignId('channel_connection_id');
            $table->dropColumn('external_thread_id');
        });

        Schema::dropIfExists('channel_connections');
    }
};
