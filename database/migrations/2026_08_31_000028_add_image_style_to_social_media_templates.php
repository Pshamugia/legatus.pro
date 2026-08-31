<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_media_templates', fn (Blueprint $table) => $table->string('image_style', 32)->default('original')->after('delivery_text'));
    }

    public function down(): void
    {
        Schema::table('social_media_templates', fn (Blueprint $table) => $table->dropColumn('image_style'));
    }
};
