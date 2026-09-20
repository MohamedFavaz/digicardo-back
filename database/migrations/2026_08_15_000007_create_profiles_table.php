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
        Schema::create('profiles', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID Primary Key
            $table->char('user_id', 26)->unique(); // One-to-One with User
            $table->string('username', 50)->unique(); // Public URL slug
            $table->string('display_name', 100)->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_url', 500)->nullable();
            $table->string('template_id', 50)->default('vcard');
            $table->boolean('is_public')->default(true);
            $table->string('seo_title', 100)->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->unsignedInteger('version')->default(1); // Optimistic concurrency control
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('username', 'idx_profiles_username');
            $table->index('user_id', 'idx_profiles_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
