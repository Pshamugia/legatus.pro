<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_reel_schedules', function (Blueprint $table): void {
            $table->string('music_track', 32)->default('bright')->after('credits_per_reel');
        });

        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->string('music_track', 32)->default('bright')->after('credit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('ai_reel_schedules', function (Blueprint $table): void {
            $table->dropColumn('music_track');
        });

        Schema::table('ai_reels', function (Blueprint $table): void {
            $table->dropColumn('music_track');
        });
    }
};
