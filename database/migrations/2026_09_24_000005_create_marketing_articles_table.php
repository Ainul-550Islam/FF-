<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — blog / SEO content engine (articles).
 *
 * Drafts are never publicly rendered; only published rows inside their
 * publication window are indexable. Bodies are stored as plain text and
 * always rendered escaped — no raw-HTML injection surface.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->index();
            $table->string('slug', 190)->unique();
            $table->string('title', 190);
            $table->string('excerpt', 500)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('author_id')->nullable();
            $table->string('seo_title', 190)->nullable();
            $table->string('seo_description', 500)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('published_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_articles');
    }
};
