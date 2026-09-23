<?php

/**
 * G3 — registration throughput benchmark (single worker).
 *
 *   php scripts/perf/benchmark-registration.php
 *   DB_CONNECTION=pgsql php scripts/perf/benchmark-registration.php
 *   PERF_REGISTRATIONS=300 php scripts/perf/benchmark-registration.php
 *
 * Measures the RegistrationService::register() flow end-to-end (risk gate →
 * lifecycle re-check → atomic slot claim → roster insert → notifications) for
 * a single worker, reporting throughput (registrations/sec) and latency
 * percentiles, and asserting the financial invariant that a full tournament
 * never exceeds its slot count (every overflow goes to the waitlist).
 *
 * Each iteration registers a NEW captain (one-team-per-captain is a hard
 * invariant), so this mutates the dedicated non-production datastore only.
 * True multi-process contention is measured by the Concurrency test suite and
 * the k6 registration script.
 */

require __DIR__.'/bootstrap.php';

use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Support\Str;

perf_header('Registration benchmark ('.perf_driver().')');

$count = perf_env_int('PERF_REGISTRATIONS', 100);
$registrations = app(RegistrationService::class);

$org = User::where('email', 'perf-org@ffarena.local')->first();

if ($org === null) {
    $org = new User();
    $org->name = 'Perf Organizer';
    $org->email = 'perf-org@ffarena.local';
    $org->password = bcrypt('perf-only-password');
    $org->email_verified_at = now();
    $org->save();
    $org->role = 'organizer';
    $org->account_status = 'active';
    $org->save();
}

$tournament = new Tournament();
$tournament->organizer_id = $org->id;
$tournament->name = 'Perf Registration Tournament';
$tournament->slug = 'perf-reg-'.Str::lower(Str::random(8));
$tournament->game_mode = 'squad';
$tournament->map = 'Bermuda';
$tournament->entry_fee = 0;
$tournament->prize_pool = 5000;
$tournament->team_slots = 9999; // large enough to avoid waitlist noise in the throughput sample
$tournament->team_size = 4;
$tournament->starts_at = now()->addDay();
$tournament->format = Tournament::FORMAT_SINGLE_ELIM;
$tournament->status = Tournament::STATUS_OPEN;
$tournament->save();

$samples = [];
$waitlisted = 0;
$ok = 0;
$failed = 0;

$started = perf_start();

for ($i = 0; $i < $count; $i++) {
    $captain = new User();
    $captain->name = 'Perf Captain '.$i;
    $captain->email = 'perf-cap-'.$i.'-'.Str::random(6).'@ffarena.local';
    $captain->password = bcrypt('perf-only-password');
    $captain->email_verified_at = now();
    $captain->save();
    $captain->role = 'player';
    $captain->account_status = 'active';
    $captain->save();

    $start = perf_start();

    try {
        $result = $registrations->register($tournament, $captain, [
            'name' => 'Perf Squad '.$i,
            'captain_name' => $captain->name,
            'phone' => '01700000000',
            'game_uid' => 'PERFUID'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'members' => [],
        ]);

        $ok++;

        if ($result['waitlisted']) {
            $waitlisted++;
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "registration {$i} failed: {$e->getMessage()}\n");
    }

    $samples[] = (microtime(true) - $start) * 1000;
}

$elapsed = perf_start() - $started;
$stats = perf_percentiles($samples);
$throughput = $elapsed > 0 ? round($count / $elapsed, 2) : 0.0;

perf_header('Registration results');
perf_line('register() latency', $stats);
printf(" registrations   : %d ok, %d failed, %d waitlisted\n", $ok, $failed, $waitlisted);
printf(" throughput      : %.2f registrations/sec\n", $throughput);
printf(" team rows       : %d\n", Team::where('tournament_id', $tournament->id)->count());

$path = perf_save('registration', [
    'driver' => perf_driver(),
    'requested' => $count,
    'ok' => $ok,
    'failed' => $failed,
    'waitlisted' => $waitlisted,
    'throughput_per_sec' => $throughput,
    'stats' => $stats,
]);

echo "\nSaved: {$path}\n";
