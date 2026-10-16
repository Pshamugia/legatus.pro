<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('commerce_connections', 'webhook_token')) {
            Schema::table('commerce_connections', function (Blueprint $table): void {
                $table->uuid('webhook_token')->nullable()->after('secret');
                $table->unique('webhook_token', 'cc_webhook_token_uq');
            });
        }
        if (! Schema::hasColumn('commerce_connections', 'webhook_secret')) {
            Schema::table('commerce_connections', function (Blueprint $table): void {
                $table->text('webhook_secret')->nullable()->after('webhook_token');
            });
        }

        if (! Schema::hasTable('commerce_installations')) {
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
        }

        $this->preserveLegacyAttributionTables();

        if (! Schema::hasTable('commerce_attributions')) {
            Schema::create('commerce_attributions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('token')->unique();
                $table->char('attribution_key', 64)->unique();
                $table->foreignId('agent_id');
                $table->foreignId('commerce_connection_id')->nullable();
                $table->foreignId('conversation_id')->nullable();
                $table->foreignId('product_id')->nullable();
                $table->string('source_type', 32)->index();
                $table->unsignedBigInteger('source_id')->nullable()->index();
                $table->string('channel', 32)->nullable()->index();
                $table->text('destination_url');
                $table->char('destination_url_hash', 64)->index();
                $table->unsignedInteger('click_count')->default(0);
                $table->timestamp('first_clicked_at')->nullable();
                $table->timestamp('last_clicked_at')->nullable();
                $table->timestamps();

                $table->foreign('agent_id', 'ca_agent_fk')->references('id')->on('agents')->cascadeOnDelete();
                $table->foreign('commerce_connection_id', 'ca_connection_fk')->references('id')->on('commerce_connections')->nullOnDelete();
                $table->foreign('conversation_id', 'ca_conversation_fk')->references('id')->on('conversations')->nullOnDelete();
                $table->foreign('product_id', 'ca_product_fk')->references('id')->on('products')->nullOnDelete();
                $table->index(['agent_id', 'source_type', 'source_id'], 'ca_agent_source_idx');
            });
        }

        if (! Schema::hasTable('commerce_attribution_orders')) {
            Schema::create('commerce_attribution_orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('commerce_attribution_id');
                $table->foreignId('agent_id');
                $table->foreignId('commerce_connection_id')->nullable();
                $table->string('provider', 32);
                $table->char('external_order_hash', 64);
                $table->string('status', 32)->default('paid')->index();
                $table->decimal('gross_amount', 12, 2)->default(0);
                $table->decimal('refunded_amount', 12, 2)->default(0);
                $table->char('currency', 3);
                $table->timestamp('converted_at');
                $table->timestamp('refunded_at')->nullable();
                $table->timestamps();

                $table->foreign('commerce_attribution_id', 'cao_attribution_fk')->references('id')->on('commerce_attributions')->cascadeOnDelete();
                $table->foreign('agent_id', 'cao_agent_fk')->references('id')->on('agents')->cascadeOnDelete();
                $table->foreign('commerce_connection_id', 'cao_connection_fk')->references('id')->on('commerce_connections')->nullOnDelete();
                $table->unique(['commerce_connection_id', 'external_order_hash'], 'cao_connection_order_uq');
                $table->index(['agent_id', 'converted_at'], 'cao_agent_converted_idx');
            });
        }

        if (! Schema::hasTable('commerce_attribution_events')) {
            Schema::create('commerce_attribution_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('commerce_connection_id')->nullable();
                $table->foreignId('commerce_attribution_id')->nullable();
                $table->string('provider', 32);
                $table->string('provider_event_id', 191);
                $table->string('event_type', 48);
                $table->char('payload_hash', 64);
                $table->timestamp('processed_at');
                $table->timestamps();

                $table->foreign('commerce_connection_id', 'cae_connection_fk')->references('id')->on('commerce_connections')->nullOnDelete();
                $table->foreign('commerce_attribution_id', 'cae_attribution_fk')->references('id')->on('commerce_attributions')->nullOnDelete();
                $table->unique(['provider', 'provider_event_id'], 'cae_provider_event_uq');
            });
        }

        $this->assertCurrentSchema();
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_attribution_events');
        Schema::dropIfExists('commerce_attribution_orders');
        Schema::dropIfExists('commerce_attributions');
        Schema::dropIfExists('commerce_installations');
        if (Schema::hasColumn('commerce_connections', 'webhook_token')) {
            Schema::table('commerce_connections', function (Blueprint $table): void {
                $table->dropUnique('cc_webhook_token_uq');
                $table->dropColumn('webhook_token');
            });
        }
        if (Schema::hasColumn('commerce_connections', 'webhook_secret')) {
            Schema::table('commerce_connections', function (Blueprint $table): void {
                $table->dropColumn('webhook_secret');
            });
        }
    }

    private function preserveLegacyAttributionTables(): void
    {
        $legacyAttributions = 'commerce_attributions_legacy_20261017';
        $legacyEvents = 'commerce_attribution_events_legacy_20261017';

        if (Schema::hasTable('commerce_attribution_events')
            && ! Schema::hasColumns('commerce_attribution_events', ['provider', 'event_type', 'payload_hash', 'processed_at'])) {
            if (Schema::hasTable($legacyEvents)) {
                throw new RuntimeException('Both current and preserved legacy commerce attribution event tables exist.');
            }
            Schema::rename('commerce_attribution_events', $legacyEvents);
        }

        if (Schema::hasTable('commerce_attributions')
            && ! Schema::hasColumns('commerce_attributions', ['attribution_key', 'source_type', 'destination_url', 'destination_url_hash'])) {
            if (Schema::hasTable($legacyAttributions)) {
                throw new RuntimeException('Both current and preserved legacy commerce attribution tables exist.');
            }
            Schema::rename('commerce_attributions', $legacyAttributions);
        }
    }

    private function assertCurrentSchema(): void
    {
        $expected = [
            'commerce_attributions' => ['token', 'attribution_key', 'agent_id', 'source_type', 'destination_url'],
            'commerce_attribution_orders' => ['commerce_attribution_id', 'external_order_hash', 'gross_amount', 'currency'],
            'commerce_attribution_events' => ['provider', 'provider_event_id', 'event_type', 'payload_hash'],
        ];
        foreach ($expected as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new RuntimeException("Existing {$table} table does not match the current Legatus attribution schema.");
            }
        }
    }
};
