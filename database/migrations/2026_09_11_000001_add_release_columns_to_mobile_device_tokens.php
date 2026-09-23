<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Phase 19 — device-token release metadata.
     *
     * Each registered push device now records the client's app version and
     * build environment so operations can target (or exclude) a release
     * channel during push fan-out and support can reason about which app
     * version a device is running. Additive columns only — existing Phase 18
     * rows keep working with NULL values.
     */
    public function up(): void
    {
        Schema::table('mobile_device_tokens', function (Blueprint $table) {
            $table->string('app_version', 30)->nullable()->after('device_label');
            $table->string('environment', 20)->nullable()->after('app_version');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_device_tokens', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'environment']);
        });
    }
};
