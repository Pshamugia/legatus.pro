<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('universal_api');
            $table->string('name')->nullable();
            $table->string('base_url', 500);
            $table->string('key_id', 120);
            $table->text('secret');
            $table->string('status')->default('pending')->index();
            $table->json('settings')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_connections');
    }
};
