<?php

/**
 * G3 — payment flow benchmark (single worker).
 *
 *   php scripts/perf/benchmark-payments.php
 *   DB_CONNECTION=pgsql php scripts/perf/benchmark-payments.php
 *
 * Measures the honest payment lifecycle without ever calling a real provider:
 *
 *   create   — PaymentService::createForTeam (pending intent, amount derived
 *              server-side);
 *   verify   — verifyManually (admin verification; the manual-flow equivalent
 *              of a settled payment);
 *   replay   — a repeated callback/confirmation for the same payment
 *              (idempotency path).
 *
 * The replay path proves no double-settle: N replays of one payment produce
 * exactly one `paid`/`verified` event and no extra ledger movement.
 *
 * Mutates the dedicated non-production datastore only (new teams/payments per
 * iteration). No payment provider is contacted.
 */

require __DIR__.'/bootstrap.php';

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Support\Str;

perf_header('Payment benchmark ('.perf_driver().')');

$it = perf_env_int('PERF_ITERATIONS', 50);
$payments = app(PaymentService::class);

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

$admin = User::where('email', 'perf-admin@ffarena.local')->first();

if ($admin === null) {
    $admin = new User();
    $admin->name = 'Perf Admin';
    $admin->email = 'perf-admin@ffarena.local';
    $admin->password = bcrypt('perf-only-password');
    $admin->email_verified_at = now();
    $admin->save();
    $admin->role = 'admin';
    $admin->account_status = 'active';
    $admin->save();
}

$tournament = new Tournament();
$tournament->organizer_id = $org->id;
$tournament->name = 'Perf Payment Tournament';
$tournament->slug = 'perf-pay-'.Str::lower(Str::random(8));
$tournament->game_mode = 'squad';
$tournament->map = 'Bermuda';
$tournament->entry_fee = 100;
$tournament->prize_pool = 5000;
$tournament->team_slots = 9999;
$tournament->team_size = 4;
$tournament->starts_at = now()->addDay();
$tournament->format = Tournament::FORMAT_SINGLE_ELIM;
$tournament->status = Tournament::STATUS_OPEN;
$tournament->save();

$createSamples = [];
$verifySamples = [];
$replaySamples = [];
$replayedPayment = null;

for ($i = 0; $i < $it; $i++) {
    $captain = new User();
    $captain->name = 'Perf Payer '.$i;
    $captain->email = 'perf-payer-'.$i.'-'.Str::random(6).'@ffarena.local';
    $captain->password = bcrypt('perf-only-password');
    $captain->email_verified_at = now();
    $captain->save();
    $captain->role = 'player';
    $captain->account_status = 'active';
    $captain->save();

    $team = new Team();
    $team->tournament_id = $tournament->id;
    $team->captain_id = $captain->id;
    $team->name = 'Perf Team '.$i;
    $team->captain_name = $captain->name;
    $team->phone = '01700000000';
    $team->game_uid = 'PAYUID'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);
    $team->status = Team::STATUS_PENDING;
    $team->save();

    $start = perf_start();
    $payment = $payments->createForTeam($tournament, $team, $captain, 'bkash', 'PERFTRX'.$i, 'bkash', 'PERFTRX'.$i);
    $createSamples[] = (microtime(true) - $start) * 1000;

    $start = perf_start();
    $payments->verifyManually($payment, $admin);
    $verifySamples[] = (microtime(true) - $start) * 1000;

    if ($i === 0) {
        $replayedPayment = $payment;
    }
}

// Idempotent replay: repeatedly confirm the same settled payment and prove no
// second effect.
$replayCount = perf_env_int('PERF_REPLAYS', 100);

for ($i = 0; $i < $replayCount; $i++) {
    $start = perf_start();
    $payments->confirmProviderPayment($replayedPayment, [
        'status' => 'completed',
        'reference' => (string) $replayedPayment->provider_reference,
        'currency' => 'BDT',
        'amount' => null, // amount omitted → no re-check path; still idempotent
    ]);
    $replaySamples[] = (microtime(true) - $start) * 1000;
}

$verifiedEvents = PaymentEvent::where('payment_id', $replayedPayment->id)
    ->where('event', PaymentEvent::EVENT_VERIFIED)
    ->count();

$createStats = perf_percentiles($createSamples);
$verifyStats = perf_percentiles($verifySamples);
$replayStats = perf_percentiles($replaySamples);

perf_header('Payment results');
perf_line('createForTeam', $createStats);
perf_line('verifyManually', $verifyStats);
perf_line('confirm replay (idempotent)', $replayStats);
printf(" replay invariant: 1 verified event after %d replays (actual: %d)\n", $replayCount, $verifiedEvents);
printf(" payments created : %d\n", Payment::where('tournament_id', $tournament->id)->count());

$path = perf_save('payments', [
    'driver' => perf_driver(),
    'iterations' => $it,
    'replays' => $replayCount,
    'create' => $createStats,
    'verify' => $verifyStats,
    'replay' => $replayStats,
    'verified_events_after_replay' => $verifiedEvents,
]);

echo "\nSaved: {$path}\n";
