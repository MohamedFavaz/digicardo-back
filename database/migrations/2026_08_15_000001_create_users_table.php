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
        Schema::create('users', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // 26-char ULID primary key
            $table->string('name', 100);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->enum('role', ['user', 'admin', 'moderator'])->default('user');
            $table->enum('status', ['active', 'suspended', 'banned'])->default('active');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('email', 'idx_email');
            $table->index(['status', 'deleted_at'], 'idx_status_deleted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
