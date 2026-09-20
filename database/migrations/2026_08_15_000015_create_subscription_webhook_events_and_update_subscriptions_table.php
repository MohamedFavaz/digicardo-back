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
        // 1. Add provider_price_id and grace_period_ends_at to subscriptions table
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('provider_price_id', 255)->nullable()->after('provider_subscription_id');
            $table->timestamp('grace_period_ends_at')->nullable()->after('trial_ends_at');
        });

        // 2. Create subscription_webhook_events table for webhook idempotency and audit logs
        Schema::create('subscription_webhook_events', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->string('provider', 50)->index();
            $table->string('provider_event_id', 255);
            $table->string('event_type', 100)->index();
            $table->string('payload_hash', 64);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            // Unique constraint on (provider, provider_event_id) for idempotent event deduplication
            $table->unique(['provider', 'provider_event_id'], 'sub_webhook_events_provider_event_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_webhook_events');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['provider_price_id', 'grace_period_ends_at']);
        });
    }
};
