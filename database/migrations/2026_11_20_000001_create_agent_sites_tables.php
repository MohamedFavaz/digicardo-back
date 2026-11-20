<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent sites: each agent gets a landing page (editable content) at digicardo/partner/{slug},
 * and optionally a domain (set by an admin) that serves the landing page at its root and the
 * agent's customers' cards at /{username}.
 * Agent leads: enquiries sent from a landing page's "Get your card" form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sites', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('agent_id', 26)->unique();
            $table->string('slug', 60)->unique();
            $table->json('content')->nullable();
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            // Admin switch: hides the landing page (cards keep working).
            $table->boolean('landing_disabled')->default(false);

            // Domain (managed by admins only).
            $table->string('domain', 253)->nullable()->unique();
            $table->string('domain_status', 20)->nullable(); // pending | active | failed
            $table->string('domain_error', 255)->nullable();
            $table->timestamp('domain_checked_at')->nullable();
            $table->timestamp('domain_activated_at')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['domain', 'domain_status']);
        });

        Schema::create('agent_leads', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->char('agent_id', 26);
            $table->string('name', 80);
            $table->string('phone', 24)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('package', 60)->nullable();
            $table->text('message')->nullable();
            $table->string('source_host', 253)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_leads');
        Schema::dropIfExists('agent_sites');
    }
};
