<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', fn (Blueprint $t) => $t->json('embedding')->nullable()->after('metadata'));
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', fn (Blueprint $t) => $t->dropColumn('embedding'));
    }
};
