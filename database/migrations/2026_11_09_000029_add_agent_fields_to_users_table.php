<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add agent-related fields to the users table.
     *
     * - agent_id: references the agent (user with role=agent) who created this user
     * - mobile: optional mobile phone number
     * - Indexes on agent_id and role for efficient agent filtering queries
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('agent_id', 26)->nullable()->after('role');
            $table->string('mobile', 20)->nullable()->after('email');

            $table->foreign('agent_id')
                ->references('id')
                ->on('users')
                ->onDelete('restrict');

            $table->index('agent_id', 'users_agent_id_index');
            $table->index('role', 'users_role_index');
        });
    }

    /**
     * Reverse the agent fields addition from the users table.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['agent_id']);
            $table->dropIndex('users_agent_id_index');
            $table->dropIndex('users_role_index');
            $table->dropColumn(['agent_id', 'mobile']);
        });
    }
};
