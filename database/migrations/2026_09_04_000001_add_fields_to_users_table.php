<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // Idempotent column additions — the users table may originate from the
        // standard scaffold migration or from create_all_tables; the app needs
        // the union of both schema generations, so each column is added only
        // when missing. Existing logic and defaults preserved.
        $add = function (string $col, Closure $def) {
            if (! Schema::hasColumn('users', $col)) {
                Schema::table('users', $def);
            }
        };

        $add('username', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });
        $add('role', function (Blueprint $table) {
            $table->string('role')->default('player')->after('username'); // admin | organizer | player
        });
        $add('phone', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('role');
        });
        $add('game_uid', function (Blueprint $table) {
            $table->string('game_uid')->nullable()->after('phone');
        });
        $add('wallet_balance', function (Blueprint $table) {
            $table->decimal('wallet_balance', 12, 2)->default(0)->after('game_uid');
        });
        $add('is_admin', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('wallet_balance');
        });
        $add('is_staff', function (Blueprint $table) {
            $table->boolean('is_staff')->default(false)->after('is_admin');
        });
        $add('is_active', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_staff');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'phone', 'game_uid', 'wallet_balance']);
        });
    }
};
