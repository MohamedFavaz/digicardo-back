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
        Schema::create('profile_media', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID Primary Key
            $table->char('profile_id', 26);
            $table->char('user_id', 26);
            $table->string('type', 50); // avatar, cover, block_image, link_thumbnail
            $table->string('disk', 50)->default('public');
            $table->string('path', 500);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size'); // in bytes
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt_text', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['profile_id', 'type'], 'idx_profile_media_profile_type');
            $table->index('user_id', 'idx_profile_media_user_id');
            $table->index('path', 'idx_profile_media_path');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->string('cover_url', 500)->nullable()->after('avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn('cover_url');
        });

        Schema::dropIfExists('profile_media');
    }
};
