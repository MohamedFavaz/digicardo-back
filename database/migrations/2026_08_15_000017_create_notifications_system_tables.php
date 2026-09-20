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
        // 1. notification_preferences
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->unique();
            $table->boolean('email_enabled')->default(true);
            $table->boolean('security_email_enabled')->default(true);
            $table->boolean('marketing_email_enabled')->default(false);
            $table->boolean('contact_email_enabled')->default(true);
            $table->boolean('subscription_email_enabled')->default(true);
            $table->boolean('domain_email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 2. notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->nullable();
            $table->string('type', 50);
            $table->string('category', 50);
            $table->string('title', 255);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'read_at']);
            $table->index(['category', 'created_at']);
        });

        // 3. email_deliveries
        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('user_id', 26)->nullable();
            $table->char('notification_id', 26)->nullable();
            $table->string('type', 50);
            $table->string('recipient', 255);
            $table->string('subject', 255);
            $table->string('provider', 50);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('status', 50)->default('queued');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('notification_id')->references('id')->on('notifications')->onDelete('cascade');

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_preferences');
    }
};
