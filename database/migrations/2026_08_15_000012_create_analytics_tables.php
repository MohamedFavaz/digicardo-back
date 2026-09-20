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
        // 1. Raw Analytics Events Table
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('profile_id', 26);
            $table->char('block_id', 26)->nullable();
            $table->string('event_type', 40);
            $table->char('visitor_hash', 64)->nullable(); // Keyed daily pseudonymized IP hash
            $table->string('referrer_host', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('profile_id')
                ->references('id')
                ->on('profiles')
                ->onDelete('cascade');

            $table->index(['profile_id', 'occurred_at']);
            $table->index(['profile_id', 'event_type', 'occurred_at']);
            $table->index(['block_id', 'occurred_at']);
        });

        // 2. Unique Visitors Table (Daily Deduplication)
        Schema::create('analytics_unique_visitors', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('profile_id', 26);
            $table->char('visitor_hash', 64);
            $table->date('date');
            $table->timestamps();

            $table->foreign('profile_id')
                ->references('id')
                ->on('profiles')
                ->onDelete('cascade');

            $table->unique(['profile_id', 'visitor_hash', 'date'], 'uniq_profile_visitor_date');
            $table->index(['profile_id', 'date']);
        });

        // 3. Analytics Daily Metrics Rollup Table
        Schema::create('analytics_daily_metrics', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('profile_id', 26);
            $table->char('block_id', 26)->default(''); // Non-null default for clean compound unique constraint in MySQL
            $table->date('date');
            $table->string('event_type', 40);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('unique_count')->default(0);
            $table->timestamps();

            $table->foreign('profile_id')
                ->references('id')
                ->on('profiles')
                ->onDelete('cascade');

            $table->unique(['profile_id', 'block_id', 'date', 'event_type'], 'uniq_daily_metric_rollup');
            $table->index(['profile_id', 'date']);
            $table->index(['profile_id', 'event_type', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_metrics');
        Schema::dropIfExists('analytics_unique_visitors');
        Schema::dropIfExists('analytics_events');
    }
};
