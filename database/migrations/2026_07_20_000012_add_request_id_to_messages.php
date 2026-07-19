<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('public_id');
            $table->unique(['conversation_id', 'request_id'], 'messages_conversation_request_unique');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropUnique('messages_conversation_request_unique');
            $table->dropColumn('request_id');
        });
    }
};
