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
            $table->json('seo_keywords')->nullable()->after('seo_description');
            $table->string('og_title', 95)->nullable()->after('seo_keywords');
            $table->string('og_description', 200)->nullable()->after('og_title');
            $table->char('og_image_media_id', 26)->nullable()->after('og_description');
            $table->boolean('indexable')->default(true)->after('og_image_media_id');

            $table->foreign('og_image_media_id')
                ->references('id')
                ->on('profile_media')
                ->nullOnDelete();

            $table->index('indexable');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropForeign(['og_image_media_id']);
            $table->dropIndex(['indexable']);
            $table->dropColumn([
                'seo_keywords',
                'og_title',
                'og_description',
                'og_image_media_id',
                'indexable',
            ]);
        });
    }
};
