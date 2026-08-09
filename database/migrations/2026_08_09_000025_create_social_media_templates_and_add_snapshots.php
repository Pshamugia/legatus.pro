<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_media_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('body_template');
            $table->boolean('delivery_enabled')->default(false);
            $table->text('delivery_text')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['agent_id', 'provider']);
        });

        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->json('template_snapshots')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('social_media_schedules', function (Blueprint $table): void {
            $table->dropColumn('template_snapshots');
        });

        Schema::dropIfExists('social_media_templates');
    }
};
