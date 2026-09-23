<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20 — campaign landing pages.
 *
 * Each campaign is a lightweight, DB-driven landing page (/campaign/{slug})
 * with its own headline, proof copy, CTA and UTM campaign key, so a campaign
 * can be measured end-to-end without a code deploy.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('name');
            $table->string('headline');
            $table->string('subheadline', 300)->nullable();
            $table->text('body')->nullable();
            $table->string('hero_image', 500)->nullable();
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 500)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->string('seo_title', 190)->nullable();
            $table->string('seo_description', 300)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
