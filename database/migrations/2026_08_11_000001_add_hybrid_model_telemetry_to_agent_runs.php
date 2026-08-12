<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('route', 32)->nullable()->after('model');
            $table->string('fallback_reason')->nullable()->after('route');
            $table->json('model_usage')->nullable()->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['route', 'fallback_reason', 'model_usage']);
        });
    }
};
