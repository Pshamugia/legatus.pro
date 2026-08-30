<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->json('languages')->nullable()->after('categories');
        });
        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->string('language', 150)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', fn (Blueprint $table) => $table->dropColumn('language'));
        Schema::table('social_media_schedules', fn (Blueprint $table) => $table->dropColumn('languages'));
    }
};
