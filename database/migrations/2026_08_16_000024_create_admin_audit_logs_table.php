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
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID Primary Key
            $table->char('actor_id', 26);
            $table->string('action', 100);
            $table->string('target_type', 50);
            $table->char('target_id', 26);
            $table->json('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('actor_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('actor_id', 'idx_audit_actor_id');
            $table->index('action', 'idx_audit_action');
            $table->index(['target_type', 'target_id'], 'idx_audit_target');
            $table->index('request_id', 'idx_audit_request_id');
            $table->index('created_at', 'idx_audit_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');
    }
};
