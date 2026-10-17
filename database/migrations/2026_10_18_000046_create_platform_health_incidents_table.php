<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_health_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('incident_key')->unique();
            $table->string('category', 48)->index();
            $table->string('severity', 16)->default('error');
            $table->string('status', 16)->default('open')->index();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('context')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'status', 'last_detected_at'], 'platform_health_category_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_health_incidents');
    }
};
