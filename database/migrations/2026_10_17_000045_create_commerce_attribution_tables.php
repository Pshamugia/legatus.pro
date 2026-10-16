<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_connections', function (Blueprint $table): void {
            $table->uuid('webhook_token')->nullable()->unique()->after('secret');
            $table->text('webhook_secret')->nullable()->after('webhook_token');
        });

        Schema::create('commerce_installations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->index();
            $table->char('token_hash', 64)->unique();
            $table->string('base_url', 500);
            $table->text('credentials')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('commerce_attributions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();
            $table->char('attribution_key', 64)->unique();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 32)->index();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->string('channel', 32)->nullable()->index();
            $table->text('destination_url');
            $table->char('destination_url_hash', 64)->index();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'source_type', 'source_id'], 'ca_agent_source_idx');
        });

        Schema::create('commerce_attribution_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_attribution_id')->constrained('commerce_attributions')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->char('external_order_hash', 64);
            $table->string('status', 32)->default('paid')->index();
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->char('currency', 3);
            $table->timestamp('converted_at');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['commerce_connection_id', 'external_order_hash'], 'cao_connection_order_uq');
            $table->index(['agent_id', 'converted_at'], 'cao_agent_converted_idx');
        });

        Schema::create('commerce_attribution_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commerce_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('commerce_attribution_id')->nullable()->constrained('commerce_attributions')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('provider_event_id', 191);
            $table->string('event_type', 48);
            $table->char('payload_hash', 64);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id'], 'cae_provider_event_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_attribution_events');
        Schema::dropIfExists('commerce_attribution_orders');
        Schema::dropIfExists('commerce_attributions');
        Schema::dropIfExists('commerce_installations');
        Schema::table('commerce_connections', function (Blueprint $table): void {
            $table->dropUnique(['webhook_token']);
            $table->dropColumn(['webhook_token', 'webhook_secret']);
        });
    }
};
