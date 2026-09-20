<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID Primary Key
            $table->char('actor_id', 26);
            $table->string('target_type', 50); // 'user', 'profile', 'report'
            $table->char('target_id', 26);
            $table->string('action_type', 50);
            $table->string('reason', 255)->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('actor_id', 'idx_mod_actor_id');
            $table->index(['target_type', 'target_id'], 'idx_mod_target');
            $table->index('action_type', 'idx_mod_action_type');
            $table->index('created_at', 'idx_mod_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
    }
};
