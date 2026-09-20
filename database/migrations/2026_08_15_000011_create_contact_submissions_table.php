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
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('profile_id', 26);
            $table->string('name', 100);
            $table->string('email', 255);
            $table->string('phone', 30)->nullable();
            $table->text('message');
            $table->string('ip_hash', 64)->nullable(); // Keyed daily pseudonymized IP hash
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('profile_id')
                ->references('id')
                ->on('profiles')
                ->onDelete('cascade');

            $table->index(['profile_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
