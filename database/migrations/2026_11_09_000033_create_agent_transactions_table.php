<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_transactions table.
     *
     * Immutable ledger of all financial transactions for agents:
     * - card_sale: revenue recorded when an agent sells a card
     * - commission_payment: payout to agent for earned commissions
     * - adjustment: manual corrections by admin
     * - Linked to agent_cards when the transaction relates to a specific card sale
     */
    public function up(): void
    {
        Schema::create('agent_transactions', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            $table->char('agent_id', 26);
            $table->char('agent_card_id', 26)->nullable();

            $table->string('type', 30);
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('description', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('agent_card_id')
                ->references('id')
                ->on('agent_cards')
                ->onDelete('restrict');

            $table->index('agent_id', 'agent_transactions_agent_id_index');
            $table->index('type', 'agent_transactions_type_index');
            $table->index(['agent_id', 'type'], 'agent_transactions_agent_id_type_index');
            $table->index('agent_card_id', 'agent_transactions_agent_card_id_index');
        });
    }

    /**
     * Drop the agent_transactions table.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_transactions');
    }
};
