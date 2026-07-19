<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->text('suggested_reply')->nullable()->after('handoff_reason');
            $table->string('outcome')->nullable()->after('suggested_reply');
            $table->decimal('outcome_value', 10, 2)->default(0)->after('outcome');
            $table->timestamp('resolved_at')->nullable()->after('outcome_value');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('consent_at')->nullable()->after('notes');
            $table->timestamp('retention_until')->nullable()->after('consent_at');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('feedback', 16)->nullable()->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn(['public_id', 'feedback']));
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn(['consent_at', 'retention_until']));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropColumn(['suggested_reply', 'outcome', 'outcome_value', 'resolved_at']));
    }
};
