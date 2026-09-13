<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->timestamp('provider_updated_at')->nullable()->after('provider_post_id');
            $table->timestamp('provider_deleted_at')->nullable()->after('provider_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_posts', function (Blueprint $table): void {
            $table->dropColumn(['provider_updated_at', 'provider_deleted_at']);
        });
    }
};
