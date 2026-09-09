<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_publication_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_media_post_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 32);
            $table->char('identity_key', 64);
            $table->string('status', 24)->default('claimed');
            $table->timestamp('published_at')->nullable();
            $table->string('provider_post_id')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'provider', 'identity_key'], 'social_publication_identity_unique');
            $table->index(['agent_id', 'provider', 'status'], 'social_publication_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_publication_identities');
    }
};
