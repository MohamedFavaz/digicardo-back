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
        Schema::create('account_sessions', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('session_identifier', 64)->index();
            $table->string('session_id', 100)->nullable()->index();
            $table->string('ip_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_name', 100)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('revoked_reason', 100)->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_sessions');
    }
};
