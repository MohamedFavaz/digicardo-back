<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messages sent by an admin (to everyone, a role, an agent's customers or chosen people)
 * or by an agent (to the customers they created). Each message is delivered as one row
 * per recipient in `notifications`, linked back through `notifications.broadcast_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_messages', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('sender_id', 26)->nullable();
            $table->string('sender_role', 20);
            $table->string('audience', 30);
            $table->json('audience_meta')->nullable();
            $table->string('title', 120);
            $table->text('body');
            $table->string('link_url', 500)->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->boolean('emailed')->default(false);
            $table->timestamp('retracted_at')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['sender_id', 'created_at']);
            $table->index(['sender_role', 'created_at']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->char('broadcast_id', 26)->nullable()->after('user_id');
            $table->index('broadcast_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['broadcast_id']);
            $table->dropColumn('broadcast_id');
        });

        Schema::dropIfExists('broadcast_messages');
    }
};
