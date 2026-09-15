<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('game_mode')->default('squad'); // squad | duo | solo
            $table->string('map')->default('Bermuda');
            $table->decimal('entry_fee', 12, 2)->default(0);      // per team
            $table->decimal('prize_pool', 12, 2)->default(0);
            $table->integer('team_slots')->default(16);           // 8|16|32
            $table->integer('team_size')->default(4);             // players per team
            $table->text('rules')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->string('status')->default('draft'); // draft|open|closed|live|finished
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
