<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_publication_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->unsignedInteger('current_cycle')->default(1);
            $table->timestamps();

            $table->unique(['agent_id', 'provider'], 'social_publication_cycle_unique');
        });

        Schema::table('social_publication_identities', function (Blueprint $table): void {
            $table->unsignedInteger('cycle_number')->default(1)->after('identity_key');
        });
    }

    public function down(): void
    {
        Schema::table('social_publication_identities', function (Blueprint $table): void {
            $table->dropColumn('cycle_number');
        });
        Schema::dropIfExists('social_publication_cycles');
    }
};
