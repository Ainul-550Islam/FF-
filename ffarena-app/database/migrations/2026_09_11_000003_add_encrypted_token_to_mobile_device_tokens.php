<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 19 — encrypted raw push token for server-side delivery.
     *
     * Phase 18 stored only the sha256 token hash (sufficient for dedup, not
     * for delivery). Server-side push requires the raw token, so it is now
     * stored encrypted at rest (Laravel `encrypted` cast, APP_KEY-based). The
     * plaintext token is NEVER serialized to API responses and NEVER logged;
     * the sha256 hash remains the dedup and identity key.
     */
    public function up(): void
    {
        Schema::table('mobile_device_tokens', function (Blueprint $table) {
            $table->text('encrypted_token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_device_tokens', function (Blueprint $table) {
            $table->dropColumn('encrypted_token');
        });
    }
};
