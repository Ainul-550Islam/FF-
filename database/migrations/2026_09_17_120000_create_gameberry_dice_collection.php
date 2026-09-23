<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dices')) {
            Schema::create('dices', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->string('rarity')->default('common')->index(); // common, rare, epic, legendary, titan
                $table->string('theme')->nullable()->index(); // classic, neon, gold, etc
                $table->string('color')->default('#6c5ce7');
                $table->string('image_path')->nullable();
                $table->integer('level_required')->default(1);
                $table->bigInteger('gold_price_minor')->default(0);
                $table->integer('gem_price')->default(0);
                $table->boolean('is_lucky')->default(false)->index();
                $table->boolean('is_collectible')->default(true)->index();
                $table->boolean('is_tradable')->default(false);
                $table->integer('max_collection')->default(52); // Gameberry max 52 dice
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['rarity', 'is_collectible']);
            });
        }

        if (! Schema::hasTable('user_dices')) {
            Schema::create('user_dices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('dice_id')->constrained('dices')->cascadeOnDelete();
                $table->integer('quantity')->default(1);
                $table->boolean('is_favorite')->default(false);
                $table->boolean('is_equipped')->default(false);
                $table->timestamp('acquired_at')->useCurrent();
                $table->json('acquisition_source')->nullable(); // magic_chest, video_ad, spin2win, purchase, gift, facebook_friend
                $table->timestamps();
                $table->unique(['user_id', 'dice_id']);
                $table->index(['user_id', 'is_equipped']);
                $table->index(['user_id', 'is_favorite']);
            });
        }

        if (! Schema::hasTable('lucky_dices')) {
            Schema::create('lucky_dices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('dice_id')->constrained('dices')->cascadeOnDelete();
                $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete(); // Facebook friend sender
                $table->string('pattern')->nullable(); // pattern for gem reward
                $table->boolean('is_rolled')->default(false);
                $table->timestamp('rolled_at')->nullable();
                $table->integer('gem_reward')->default(0);
                $table->timestamps();
                $table->index(['user_id', 'is_rolled']);
            });
        }

        if (! Schema::hasTable('dice_exchanges')) {
            Schema::create('dice_exchanges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('dice_id')->constrained('dices')->cascadeOnDelete();
                $table->string('status')->default('pending')->index(); // pending, accepted, rejected
                $table->boolean('is_facebook_only')->default(true); // Gameberry currently only Facebook friends
                $table->timestamps();
                $table->index(['receiver_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dice_exchanges');
        Schema::dropIfExists('lucky_dices');
        Schema::dropIfExists('user_dices');
        Schema::dropIfExists('dices');
    }
};
