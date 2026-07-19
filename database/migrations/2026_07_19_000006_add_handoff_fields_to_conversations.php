<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $t) {
            $t->string('assigned_to')->nullable()->after('status');
            $t->string('priority')->default('normal')->after('assigned_to');
            $t->text('handoff_summary')->nullable()->after('intent');
            $t->text('handoff_reason')->nullable()->after('handoff_summary');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn(['assigned_to', 'priority', 'handoff_summary', 'handoff_reason']));
    }
};
