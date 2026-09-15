<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 08 — payments + wallet + immutable ledger + refunds.
     *
     * payments          : gains integer minor-unit amounts (poisha), currency,
     *                     provider/provider reference, idempotency key, payer,
     *                     paid_at and refunded_at — the payment intent/state
     *                     machine columns.
     * wallets           : per-user BDT balance (minor units) with a single
     *                     active wallet per user.
     * ledger_entries    : append-only, double-entry-style (credit/debit)
     *                     record of every wallet movement with a running
     *                     balance snapshot.
     * refunds           : one authorized, auditable refund per payment.
     * payment_events    : append-only financial audit trail for payment
     *                     lifecycle changes.
     *
     * No existing column is dropped or rewritten: legacy decimal `amount`,
     * `method`, `trx_id` and `status` values remain valid and are mapped
     * honestly (a `verified` payment is a manually verified payment, never
     * falsely relabelled as provider-confirmed).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('amount_minor')->default(0)->after('amount');
            $table->string('currency', 8)->default('BDT')->after('amount_minor');
            $table->string('provider', 30)->nullable()->after('method');
            $table->string('provider_reference', 80)->nullable()->after('provider');
            $table->string('idempotency_key', 64)->nullable()->after('provider_reference');
            $table->foreignId('payer_user_id')->nullable()->after('trx_id')->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->after('status');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');

            $table->unique('idempotency_key', 'payments_idempotency_key_unique');
            $table->index('team_id', 'payments_team_index');
            $table->index('status', 'payments_status_index');
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 8)->default('BDT');
            $table->bigInteger('balance_minor')->default(0);
            $table->string('status')->default('active'); // active|frozen
            $table->timestamps();

            $table->unique('user_id', 'wallets_user_unique');
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->string('direction', 8); // credit | debit
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('balance_after');
            $table->string('currency', 8)->default('BDT');
            $table->string('type', 30); // deposit | refund | adjustment | reversal
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index('wallet_id', 'ledger_wallet_index');
            $table->index(['reference_type', 'reference_id'], 'ledger_reference_index');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 8)->default('BDT');
            $table->string('reason');
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique('payment_id', 'refunds_payment_unique');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->unsignedBigInteger('amount_minor')->default(0);
            $table->string('currency', 8)->default('BDT');
            $table->string('reference', 80)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('payment_id', 'payment_events_payment_index');
            $table->index('event', 'payment_events_event_index');
        });

        $this->backfillExistingPayments();
    }

    /**
     * Backfill legacy payment rows honestly:
     *  - amount_minor from the decimal amount (×100, poisha)
     *  - currency BDT
     *  - provider from method, provider_reference from trx_id
     *  - payer_user_id from the team's captain
     *  - paid_at for verified/refunded payments from updated_at
     *
     * `status` is deliberately NOT renamed: `verified` means manually
     * verified (Phase 01–07 behaviour) and is kept distinct from a
     * provider-confirmed `paid`.
     */
    protected function backfillExistingPayments(): void
    {
        $captainByTeam = DB::table('teams')->pluck('captain_id', 'id')->all();

        $payments = DB::table('payments')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $minor = (int) round((float) $payment->amount * 100);

            $update = [
                'amount_minor' => max(0, $minor),
                'currency' => 'BDT',
                'provider' => $payment->method ?: 'bkash',
                'provider_reference' => $payment->trx_id,
                'payer_user_id' => $captainByTeam[$payment->team_id] ?? null,
            ];

            if (in_array($payment->status, ['verified', 'refunded'], true)) {
                $update['paid_at'] = $payment->updated_at;
            }

            if ($payment->status === 'refunded') {
                $update['refunded_at'] = $payment->updated_at;
            }

            DB::table('payments')->where('id', $payment->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('wallets');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_idempotency_key_unique');
            $table->dropIndex('payments_team_index');
            $table->dropIndex('payments_status_index');
            $table->dropConstrainedForeignId('payer_user_id');
            $table->dropColumn([
                'amount_minor',
                'currency',
                'provider',
                'provider_reference',
                'idempotency_key',
                'paid_at',
                'refunded_at',
            ]);
        });
    }
};
