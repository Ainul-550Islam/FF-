<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 — marketing acquisition attribution (first-touch / last-touch).
 *
 * One row per (anonymous visitor, campaign key). The first insert is the
 * first touch and is never overwritten; later hits with the same campaign
 * key update the last-touch columns. user_id is attached retroactively when
 * the visitor registers or logs in, which is what makes campaigns attributable
 * to registrations and revenue without modifying business tables.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_attributions', function (Blueprint $table) {
            $table->id();
            $table->string('anonymous_id', 64)->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('campaign_key', 120)->default('(direct)');
            $table->string('source', 120)->nullable();
            $table->string('medium', 120)->nullable();
            $table->string('campaign', 120)->nullable();
            $table->string('content', 255)->nullable();
            $table->string('term', 255)->nullable();
            $table->string('click_id_type', 20)->nullable();
            $table->string('click_id', 190)->nullable();
            $table->string('landing_path', 500)->nullable();
            $table->string('referrer_host', 190)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->string('conversion_type', 40)->nullable();

            $table->unique(['anonymous_id', 'campaign_key']);
            $table->index(['user_id', 'converted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_attributions');
    }
};
