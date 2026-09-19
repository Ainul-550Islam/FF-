<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 50)->index();
            $table->string('event_id', 100)->nullable()->index();
            $table->string('external_ref', 100)->nullable()->index();
            $table->json('payload')->nullable();
            $table->binary('raw_body')->nullable();
            $table->text('error')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamp('first_failed_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();
            
            $table->index(['provider', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_dead_letters');
    }
};
