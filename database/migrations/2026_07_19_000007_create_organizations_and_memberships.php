<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('plan')->default('build-week');
            $t->json('settings')->nullable();
            $t->timestamps();
        });
        Schema::create('organization_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->enum('role', ['owner', 'admin', 'agent', 'viewer'])->default('agent');
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });
        Schema::table('agents', fn (Blueprint $t) => $t->foreignId('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete());
    }

    public function down(): void
    {
        Schema::table('agents', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
