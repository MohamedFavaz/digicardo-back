<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            // Drop unique index to allow multiple cards per user
            $table->dropUnique('profiles_user_id_unique');

            // Multi-card metadata fields
            $table->string('category', 50)->default('business')->after('template_id');
            $table->string('card_title', 150)->nullable()->after('category');
            $table->string('status', 30)->default('published')->after('card_title');
            $table->boolean('is_primary')->default(false)->after('status');
            $table->timestamp('published_at')->nullable()->after('is_primary');
            $table->string('published_url', 255)->nullable()->after('published_at');

            // Fast index lookups for user's cards
            $table->index(['user_id', 'status'], 'idx_profiles_user_status');
            $table->index(['user_id', 'is_primary'], 'idx_profiles_user_primary');
        });

        // Backfill existing profiles as primary & published
        DB::table('profiles')->update([
            'is_primary' => true,
            'status' => 'published',
            'published_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex('idx_profiles_user_status');
            $table->dropIndex('idx_profiles_user_primary');

            $table->dropColumn([
                'category',
                'card_title',
                'status',
                'is_primary',
                'published_at',
                'published_url',
            ]);

            // Re-apply unique constraint
            $table->unique('user_id', 'profiles_user_id_unique');
        });
    }
};
