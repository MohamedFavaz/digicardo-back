<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purpose (ADR-030): card content moves out of theme_tokens.custom_options into a typed,
 * validated `content` JSON column. `theme_tokens_legacy` keeps a copy of the old theme
 * blob when a profile is first converted, so the change can be rolled back; it is dropped
 * after the redesign cutover (plan §7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('content')->nullable()->after('theme_tokens');
            $table->json('theme_tokens_legacy')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['content', 'theme_tokens_legacy']);
        });
    }
};
