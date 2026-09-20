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
        Schema::create('profile_blocks', function (Blueprint $table) {
            $table->char('id', 26)->primary(); // ULID Primary Key
            $table->char('profile_id', 26);
            $table->string('type', 50); // Controlled block type: link, heading, text, divider, social
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('config'); // Block-type-specific structured data
            $table->boolean('is_visible')->default(true);
            $table->unsignedInteger('version')->default(1); // Optimistic concurrency control
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('profile_id')->references('id')->on('profiles')->onDelete('cascade');
            $table->index(['profile_id', 'sort_order'], 'idx_profile_blocks_sort');
            $table->index(['profile_id', 'is_visible'], 'idx_profile_blocks_visible');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profile_blocks');
    }
};
