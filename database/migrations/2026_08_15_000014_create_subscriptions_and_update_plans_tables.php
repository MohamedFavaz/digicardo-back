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
        // 1. Enhance plans table with description, currency, code, features and softDeletes
        Schema::table('plans', function (Blueprint $table) {
            if (!Schema::hasColumn('plans', 'code')) {
                $table->string('code', 50)->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('plans', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (!Schema::hasColumn('plans', 'currency')) {
                $table->string('currency', 3)->default('USD')->after('price_yearly_cents');
            }
            if (!Schema::hasColumn('plans', 'features')) {
                $table->json('features')->nullable()->after('currency');
            }
            if (!Schema::hasColumn('plans', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // 2. Create subscriptions table
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID primary key
            $table->char('user_id', 26);
            $table->unsignedBigInteger('plan_id');
            $table->string('provider', 50)->nullable(); // e.g., 'stripe', 'null'
            $table->string('provider_customer_id', 100)->nullable();
            $table->string('provider_subscription_id', 100)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('billing_interval', 20)->nullable(); // 'monthly', 'yearly'
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Foreign Keys
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();

            // Indexes
            $table->index('user_id');
            $table->index('plan_id');
            $table->index('status');
            $table->index('provider_subscription_id');
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['code', 'description', 'currency', 'features']);
        });
    }
};
