<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create validity_plans table — admin-configurable plans with price and duration.
     * Used when creating users and in the Accounts revenue tracking.
     */
    public function up(): void
    {
        Schema::create('validity_plans', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->string('name', 50);         // "1 Year", "2 Years", etc.
            $table->unsignedTinyInteger('months'); // 12, 24, 36, 60
            $table->decimal('price', 10, 2)->default(0); // Admin sets this in Settings
            $table->string('currency_symbol', 5)->default('₹');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('months', 'uniq_months');
            $table->index(['is_active', 'sort_order'], 'idx_active_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validity_plans');
    }
};
