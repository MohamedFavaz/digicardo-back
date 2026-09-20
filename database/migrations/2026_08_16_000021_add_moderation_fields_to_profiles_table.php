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
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('moderation_status', 30)->default('active')->after('is_public');
            $table->string('moderation_reason', 255)->nullable()->after('moderation_status');
            $table->text('moderation_notes')->nullable()->after('moderation_reason');
            $table->timestamp('moderated_at')->nullable()->after('moderation_notes');
            $table->char('moderated_by', 26)->nullable()->after('moderated_at');

            $table->index('moderation_status', 'idx_profiles_moderation_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex('idx_profiles_moderation_status');
            $table->dropColumn([
                'moderation_status',
                'moderation_reason',
                'moderation_notes',
                'moderated_at',
                'moderated_by',
            ]);
        });
    }
};
