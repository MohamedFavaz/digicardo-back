<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add validity/expiry fields to users table for admin-assigned plan tracking.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When this user's access expires (null = no expiry)
            $table->timestamp('expires_at')->nullable()->after('status');
            // Number of months granted (12, 24, 36, 60)
            $table->unsignedTinyInteger('validity_months')->nullable()->after('expires_at');
            // Price paid at creation (for revenue tracking)
            $table->decimal('plan_price_paid', 10, 2)->nullable()->after('validity_months');
            // Plain-text note (e.g. "1 Year Plan") — not the password
            $table->string('validity_label', 50)->nullable()->after('plan_price_paid');

            $table->index(['expires_at', 'status'], 'idx_expires_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_expires_status');
            $table->dropColumn(['expires_at', 'validity_months', 'plan_price_paid', 'validity_label']);
        });
    }
};
