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
        Schema::create('account_security_events', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->index();
            $table->string('event_type', 100)->index();
            $table->json('metadata')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('created_at')->nullable()->index();

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
        Schema::dropIfExists('account_security_events');
    }
};
