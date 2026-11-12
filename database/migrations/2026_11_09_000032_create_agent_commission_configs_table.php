<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the agent_commission_configs table.
     *
     * Stores commission configuration per agent or a global default:
     * - agent_id NULL = global default pricing config
     * - agent_id set = override pricing for that specific agent
     * - Tracks card_price, agent_commission split, and admin_profit split
     * - pricing_model supports future expansion (fixed, percentage, tiered, etc.)
     */
    public function up(): void
    {
        Schema::create('agent_commission_configs', function (Blueprint $table) {
            $table->char('id', 26)->primary();

            $table->char('agent_id', 26)->nullable()->unique();

            $table->decimal('card_price', 10, 2)->default(500.00);
            $table->decimal('agent_commission', 10, 2)->default(200.00);
            $table->decimal('admin_profit', 10, 2)->default(300.00);
            $table->string('pricing_model', 20)->default('fixed');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');
        });
    }

    /**
     * Drop the agent_commission_configs table.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_commission_configs');
    }
};
