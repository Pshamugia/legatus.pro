<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_oauth_selections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->char('selector_hash', 64)->unique();
            // Contains short-lived Page access tokens and is encrypted by the model.
            $table->text('candidates');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['agent_id', 'user_id', 'provider', 'expires_at'], 'meta_oauth_selection_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_oauth_selections');
    }
};
