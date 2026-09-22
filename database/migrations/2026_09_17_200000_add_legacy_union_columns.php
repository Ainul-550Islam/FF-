<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Union of legacy schema columns.
 *
 * The project evolved through two migration generations. Code in controllers,
 * policies, resources and tests references a few columns that only one of the
 * generations ever created. This migration guarantees the union schema without
 * touching either original migration: every column is added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tournaments')) {
            // Columns from the pre-Phase04 (2026_09_04_000002) tournaments
            // generation that controllers and tests still use.
            $legacy = [
                'organizer_id' => fn (Blueprint $table) => $table->foreignId('organizer_id')->nullable()->after('id'),
                'game_mode' => fn (Blueprint $table) => $table->string('game_mode', 20)->nullable(),
                'map' => fn (Blueprint $table) => $table->string('map', 60)->nullable(),
                'entry_fee' => fn (Blueprint $table) => $table->unsignedInteger('entry_fee')->default(0),
                'prize_pool' => fn (Blueprint $table) => $table->unsignedInteger('prize_pool')->default(0),
                'team_slots' => fn (Blueprint $table) => $table->unsignedInteger('team_slots')->default(0),
                'team_size' => fn (Blueprint $table) => $table->unsignedInteger('team_size')->default(1),
                'rules' => fn (Blueprint $table) => $table->text('rules')->nullable(),
            ];

            foreach ($legacy as $col => $def) {
                if (!Schema::hasColumn('tournaments', $col)) {
                    Schema::table('tournaments', $def);
                }
            }

            $usersPk = Schema::getColumns('users');
            if (in_array('id', array_column($usersPk, 'name'), true)) {
                $fkExists = false;
                foreach (Schema::getIndexes('tournaments') as $index) {
                    if (in_array('organizer_id', $index['columns'], true)) {
                        $fkExists = true;
                    }
                }
                if (!$fkExists) {
                    Schema::table('tournaments', function (Blueprint $table) {
                        $table->foreign('organizer_id')->references('id')->on('users')->nullOnDelete();
                    });
                }
            }
        }

        // payments: the Phase-04 wallet schema declares user_id / external_id
        // NOT NULL, but the Phase-14 checkout flow keys payments via
        // payer_user_id/trx_id and leaves those unset. Make them nullable so
        // both generations coexist on the union schema.
        if (Schema::hasTable('payments')) {
            // Union semantics (see nullableUnionColumns): each generation
            // inserts rows that omit the other generation's columns.
            $this->nullableUnionColumns('payments');
        }

        // payouts: the Phase-14 prize-distribution flow keys payouts to a
        // distribution/rank with explicit recipient + processing audit. The
        // Phase-04 schema only has user_id/tournament_id/amount_minor.
        if (Schema::hasTable('payouts')) {
            // Union semantics: every non-PK column that is NOT NULL without a
            // default becomes nullable, because each schema generation inserts
            // rows that legitimately omit the other generation's columns.
            $this->nullableUnionColumns('payouts');

            $payoutCols = [
                'distribution_id' => 'foreignIdNullable',
                'rank' => 'unsignedIntegerNullable',
                'recipient_user_id' => 'foreignIdNullableUsers',
                'recipient_team_id' => 'foreignIdNullableTeams',
                'payout_method' => 'string30Nullable',
                'approved_by' => 'foreignIdNullableUsers',
                'processed_by' => 'foreignIdNullableUsers',
                'processed_at' => 'timestampNullable',
                // The settlement flow records the external/manual reference on
                // completion and the reason on failure.
                'provider_reference' => 'string60Nullable',
                'failure_reason' => 'string255Nullable',
            ];
            foreach (array_keys($payoutCols) as $col) {
                if (Schema::hasColumn('payouts', $col)) {
                    continue;
                }
                Schema::table('payouts', function (Blueprint $table) use ($col, $payoutCols) {
                    switch ($payoutCols[$col]) {
                        case 'foreignIdNullable':
                            $table->foreignId($col)->nullable();
                            break;
                        case 'unsignedIntegerNullable':
                            $table->unsignedInteger($col)->nullable();
                            break;
                        case 'foreignIdNullableUsers':
                            $table->foreignId($col)->nullable()->constrained('users')->nullOnDelete();
                            break;
                        case 'foreignIdNullableTeams':
                            $table->foreignId($col)->nullable()->constrained('teams')->nullOnDelete();
                            break;
                        case 'string30Nullable':
                            $table->string($col, 30)->nullable();
                            break;
                        case 'string60Nullable':
                            $table->string($col, 60)->nullable();
                            break;
                        case 'string255Nullable':
                            $table->string($col, 255)->nullable();
                            break;
                        case 'timestampNullable':
                            $table->timestamp($col)->nullable();
                            break;
                    }
                });
            }
            try {
                Schema::table('payouts', function (Blueprint $table) {
                    $table->unique(['distribution_id', 'rank'], 'payouts_distribution_rank_unique');
                });
            } catch (\Throwable $e) {
                if (!str_contains($e->getMessage(), 'duplicate') && !str_contains($e->getMessage(), 'already exists')) {
                    throw $e;
                }
            }
        }

        // financial_settlements: the pre-Phase09 migration creates the table
        // with the legacy totals only, so the prize-distribution generation's
        // reconciliation snapshot columns must be guaranteed here.
        if (Schema::hasTable('financial_settlements')) {
            // The legacy generation declares total_amount_minor NOT NULL; the
            // settlement snapshot inserts its own columns instead.
            $this->nullableUnionColumns('financial_settlements');

            $settlementCols = [
                'gross_collected_minor' => 'unsignedBigIntegerDefault',
                'refunded_minor' => 'unsignedBigIntegerDefault',
                'net_collected_minor' => 'unsignedBigIntegerDefault',
                'prize_pool_minor' => 'unsignedBigIntegerDefault',
                'allocated_prizes_minor' => 'unsignedBigIntegerDefault',
                'completed_payouts_minor' => 'unsignedBigIntegerDefault',
                'platform_revenue_minor' => 'unsignedBigIntegerDefault',
                'adjustments_minor' => 'bigIntegerDefault',
                'reconciliation_status' => 'string20Nullable',
                'finalized_by' => 'foreignIdNullableUsers',
                'finalized_at' => 'timestampNullable',
            ];
            foreach ($settlementCols as $col => $kind) {
                if (Schema::hasColumn('financial_settlements', $col)) {
                    continue;
                }
                Schema::table('financial_settlements', function (Blueprint $table) use ($col, $kind) {
                    switch ($kind) {
                        case 'unsignedBigIntegerDefault':
                            $table->unsignedBigInteger($col)->default(0);
                            break;
                        case 'bigIntegerDefault':
                            $table->bigInteger($col)->default(0);
                            break;
                        case 'string20Nullable':
                            $table->string($col, 20)->nullable();
                            break;
                        case 'foreignIdNullableUsers':
                            $table->foreignId($col)->nullable()->constrained('users')->nullOnDelete();
                            break;
                        case 'timestampNullable':
                            $table->timestamp($col)->nullable();
                            break;
                    }
                });
            }
        }

        if (Schema::hasTable('login_events') && !Schema::hasColumn('login_events', 'updated_at')) {
            Schema::table('login_events', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            });
        }

        // notifications: the app's own rows key on user_id, but the User model
        // also uses Laravel's Notifiable trait (the shared layout renders
        // $user->unreadNotifications), which expects the standard morph
        // columns. Guarantee both exist on the union table.
        if (Schema::hasTable('notifications')) {
            if (!Schema::hasColumn('notifications', 'notifiable_id')) {
                Schema::table('notifications', function (Blueprint $table) {
                    $table->unsignedBigInteger('notifiable_id')->nullable();
                    $table->string('notifiable_type')->nullable();
                    $table->index(['notifiable_id', 'notifiable_type'], 'notifications_notifiable_index');
                });
            }
        }

        // payments: the Phase-14 checkout flow keys payments to a
        // tournament/team/payer and carries the gateway method + trx id.
        // The Phase-04 wallet schema omits them; guarantee the union.
        if (Schema::hasTable('payments')) {
            $pay = [
                'tournament_id' => fn (Blueprint $table) => $table->foreignId('tournament_id')->nullable(),
                'team_id' => fn (Blueprint $table) => $table->foreignId('team_id')->nullable(),
                'payer_user_id' => fn (Blueprint $table) => $table->foreignId('payer_user_id')->nullable(),
                'amount' => fn (Blueprint $table) => $table->decimal('amount', 12, 2)->nullable(),
                'method' => fn (Blueprint $table) => $table->string('method', 30)->nullable(),
                'trx_id' => fn (Blueprint $table) => $table->string('trx_id', 60)->nullable(),
            ];
            foreach ($pay as $col => $def) {
                if (!Schema::hasColumn('payments', $col)) {
                    Schema::table('payments', $def);
                }
            }
        }

        // ledger_entries: the financial-architecture generation documents
        // type (deposit|refund|adjustment|reversal|...) and description on
        // the ledger, plus the running-balance snapshot (balance_after) and
        // the movement currency; guarantee the columns exist on the union
        // schema so both generations read/write the same rows.
        if (Schema::hasTable('ledger_entries')) {
            if (!Schema::hasColumn('ledger_entries', 'type')) {
                Schema::table('ledger_entries', function (Blueprint $table) {
                    $table->string('type', 30)->nullable();
                });
            }
            if (!Schema::hasColumn('ledger_entries', 'description')) {
                Schema::table('ledger_entries', function (Blueprint $table) {
                    $table->string('description', 255)->nullable();
                });
            }
            if (!Schema::hasColumn('ledger_entries', 'balance_after')) {
                Schema::table('ledger_entries', function (Blueprint $table) {
                    $table->bigInteger('balance_after')->nullable();
                });
            }
            if (!Schema::hasColumn('ledger_entries', 'currency')) {
                Schema::table('ledger_entries', function (Blueprint $table) {
                    $table->string('currency', 8)->default('BDT');
                });
            }

            // Backfill: the legacy rows carry the running balance in
            // balance_after_minor; mirror it into balance_after so either
            // column reads the same value.
            DB::table('ledger_entries')
                ->whereNull('balance_after')
                ->whereNotNull('balance_after_minor')
                ->update(['balance_after' => DB::raw('balance_after_minor')]);
        }

        // users: the Phase 14 account-security generation records two-factor
        // state on the user row.
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'two_factor_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('two_factor_enabled')->default(false);
            });
        }

        // login_events: the Phase 14 generation records the auth status, the
        // resolved location and the raw request metadata for the user-facing
        // login history.
        if (Schema::hasTable('login_events')) {
            $loginColumns = [
                'ip_address' => fn (Blueprint $table) => $table->string('ip_address', 45)->nullable(),
                'user_agent' => fn (Blueprint $table) => $table->string('user_agent', 512)->nullable(),
                'location' => fn (Blueprint $table) => $table->string('location', 120)->nullable(),
                'status' => fn (Blueprint $table) => $table->string('status', 20)->nullable(),
                'successful' => fn (Blueprint $table) => $table->boolean('successful')->default(true),
            ];

            foreach ($loginColumns as $col => $def) {
                if (Schema::hasColumn('login_events', $col)) {
                    continue;
                }

                Schema::table('login_events', function (Blueprint $table) use ($def) {
                    $def($table);
                });
            }
        }

        // webhook_events: the Phase 15 ingress generation tracks the provider
        // event id, the signature verdict, the processing status/state and the
        // encrypted payload + receipt timestamp.
        if (Schema::hasTable('webhook_events')) {
            $webhookColumns = [
                'external_event_id' => fn (Blueprint $table) => $table->string('external_event_id', 191)->nullable(),
                'signature_status' => fn (Blueprint $table) => $table->string('signature_status', 20)->nullable(),
                'status' => fn (Blueprint $table) => $table->string('status', 30)->nullable(),
                'payload_encrypted' => fn (Blueprint $table) => $table->text('payload_encrypted')->nullable(),
                'metadata' => fn (Blueprint $table) => $table->json('metadata')->nullable(),
                'received_at' => fn (Blueprint $table) => $table->timestamp('received_at')->nullable(),
            ];

            foreach ($webhookColumns as $col => $def) {
                if (Schema::hasColumn('webhook_events', $col)) {
                    continue;
                }

                Schema::table('webhook_events', function (Blueprint $table) use ($def) {
                    $def($table);
                });
            }

            // Idempotency for (provider, external_event_id): the ingress never
            // processes the same provider event twice.
            $indexName = 'webhook_events_provider_external_event_unique';

            if (! Schema::hasIndex('webhook_events', $indexName)) {
                Schema::table('webhook_events', function (Blueprint $table) use ($indexName) {
                    $table->unique(['provider', 'external_event_id'], $indexName);
                });
            }

            if (! Schema::hasIndex('webhook_events', 'webhook_events_status_index')) {
                Schema::table('webhook_events', function (Blueprint $table) {
                    $table->index('status');
                });
            }

            // Union: the ingress generation keys rows by external_event_id and
            // never sets the legacy event_id, so that column must be nullable
            // (the unique indexes still guarantee one row per event).
            if (Schema::hasColumn('webhook_events', 'event_id')) {
                Schema::table('webhook_events', function (Blueprint $table) {
                    $table->string('event_id', 191)->nullable()->change();
                });
            }

            // Backfill both generations' identifiers/state onto one another.
            DB::table('webhook_events')
                ->whereNull('external_event_id')
                ->whereNotNull('event_id')
                ->update(['external_event_id' => DB::raw('event_id')]);

            DB::table('webhook_events')
                ->whereNull('status')
                ->whereNotNull('state')
                ->update(['status' => DB::raw('state')]);

            DB::table('webhook_events')
                ->whereNull('event_id')
                ->whereNotNull('external_event_id')
                ->update(['event_id' => DB::raw('external_event_id')]);
        }

        // payment_methods: the Phase 14 generation verifies a saved method and
        // tracks its masked identifier hash + last use.
        if (Schema::hasTable('payment_methods')) {
            $methodColumns = [
                'identifier_hash' => fn (Blueprint $table) => $table->string('identifier_hash', 64)->nullable(),
                'is_verified' => fn (Blueprint $table) => $table->boolean('is_verified')->default(false),
                'last_used_at' => fn (Blueprint $table) => $table->timestamp('last_used_at')->nullable(),
            ];

            foreach ($methodColumns as $col => $def) {
                if (Schema::hasColumn('payment_methods', $col)) {
                    continue;
                }

                Schema::table('payment_methods', function (Blueprint $table) use ($def) {
                    $def($table);
                });
            }
        }

        // user_identities: the Phase 14 linked-provider generation stores the
        // provider subject, the profile snapshot and the raw payload.
        if (Schema::hasTable('user_identities')) {
            $identityColumns = [
                'provider_user_id' => fn (Blueprint $table) => $table->string('provider_user_id', 191)->nullable(),
                'email' => fn (Blueprint $table) => $table->string('email', 191)->nullable(),
                'name' => fn (Blueprint $table) => $table->string('name', 191)->nullable(),
                'avatar' => fn (Blueprint $table) => $table->string('avatar', 512)->nullable(),
                'payload' => fn (Blueprint $table) => $table->json('payload')->nullable(),
            ];

            foreach ($identityColumns as $col => $def) {
                if (Schema::hasColumn('user_identities', $col)) {
                    continue;
                }

                Schema::table('user_identities', function (Blueprint $table) use ($def) {
                    $def($table);
                });
            }

            // Backfill the provider subject from the legacy identifier column
            // (both hold the same provider-side id).
            if (Schema::hasColumn('user_identities', 'provider_id')) {
                DB::table('user_identities')
                    ->whereNull('provider_user_id')
                    ->whereNotNull('provider_id')
                    ->update(['provider_user_id' => DB::raw('provider_id')]);
            }
        }

        // otp_challenges: the Phase 14 generation keys a challenge by the
        // delivery channel/reference and enforces an attempt ceiling.
        if (Schema::hasTable('otp_challenges')) {
            $otpColumns = [
                'reference' => fn (Blueprint $table) => $table->string('reference', 191)->nullable(),
                'provider' => fn (Blueprint $table) => $table->string('provider', 40)->nullable(),
                'max_attempts' => fn (Blueprint $table) => $table->unsignedSmallInteger('max_attempts')->default(5),
                'consumed_at' => fn (Blueprint $table) => $table->timestamp('consumed_at')->nullable(),
            ];

            foreach ($otpColumns as $col => $def) {
                if (Schema::hasColumn('otp_challenges', $col)) {
                    continue;
                }

                Schema::table('otp_challenges', function (Blueprint $table) use ($def) {
                    $def($table);
                });
            }
        }
    }

    /**
     * Union-schema relaxation: each schema generation inserts rows that
     * legitimately omit the other generation's columns, so every non-PK
     * column that is NOT NULL without a default is made nullable. This never
     * drops data and never tightens a constraint.
     */
    protected function nullableUnionColumns(string $table): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }

        foreach (Schema::getColumns($table) as $col) {
            if ($col['name'] === 'id' || !empty($col['nullable']) || $col['default'] !== null) {
                continue;
            }

            $type = strtolower((string) $col['type']);
            $precision = $col['precision'] ?? null;
            $scale = $col['scale'] ?? null;
            $method = match (true) {
                str_contains($type, 'int') => 'bigInteger',
                str_contains($type, 'char'), str_contains($type, 'text') => 'string',
                str_contains($type, 'real'), str_contains($type, 'float'), str_contains($type, 'double') => 'double',
                default => 'decimal',
            };

            Schema::table($table, function (Blueprint $table) use ($col, $method, $precision, $scale) {
                $column = $method === 'decimal'
                    ? $table->decimal($col['name'], (int) ($precision ?? 12), (int) ($scale ?? 2))->nullable()
                    : $table->{$method}($col['name'])->nullable();
                $column->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tournaments') && Schema::hasColumn('tournaments', 'organizer_id')) {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('organizer_id');
            });
        }

        if (Schema::hasTable('login_events') && Schema::hasColumn('login_events', 'updated_at')) {
            Schema::table('login_events', function (Blueprint $table) {
                $table->dropColumn('updated_at');
            });
        }
    }
};
