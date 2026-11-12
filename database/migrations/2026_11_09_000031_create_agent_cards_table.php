<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_cards table.
     *
     * Records each card created by an agent for a customer:
     * - Links agent, customer user account, and the profile together
     * - Tracks financial data: revenue, agent_commission, admin_profit
     * - Status tracks the card lifecycle (active, suspended, etc.)
     */
    public function up(): void
    {
        Schema::create('agent_cards', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            $table->char('agent_id', 26);
            $table->char('customer_user_id', 26);
            $table->char('profile_id', 26);

            $table->string('card_name', 150);
            $table->string('customer_name', 150);
            $table->string('customer_email', 255)->nullable();
            $table->string('customer_phone', 20)->nullable();

            $table->string('status', 20)->default('active');
            $table->decimal('revenue', 10, 2)->default(0);
            $table->decimal('agent_commission', 10, 2)->default(0);
            $table->decimal('admin_profit', 10, 2)->default(0);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('customer_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->foreign('profile_id')
                ->references('id')
                ->on('profiles')
                ->onDelete('restrict');

            $table->index('agent_id', 'agent_cards_agent_id_index');
            $table->index('customer_user_id', 'agent_cards_customer_user_id_index');
            $table->index('profile_id', 'agent_cards_profile_id_index');
            $table->index('status', 'agent_cards_status_index');
            $table->index(['agent_id', 'status'], 'agent_cards_agent_id_status_index');
        });
    }

    /**
     * Drop the agent_cards table.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_cards');
    }
};
