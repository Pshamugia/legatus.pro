<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->json('posting_times')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->dropColumn('posting_times');
        });
    }
};
