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
        Schema::create('profile_domains', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('profile_id', 26);
            $table->char('user_id', 26);
            $table->string('domain', 255);
            $table->string('normalized_domain', 255)->unique();
            $table->string('status', 30)->default('pending');
            $table->string('verification_method', 30)->default('txt_record');
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('ssl_status', 30)->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('profile_id')
                ->references('id')
                ->on('profiles')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->index('profile_id');
            $table->index('user_id');
            $table->index('status');
            $table->index(['normalized_domain', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_domains');
    }
};
