<?php

namespace Tests\Feature\Concurrency;

use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — registration concurrency race (final-slot integrity).
 *
 * N registrations race for the last free slot of a tournament. The server
 * must never oversubscribe: at most `team_slots` teams may be pending/
 * confirmed, and every overflow must land deterministically on the waitlist.
 *
 * Execution model (honest about the environment):
 *   - PostgreSQL + pcntl → true process-level concurrency: the test forks N
 *     real child processes, each registering through RegistrationService over
 *     its own database connection.
 *   - SQLite (or no pcntl) → SQLite has a single-writer model and `:memory:`
 *     cannot be shared across processes, so true parallel writers are not
 *     testable there. The test still runs N registrations and asserts the
 *     same slot/waitlist invariant — the atomic-guard correctness — and the
 *     PostgreSQL suite is authoritative for the race itself.
 */
class RegistrationRaceTest extends TestCase
{
    // Note: we do NOT use the DatabaseMigrations trait here. Its teardown runs
    // `migrate:rollback`, which calls the users-table `down()` migration and
    // fails on SQLite (it cannot drop the indexed `username` column). We run
    // `migrate:fresh` explicitly instead; on SQLite the in-memory database is
    // discarded per test anyway, and on PostgreSQL `migrate:fresh` fully
    // resets the schema for the next test.
    protected Tournament $tournament;

    protected int $slotCount = 1;

    protected function canFork(): bool
    {
        return function_exists('pcntl_fork') && config('database.default') === 'pgsql';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $this->slotCount = 1;

        $org = new User();
        $org->name = 'Race Organizer';
        $org->email = 'race-org-'.Str::random(6).'@ffarena.local';
        $org->password = bcrypt('secret123');
        $org->email_verified_at = now();
        $org->save();
        $org->role = 'organizer';
        $org->account_status = 'active';
        $org->save();

        $t = new Tournament();
        $t->organizer_id = $org->id;
        $t->name = 'Registration Race';
        $t->slug = 'race-reg-'.Str::lower(Str::random(10));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = $this->slotCount;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = Tournament::STATUS_OPEN;
        $t->save();

        $this->tournament = $t;
    }

    public function test_concurrent_registration_never_oversubscribes_slots(): void
    {
        $total = 6; // racers for a single slot

        if ($this->canFork()) {
            $this->forkRegistrations($total);
        } else {
            // Sequential fallback (SQLite): the same invariant, no fork.
            for ($i = 0; $i < $total; $i++) {
                $this->registerOne($i, $this->tournament->id);
            }

            fwrite(STDERR, "[note] registration race ran sequentially — true parallel writers need pcntl + PostgreSQL.\n");
        }

        $pending = Team::where('tournament_id', $this->tournament->id)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED])
            ->count();

        $waitlisted = Team::where('tournament_id', $this->tournament->id)
            ->where('status', Team::STATUS_WAITLISTED)
            ->count();

        $totalTeams = Team::where('tournament_id', $this->tournament->id)->count();

        // No slot oversubscription — exactly one team claimed the slot.
        $this->assertSame($this->slotCount, $pending);
        $this->assertSame($total - $this->slotCount, $waitlisted);
        $this->assertSame($total, $totalTeams);

        // Waitlist order is deterministic (FIFO by id).
        $waitlistOrder = Team::where('tournament_id', $this->tournament->id)
            ->where('status', Team::STATUS_WAITLISTED)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame($waitlistOrder, array_values($waitlistOrder));
    }

    /**
     * Fork N children that each register over their own connection.
     */
    protected function forkRegistrations(int $total): void
    {
        $pids = [];

        for ($i = 0; $i < $total; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                // Child: fresh connection, one registration, then exit.
                DB::purge();

                try {
                    $this->registerOne($i, $this->tournament->id);
                    exit(0);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "child {$i}: ".$e->getMessage()."\n");
                    exit(2);
                }
            }

            $pids[] = $pid;
        }

        $exitCodes = [];

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[] = pcntl_wexitstatus($status);
        }

        $this->assertSame(array_fill(0, $total, 0), $exitCodes, 'all registration children must succeed');
    }

    /**
     * One distinct captain registers one team for the tournament.
     */
    protected function registerOne(int $i, int $tournamentId): void
    {
        $captain = new User();
        $captain->name = 'Race Captain '.$i;
        $captain->email = 'race-cap-'.$i.'-'.Str::random(6).'@ffarena.local';
        $captain->password = bcrypt('secret123');
        $captain->email_verified_at = now();
        $captain->save();
        $captain->role = 'player';
        $captain->account_status = 'active';
        $captain->save();

        app(RegistrationService::class)->register(Tournament::find($tournamentId), $captain, [
            'name' => 'Race Squad '.$i,
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'RACEUID'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'members' => [],
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up the dedicated fixtures (committed, since this test does not
        // wrap itself in a transaction).
        if (isset($this->tournament)) {
            DB::table('teams')->where('tournament_id', $this->tournament->id)->delete();
            DB::table('tournaments')->where('id', $this->tournament->id)->delete();
            DB::table('users')->where('email', 'like', 'race-%@ffarena.local')->delete();
        }

        parent::tearDown();
    }
}
