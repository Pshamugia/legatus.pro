<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $t->json('preferences');
            $t->timestamps();
        });
        Schema::create('recommendation_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $t->json('query');
            $t->json('ranked_products');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_events');
        Schema::dropIfExists('shopping_profiles');
    }
};
