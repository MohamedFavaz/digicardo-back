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
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID Primary Key
            $table->char('profile_id', 26);
            $table->char('block_id', 26)->nullable();
            $table->string('reporter_email', 255)->nullable();
            $table->string('reporter_ip_hash', 64)->nullable();
            $table->string('reason', 50);
            $table->text('description');
            $table->string('status', 30)->default('open');
            $table->json('metadata')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->char('resolved_by', 26)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index('profile_id', 'idx_reports_profile_id');
            $table->index('status', 'idx_reports_status');
            $table->index('reason', 'idx_reports_reason');
            $table->index('created_at', 'idx_reports_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
    }
};
