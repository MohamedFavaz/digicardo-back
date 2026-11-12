<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_quotas table.
     *
     * Tracks each agent's card creation quota:
     * - card_limit: maximum number of cards the agent can create
     * - cards_used: current count of cards created
     * - restore_on_delete: whether deleting a card restores a credit to the agent
     */
    public function up(): void
    {
        Schema::create('agent_quotas', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            $table->char('agent_id', 26)->unique();
            $table->unsignedInteger('card_limit')->default(0);
            $table->unsignedInteger('cards_used')->default(0);
            $table->boolean('restore_on_delete')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    /**
     * Drop the agent_quotas table.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_quotas');
    }
};
