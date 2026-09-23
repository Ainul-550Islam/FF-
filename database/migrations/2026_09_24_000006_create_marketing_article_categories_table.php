<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — blog / SEO content engine (categories).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_article_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 190)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_article_categories');
    }
};
