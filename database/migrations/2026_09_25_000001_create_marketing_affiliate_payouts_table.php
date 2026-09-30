<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 22 (Phase C) — affiliate payout workflow.
 *
 * Tracks partner commission payout requests, review approvals, rejections,
 * and wallet disbursements. The financial source of truth remains the
 * Wallet + Ledger architecture; this table maintains the audit lifecycle.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_affiliate_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('marketing_affiliates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8)->default('BDT');
            $table->string('status', 24)->default('pending')->index();
            $table->string('payout_method', 24)->default('wallet');
            $table->timestamp('requested_at')->useCurrent();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->text('review_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
            $table->index('requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_affiliate_payouts');
    }
};
