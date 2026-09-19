<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if(!Schema::hasTable('idempotency_records_go')){
            Schema::create('idempotency_records_go', function (Blueprint $table) {
                $table->string('key')->primary(); $table->string('fingerprint'); $table->string('operation')->index(); $table->bigInteger('user_id')->nullable(); $table->json('request_body')->nullable(); $table->json('response_body')->nullable(); $table->integer('status_code')->nullable(); $table->timestamp('expires_at')->nullable()->index(); $table->timestamps();
            });
        }
    }
    public function down(): void{Schema::dropIfExists('idempotency_records_go');}
};
