<?php

namespace Tests\Feature\Concurrency;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\RosterService;
use App\Services\TournamentParticipationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-39 — a waiting writer must not be able to claim a slot that was already taken.
 *
 * THE DEFECT
 * ----------
 * Four guards in this codebase decide "is there room?" inside the WHERE clause
 * of an UPDATE:
 *
 *     UPDATE tournaments SET updated_at = now()
 *     WHERE id = ? AND ... 
 *       AND (SELECT COUNT(*) FROM teams ...) < team_slots
 *
 * On SQLite that is airtight: the UPDATE takes the database write lock and
 * everything after it in the transaction is serialised. On PostgreSQL it is
 * not. Under READ COMMITTED the scalar subquery is planned as an InitPlan and
 * evaluated once with the snapshot taken when the statement *started*; the
 * re-check PostgreSQL performs after a lock wait does not refresh it. A second
 * writer that arrives while the first is still mid-transaction therefore still
 * sees the free slot, claims it, and two teams hold one slot.
 *
 * This is the same family as the A2 defect (a lock that does not lock). It was
 * found by running the concurrency directories in isolation — the probabilistic
 * race test only fails about one run in five, so the regression test below is
 * deterministic instead: the parent holds the parent-row lock deliberately and
 * the child is guaranteed to be waiting on it.
 *
 * WHY THIS HARNESS LOOKS THE WAY IT DOES
 * ---------------------------------------
 * Two details are not incidental:
 *
 *   * the parent holds the blocking transaction on a **dedicated connection**.
 *     A forked child inherits every open file descriptor, and when the child
 *     closes the inherited PDO, libpq sends a TLS close_notify / Terminate on
 *     the socket the parent is still using — the parent's next statement then
 *     dies with `SSL error: decryption failed or bad record mac`. Keeping the
 *     parent's transaction on its own session means the child's cleanup cannot
 *     touch it.
 *   * the child ends with SIGKILL after writing its report, so no destructor
 *     runs in it and nothing is ever written to an inherited socket. It has
 *     already committed its work by then (`DB::transaction()` commits inside
 *     the service), so nothing is lost.
 *
 * THE MECHANISM THE FIX RELIES ON
 * -------------------------------
 * A locking read of the parent row *before* the count is read. Every contender
 * waits there, and every statement issued after the lock is granted runs with a
 * fresh snapshot that can see the winner's committed row. On SQLite
 * `lockForUpdate()` compiles to nothing (SQLite grammar) and the atomic UPDATE
 * keeps its original job: taking the write lock.
 *
 * These tests are PostgreSQL-only on purpose. On SQLite there is a single
 * writer, the situation cannot arise, and a skipped test is reported as such.
 */
class SlotClaimSerialisationTest extends TestCase
{
    /**
     * We do not use DatabaseMigrations: its teardown runs `migrate:rollback`,
     * which fails on the users-table down() migration. `migrate:fresh` is the
     * reset this file needs, exactly as in RegistrationRaceTest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
    }

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh');

        parent::tearDown();
    }

    protected function canFork(): bool
    {
        return function_exists('pcntl_fork') && config('database.default') === 'pgsql';
    }

    protected function requireFork(): void
    {
        if (! $this->canFork()) {
            $this->markTestSkipped('Slot-claim serialisation requires PostgreSQL + pcntl (true parallel writers).');
        }
    }

    /**
     * A second connection to the same database, used to hold a blocking
     * transaction while a child waits on it.
     */
    protected function holder(): \Illuminate\Database\Connection
    {
        $default = config('database.default');

        if (! config('database.connections.slot_holder')) {
            config(['database.connections.slot_holder' => config("database.connections.{$default}")]);
        }

        return DB::connection('slot_holder');
    }

    /**
     * Fork a child that runs $work against its own database connection.
     *
     * The child writes its report and then kills itself without running
     * destructors: see the class docblock for why that is deliberate.
     *
     * @return array{0:int,1:string} the child pid and the file it will report into
     */
    protected function forkChild(callable $work): array
    {
        $report = tempnam(sys_get_temp_dir(), 'slot-claim-');
        file_put_contents($report, '');

        $pid = pcntl_fork();

        if ($pid === 0) {
            // The child gets its own session for the default connection. The
            // parent's holder connection is a different session and is never
            // touched here (and never destructed: the SIGKILL below sees to it).
            DB::purge();

            try {
                $payload = ['outcome' => $work(), 'error' => null];
            } catch (\Throwable $e) {
                $payload = ['outcome' => null, 'error' => get_class($e).': '.$e->getMessage()];
            }

            file_put_contents($report, json_encode($payload));
            fflush(STDOUT);

            posix_kill(getmypid(), SIGKILL);

            exit(0); // unreachable: the SIGKILL above ends this process
        }

        $this->assertGreaterThan(0, $pid, 'pcntl_fork failed');

        return [$pid, $report];
    }

    /**
     * Wait for the child and return what it reported.
     *
     * @return array{outcome:?string,error:?string}
     */
    protected function awaitChild(int $pid, string $report): array
    {
        pcntl_waitpid($pid, $status);

        $raw = (string) file_get_contents($report);
        @unlink($report);

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['outcome' => null, 'error' => 'child produced no report'];
    }

    protected function makeOrganizer(): User
    {
        $org = new User();
        $org->name = 'Slot Organizer';
        $org->email = 'slot-org-'.Str::random(8).'@ffarena.local';
        $org->password = bcrypt('secret123');
        $org->email_verified_at = now();
        $org->save();
        $org->role = 'organizer';
        $org->account_status = 'active';
        $org->save();

        return $org;
    }

    protected function makeTournament(User $organizer, int $slots, int $teamSize = 4): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = 'Slot Claim';
        $t->slug = 'slot-claim-'.Str::lower(Str::random(10));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 0;
        $t->prize_pool = 5000;
        $t->team_slots = $slots;
        $t->team_size = $teamSize;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = Tournament::STATUS_OPEN;
        $t->save();

        return $t;
    }

    protected function makePlayer(int $i): User
    {
        $user = new User();
        $user->name = 'Slot Captain '.$i;
        $user->email = 'slot-cap-'.$i.'-'.Str::random(8).'@ffarena.local';
        $user->password = bcrypt('secret123');
        $user->email_verified_at = now();
        $user->save();
        $user->role = 'player';
        $user->account_status = 'active';
        $user->save();

        return $user;
    }

    protected function registrationPayload(User $captain, int $i): array
    {
        return [
            'name' => 'Slot Squad '.$i,
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'SLOTUID'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'members' => [],
        ];
    }

    // -----------------------------------------------------------------------
    // 1. RegistrationService::register()
    // -----------------------------------------------------------------------

    public function test_a_waiting_registration_cannot_claim_a_slot_already_taken(): void
    {
        $this->requireFork();

        $org = $this->makeOrganizer();
        $tournament = $this->makeTournament($org, slots: 1);
        $winner = $this->makePlayer(1);
        $loser = $this->makePlayer(2);

        // The winner is mid-transaction: it holds the tournament row and has
        // already put its team in the only slot, but has not committed yet.
        // It runs on the holder connection so the forked child cannot disturb it.
        $holder = $this->holder();
        $holder->beginTransaction();
        $claimed = $holder->table('tournaments')
            ->where('id', $tournament->id)
            ->update(['updated_at' => now()]);
        $this->assertSame(1, $claimed, 'the fixture winner must take the row lock');

        $holder->table('teams')->insert([
            'tournament_id' => $tournament->id,
            'captain_id' => $winner->id,
            'name' => 'Winner',
            'captain_name' => $winner->name,
            'phone' => '01700000000',
            'game_uid' => 'WINNERUID',
            'status' => Team::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$pid, $report] = $this->forkChild(function () use ($tournament, $loser) {
            $tournamentId = $tournament->id;
            $result = app(RegistrationService::class)->register(
                Tournament::findOrFail($tournamentId),
                User::findOrFail($loser->id),
                $this->registrationPayload($loser, 2),
            );

            return $result['waitlisted'] ? 'waitlisted' : 'claimed';
        });

        // Let the child reach the lock, then release it. Without the parent-row
        // lock the child blocks on the UPDATE instead and its stale count lets
        // it claim the same slot.
        usleep(700000);
        $holder->commit();

        // The child's DB::purge() closed its inherited default session, which
        // the server reports to the parent as a dead connection. Reconnect
        // before asserting so the test reads the committed truth.
        DB::reconnect();

        $result = $this->awaitChild($pid, $report);

        $this->assertSame(
            'waitlisted',
            $result['outcome'],
            'a writer that waited on the tournament row claimed a slot that was already taken. '.
            'child said: '.json_encode($result)
        );

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED])->count(),
            'exactly one team may hold the single slot');
        $this->assertSame(1, Team::where('tournament_id', $tournament->id)
            ->where('status', Team::STATUS_WAITLISTED)->count(),
            'the loser must land on the waitlist, not vanish');
    }

    // -----------------------------------------------------------------------
    // 2. TournamentParticipationService::promoteNext()
    // -----------------------------------------------------------------------

    public function test_a_waiting_promotion_cannot_take_a_slot_already_taken(): void
    {
        $this->requireFork();

        $org = $this->makeOrganizer();
        $tournament = $this->makeTournament($org, slots: 1);
        $winner = $this->makePlayer(3);
        $waitlisted = $this->makePlayer(4);

        // A waitlisted team exists, so promotion is a real possibility.
        DB::table('teams')->insert([
            'tournament_id' => $tournament->id,
            'captain_id' => $waitlisted->id,
            'name' => 'Waitlisted',
            'captain_name' => $waitlisted->name,
            'phone' => '01700000000',
            'game_uid' => 'WAITUID',
            'status' => Team::STATUS_WAITLISTED,
            'waitlisted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The winner takes the only slot, uncommitted, holding the row lock.
        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->table('tournaments')->where('id', $tournament->id)->update(['updated_at' => now()]);
        $holder->table('teams')->insert([
            'tournament_id' => $tournament->id,
            'captain_id' => $winner->id,
            'name' => 'Winner',
            'captain_name' => $winner->name,
            'phone' => '01700000000',
            'game_uid' => 'WINNERUID',
            'status' => Team::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$pid, $report] = $this->forkChild(function () use ($tournament) {
            try {
                app(TournamentParticipationService::class)
                    ->promoteNext(Tournament::findOrFail($tournament->id));

                return 'promoted';
            } catch (\DomainException $e) {
                // The refusal is the correct outcome; the message says why.
                return 'refused: '.$e->getMessage();
            }
        });

        usleep(700000);
        $holder->commit();

        // The child's DB::purge() closed its inherited default session, which
        // the server reports to the parent as a dead connection. Reconnect
        // before asserting so the test reads the committed truth.
        DB::reconnect();

        $result = $this->awaitChild($pid, $report);

        $this->assertStringStartsWith(
            'refused:',
            (string) $result['outcome'],
            'a waited promotion took a slot that was already taken. child said: '.json_encode($result)
        );

        $this->assertSame(1, Team::where('tournament_id', $tournament->id)
            ->whereIn('status', [Team::STATUS_PENDING, Team::STATUS_CONFIRMED])->count(),
            'exactly one team may hold the single slot');
    }

    // -----------------------------------------------------------------------
    // 3. RosterService::addMember() — the same guard over team_members
    // -----------------------------------------------------------------------

    public function test_a_waiting_roster_add_cannot_exceed_the_team_size(): void
    {
        $this->requireFork();

        $org = $this->makeOrganizer();
        $tournament = $this->makeTournament($org, slots: 8, teamSize: 2); // captain + 1
        $captain = $this->makePlayer(5);

        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain->id;
        $team->name = 'Roster Squad';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'ROSTERUID';
        $team->status = Team::STATUS_PENDING;
        $team->save();

        // The winner's roster slot is taken, uncommitted, holding the team row.
        $holder = $this->holder();
        $holder->beginTransaction();
        $holder->table('teams')->where('id', $team->id)->update(['updated_at' => now()]);
        $holder->table('team_members')->insert([
            'team_id' => $team->id,
            'player_name' => 'First Member',
            'game_uid' => 'MEMBERUID1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$pid, $report] = $this->forkChild(function () use ($team, $tournament) {
            try {
                app(RosterService::class)->addMember(
                    Team::findOrFail($team->id),
                    Tournament::findOrFail($tournament->id),
                    ['player_name' => 'Second Member', 'game_uid' => 'MEMBERUID2'],
                );

                return 'added';
            } catch (\DomainException $e) {
                return 'refused: '.$e->getMessage();
            }
        });

        usleep(700000);
        $holder->commit();

        // The child's DB::purge() closed its inherited default session, which
        // the server reports to the parent as a dead connection. Reconnect
        // before asserting so the test reads the committed truth.
        DB::reconnect();

        $result = $this->awaitChild($pid, $report);

        $this->assertStringStartsWith(
            'refused:',
            (string) $result['outcome'],
            'a waited roster add exceeded the team size. child said: '.json_encode($result)
        );

        $this->assertSame(1, TeamMember::where('team_id', $team->id)->count(),
            'the team size is 2 (captain + 1 member): exactly one member row may exist');
    }
}
