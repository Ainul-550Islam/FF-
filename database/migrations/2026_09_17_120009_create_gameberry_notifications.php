<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('gameberry_notifications')) {
            Schema::create('gameberry_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->string('title');
                $table->text('message')->nullable();
                $table->boolean('is_read')->default(false);
                $table->json('payload')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'is_read']);
            });
        }
        if (!Schema::hasTable('video_ads')) {
            Schema::create('video_ads', function (Blueprint $table) {
                $table->id();
                $table->string('provider')->default('admob');
                $table->string('placement')->default('free_gold');
                $table->integer('gold_reward')->default(100);
                $table->integer('gem_reward')->default(1);
                $table->boolean('is_active')->default(true);
                $table->integer('daily_limit')->default(5);
                $table->integer('cooldown_minutes')->default(30);
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('referral_bonuses')) {
            Schema::create('referral_bonuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('type')->default('gold');
                $table->integer('amount_minor')->default(2500);
                $table->integer('gems')->default(10);
                $table->string('status')->default('pending');
                $table->timestamp('awarded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('referral_bonuses');
        Schema::dropIfExists('video_ads');
        Schema::dropIfExists('gameberry_notifications');
    }
};
