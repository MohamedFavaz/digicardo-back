<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_payment_records table.
     *
     * Tracks actual payment disbursements to agents:
     * - Records payment status lifecycle: pending → paid / failed
     * - Stores payment method and reference number for reconciliation
     * - paid_at timestamp records when payment was actually completed
     */
    public function up(): void
    {
        Schema::create('agent_payment_records', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            $table->char('agent_id', 26);
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 100)->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->index('agent_id', 'agent_payment_records_agent_id_index');
            $table->index('status', 'agent_payment_records_status_index');
            $table->index(['agent_id', 'status'], 'agent_payment_records_agent_id_status_index');
        });
    }

    /**
     * Drop the agent_payment_records table.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_payment_records');
    }
};
