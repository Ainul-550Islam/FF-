<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('role')->default('player')->after('username'); // admin | organizer | player
            $table->string('phone')->nullable()->after('role');
            $table->string('game_uid')->nullable()->after('phone');
            $table->decimal('wallet_balance', 12, 2)->default(0)->after('game_uid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'phone', 'game_uid', 'wallet_balance']);
        });
    }
};
