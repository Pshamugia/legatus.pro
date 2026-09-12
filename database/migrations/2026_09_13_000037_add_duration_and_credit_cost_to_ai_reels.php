<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reel_schedules', function (Blueprint $table): void {
            $table->unsignedTinyInteger('duration_seconds')->default(5)->after('reel_count');
            $table->unsignedTinyInteger('credits_per_reel')->default(1)->after('duration_seconds');
        });

        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->unsignedTinyInteger('duration_seconds')->default(5)->after('mode');
            $table->unsignedTinyInteger('credit_cost')->default(1)->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('ai_reel_schedules', function (Blueprint $table): void {
            $table->dropColumn(['duration_seconds', 'credits_per_reel']);
        });

        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->dropColumn(['duration_seconds', 'credit_cost']);
        });
    }
};
