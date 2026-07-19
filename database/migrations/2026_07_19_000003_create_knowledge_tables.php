<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $t->string('type');
            $t->string('name');
            $t->text('url')->nullable();
            $t->string('file_path')->nullable();
            $t->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $t->unsignedTinyInteger('progress')->default(0);
            $t->unsignedInteger('items_found')->default(0);
            $t->unsignedInteger('items_created')->default(0);
            $t->unsignedInteger('items_updated')->default(0);
            $t->text('error')->nullable();
            $t->string('content_hash')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('knowledge_chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $t->string('kind')->default('document');
            $t->string('title')->nullable();
            $t->longText('content');
            $t->string('content_hash')->index();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['knowledge_source_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
    }
};
