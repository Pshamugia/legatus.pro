<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eval_cases', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('prompt');
            $t->string('expected_intent');
            $t->boolean('expected_handoff')->default(false);
            $t->json('expected_tools')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('evaluation_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $t->string('mode');
            $t->unsignedInteger('passed');
            $t->unsignedInteger('failed');
            $t->json('results');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_runs');
        Schema::dropIfExists('eval_cases');
    }
};
