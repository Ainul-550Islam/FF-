<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 09 — prize distribution + payouts + revenue/commission +
     * financial reconciliation.
     *
     * prize_tiers            : configurable prize rules per tournament
     *                          (fixed minor-unit amounts OR percentage-based),
     *                          validated server-side and snapshotted before
     *                          distribution.
     * prize_distributions    : the prize-distribution state machine
     *                          (draft → calculated → approved → processing →
     *                          completed / failed / cancelled) with the prize
     *                          pool snapshot used at calculation time.
     * prize_snapshot_items   : immutable rank → team → amount snapshot rows
     *                          captured when a distribution is calculated.
     *                          Historical prize calculations never change when
     *                          the tournament, fees or tiers are later edited.
     * payouts                : one payout record per awarded rank, with the
     *                          server-derived recipient, integer minor-unit
     *                          amount, controlled status machine and a unique
     *                          idempotency key. A payout is never processed
     *                          twice.
     * payout_events          : append-only audit trail for payout lifecycle
     *                          changes.
     * financial_settlements  : immutable per-tournament financial snapshot
     *                          (gross/refunds/net/pool/allocated/completed/
     *                          revenue/adjustments + reconciliation result +
     *                          finalizer). Written once at finalization.
     * settlement_adjustments : approved manual correction/reversal records
     *                          (signed minor units) used instead of mutating
     *                          historical financial values.
     *
     * All monetary columns are integer minor units (BDT poisha); there is no
     * floating-point money. No Phase 01–08 table or column is modified or
     * dropped.
     */
    public function up(): void
    {
        $guard = function (string $table, \Closure $create) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $create);
            }
        };
        $guard('prize_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position'); // 1-based rank (1st, 2nd, …)
            $table->string('type', 16);          // fixed | percentage
            $table->unsignedBigInteger('amount_minor')->nullable();   // fixed poisha
            $table->unsignedInteger('percentage_bp')->nullable();     // basis points
            $table->timestamps();

            $table->unique(['tournament_id', 'position'], 'prize_tiers_tournament_position_unique');
        });

        $guard('prize_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('draft'); // draft|calculated|approved|processing|completed|failed|cancelled
            $table->unsignedBigInteger('pool_minor')->default(0);            // pool snapshot at calculation
            $table->unsignedBigInteger('total_allocated_minor')->default(0); // sum of snapshot items
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'prize_distributions_idem_unique');
            $table->index('tournament_id', 'prize_distributions_tournament_index');
            $table->index('status', 'prize_distributions_status_index');
        });

        $guard('prize_snapshot_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('prize_distributions')->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');          // rank
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->string('type', 16);                   // fixed | percentage (as configured)
            $table->unsignedBigInteger('amount_minor');   // resolved amount (poisha)
            $table->timestamp('created_at')->nullable();

            $table->unique(['distribution_id', 'position'], 'prize_snapshot_distribution_position_unique');
            $table->index('tournament_id', 'prize_snapshot_tournament_index');
        });

        $guard('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_id')->constrained('prize_distributions')->cascadeOnDelete();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recipient_team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->unsignedInteger('rank');
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8)->default('BDT');
            $table->string('status', 16)->default('pending'); // pending|approved|processing|completed|failed|cancelled
            $table->string('payout_method', 16)->default('wallet'); // wallet | manual
            $table->string('provider', 30)->default('wallet');
            $table->string('provider_reference', 80)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['distribution_id', 'rank'], 'payouts_distribution_rank_unique');
            $table->unique('idempotency_key', 'payouts_idem_unique');
            $table->index('tournament_id', 'payouts_tournament_index');
            $table->index('recipient_user_id', 'payouts_recipient_index');
            $table->index('status', 'payouts_status_index');
        });

        $guard('payout_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 8)->default('BDT');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payout_id', 'payout_events_payout_index');
            $table->index('event', 'payout_events_event_index');
        });

        $guard('financial_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('gross_collected_minor')->default(0);
            $table->unsignedBigInteger('refunded_minor')->default(0);
            $table->unsignedBigInteger('net_collected_minor')->default(0);
            $table->unsignedBigInteger('prize_pool_minor')->default(0);
            $table->unsignedBigInteger('allocated_prizes_minor')->default(0);
            $table->unsignedBigInteger('completed_payouts_minor')->default(0);
            $table->unsignedBigInteger('platform_revenue_minor')->default(0);
            $table->bigInteger('adjustments_minor')->default(0); // signed
            $table->string('reconciliation_status', 20); // balanced|underfunded|overallocated|mismatch
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique('tournament_id', 'financial_settlements_tournament_unique');
        });

        $guard('settlement_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor'); // signed: credit positive, debit negative
            $table->string('type', 16)->default('correction'); // correction | reversal
            $table->string('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('tournament_id', 'settlement_adjustments_tournament_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_adjustments');
        Schema::dropIfExists('financial_settlements');
        Schema::dropIfExists('payout_events');
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('prize_snapshot_items');
        Schema::dropIfExists('prize_distributions');
        Schema::dropIfExists('prize_tiers');
    }
};
