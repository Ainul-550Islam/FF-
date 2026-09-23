<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('private_tables')) {
            Schema::create('private_tables', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique()->index(); // table code for sharing
                $table->string('link')->unique(); // shareable link
                $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
                $table->string('game_variation')->default('classic')->index(); // classic, master, quick per Gameberry
                $table->string('mode')->default('1vs1')->index(); // 1vs1, team_up, 4_player, private_table
                $table->bigInteger('bet_amount_minor')->default(0); // gold at stake
                $table->integer('max_players')->default(2);
                $table->string('status')->default('waiting')->index(); // waiting, playing, completed, cancelled
                $table->boolean('is_team_up')->default(false);
                $table->boolean('is_facebook_only')->default(false);
                $table->boolean('allow_spectators')->default(true);
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->json('settings')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['creator_id', 'status']);
                $table->index(['status', 'expires_at']);
            });
        }

        if (! Schema::hasTable('private_table_participants')) {
            Schema::create('private_table_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('private_table_id')->constrained('private_tables')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role')->default('player')->index(); // player, spectator, creator
                $table->string('team')->nullable()->index(); // for team_up mode
                $table->string('status')->default('joined')->index(); // joined, ready, playing, left, auto_mode
                $table->boolean('is_in_auto_mode')->default(false); // Gameberry auto mode on disconnect
                $table->boolean('is_ready')->default(false);
                $table->integer('position')->nullable(); // 1st, 2nd, etc
                $table->bigInteger('gold_won_minor')->default(0);
                $table->timestamp('joined_at')->useCurrent();
                $table->timestamp('left_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['private_table_id', 'user_id']);
                $table->index(['private_table_id', 'status']);
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('private_table_id')->nullable()->constrained('private_tables')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('receiver_id')->nullable()->constrained('users')->nullOnDelete(); // private chat
                $table->text('message')->nullable();
                $table->string('emoji')->nullable(); // Gameberry chat & send emojis
                $table->string('type')->default('text')->index(); // text, emoji, system, gift, challenge
                $table->boolean('is_muted')->default(false);
                $table->boolean('is_reported')->default(false);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['private_table_id', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('challenges')) {
            Schema::create('challenges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('challenger_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('challenged_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('private_table_id')->nullable()->constrained('private_tables')->nullOnDelete();
                $table->string('type')->index(); // 1vs1, team_up, etc
                $table->string('status')->default('pending')->index(); // pending, accepted, denied, expired
                $table->bigInteger('bet_amount_minor')->default(0);
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('responded_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['challenged_id', 'status']);
                $table->index(['challenger_id', 'status']);
            });
        }

        if (! Schema::hasTable('weekly_events')) {
            Schema::create('weekly_events', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('type')->index(); // tournament, dice_collection, gold_rush, etc
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->index();
                $table->string('status')->default('upcoming')->index(); // upcoming, active, completed
                $table->json('rewards')->nullable(); // gold, gems, dice, badges
                $table->json('requirements')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['status', 'starts_at']);
            });
        }

        if (! Schema::hasTable('weekly_event_participants')) {
            Schema::create('weekly_event_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('weekly_event_id')->constrained('weekly_events')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->integer('progress')->default(0);
                $table->integer('rank')->nullable()->index();
                $table->boolean('is_completed')->default(false);
                $table->json('rewards_claimed')->nullable();
                $table->timestamps();
                $table->unique(['weekly_event_id', 'user_id']);
                $table->index(['weekly_event_id', 'rank']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_event_participants');
        Schema::dropIfExists('weekly_events');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('private_table_participants');
        Schema::dropIfExists('private_tables');
    }
};
