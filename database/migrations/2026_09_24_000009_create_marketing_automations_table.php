<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 21 — lifecycle automation definitions.
 *
 * trigger is a lifecycle moment key (user.registered, lead.subscribed, ...),
 * action is the NotificationService payload {type,title,body,link}. Sends are
 * deduplicated through the marketing_events funnel log (automation.sent),
 * so no separate send-ledger table is needed.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_automations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 190);
            $table->string('trigger', 64)->index();
            $table->json('audience')->nullable();
            $table->json('action');
            $table->unsignedInteger('cooldown_hours')->default(24);
            $table->boolean('enabled')->default(false)->index();
            $table->timestamp('last_run_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_automations');
    }
};
