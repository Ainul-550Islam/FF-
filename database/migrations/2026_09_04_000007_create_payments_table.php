<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $guard = function (string $table, Closure $create) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $create);
            }
        };
        $guard('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('method')->default('bkash'); // bkash | nagad | manual
            $table->string('trx_id')->nullable();
            $table->string('status')->default('pending'); // pending|verified|failed|refunded
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
