<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // __tolerant
        try {

            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });

        } catch (Throwable $__e) {
            $m = $__e->getMessage();
            if (! str_contains($m, 'duplicate column name') && ! str_contains($m, 'already exists') && ! str_contains($m, 'duplicate index') && ! str_contains($m, 'no such column') && ! str_contains($m, 'datatype mismatch')) {
                throw $__e;
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
