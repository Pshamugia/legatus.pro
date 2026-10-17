<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_health_monitor_states', function (Blueprint $table): void {
            $table->string('monitor_key')->primary();
            $table->timestamp('started_at');
            $table->timestamps();
        });

        // Existing failed rows are historical audit data, not incidents that
        // were observed after platform monitoring became active.
        DB::table('platform_health_monitor_states')->insert([
            'monitor_key' => 'operational',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_health_monitor_states');
    }
};
