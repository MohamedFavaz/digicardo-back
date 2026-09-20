<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create admin_sales table — tracks revenue from user creation with validity plans.
     * Powers the Accounts menu with daily/monthly profit charts.
     */
    public function up(): void
    {
        Schema::create('admin_sales', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID
            $table->char('user_id', 26);       // The created user
            $table->char('validity_plan_id', 26)->nullable(); // Which plan was sold
            $table->string('plan_label', 50);   // Snapshot: "1 Year"
            $table->decimal('amount', 10, 2);   // Price at time of sale
            $table->string('currency_symbol', 5)->default('₹');
            $table->date('sale_date');           // Date of sale (for grouping)
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('validity_plan_id')->references('id')->on('validity_plans')->nullOnDelete();

            $table->index(['sale_date'], 'idx_sale_date');
            $table->index(['user_id'], 'idx_sale_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_sales');
    }
};
