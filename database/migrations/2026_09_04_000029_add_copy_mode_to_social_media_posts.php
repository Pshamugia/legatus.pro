<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->string('copy_mode', 24)->nullable()->after('caption');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', fn (Blueprint $table) => $table->dropColumn('copy_mode'));
    }
};
