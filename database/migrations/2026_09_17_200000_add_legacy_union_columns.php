<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // the ledger; guarantee the columns exist on the union schema.
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
