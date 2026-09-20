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
        Schema::create('plans', function (Blueprint $table) {
            $table->id(); // BIGINT AUTO_INCREMENT internal reference table
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->unsignedInteger('price_monthly_cents')->default(0);
            $table->unsignedInteger('price_yearly_cents')->default(0);
            $table->string('stripe_monthly_price_id', 100)->nullable();
            $table->string('stripe_yearly_price_id', 100)->nullable();
            $table->json('limits');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
