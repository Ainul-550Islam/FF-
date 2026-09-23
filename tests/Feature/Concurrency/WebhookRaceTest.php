<?php

namespace Tests\Feature\Concurrency;

use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 — webhook replay/concurrency race.
 *
 * N concurrent deliveries of the SAME signed provider webhook race through the
 * real webhook endpoint (HMAC-authenticated, outside the session). The
 * payment must settle exactly once — a single `payment.paid` event — and
 * concurrent duplicates must be audited, never double-processed.
 *
 * Execution model mirrors RegistrationRaceTest: true process-level
 * concurrency on PostgreSQL + pcntl; sequential replay (same invariant) on
 * SQLite.
 */
class WebhookRaceTest extends TestCase
{
    // See RegistrationRaceTest for why we run `migrate:fresh` explicitly
    // instead of using the DatabaseMigrations trait (its teardown rollback
    // fails on SQLite).
    protected Payment $payment;

    protected array $webhook;

    protected string $signature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');

        $org = new User();
        $org->name = 'Webhook Race Org';
        $org->email = 'webhookrace-org-'.Str::random(6).'@ffarena.local';
        $org->password = bcrypt('secret123');
        $org->email_verified_at = now();
        $org->save();
        $org->role = 'organizer';
        $org->account_status = 'active';
        $org->save();

        $captain = new User();
        $captain->name = 'Webhook Race Payer';
        $captain->email = 'webhookrace-cap-'.Str::random(6).'@ffarena.local';
        $captain->password = bcrypt('secret123');
        $captain->email_verified_at = now();
        $captain->save();
        $captain->role = 'player';
        $captain->account_status = 'active';
        $captain->save();

        $t = new Tournament();
        $t->organizer_id = $org->id;
        $t->name = 'Webhook Race';
        $t->slug = 'race-hook-'.Str::lower(Str::random(10));
        $t->game_mode = 'squad';
        $t->map = 'Bermuda';
        $t->entry_fee = 100;
        $t->prize_pool = 5000;
        $t->team_slots = 8;
        $t->team_size = 4;
        $t->starts_at = now()->addDay();
        $t->format = Tournament::FORMAT_SINGLE_ELIM;
        $t->status = Tournament::STATUS_OPEN;
        $t->save();

        $team = new Team();
        $team->tournament_id = $t->id;
        $team->captain_id = $captain->id;
        $team->name = 'Webhook Race Team';
        $team->captain_name = $captain->name;
        $team->phone = '01700000000';
        $team->game_uid = 'HOOKRACE1';
        $team->status = Team::STATUS_PENDING;
        $team->save();

        $this->payment = app(PaymentService::class)->createForTeam(
            $t, $team, $captain, 'bkash', 'HOOKTRX1', 'bkash', 'HOOKTRX1',
        );

        $this->webhook = [
            'payment_id' => $this->payment->id,
            'provider_reference' => 'HOOKTRX1',
            'amount_minor' => $this->payment->amount_minor,
            'currency' => 'BDT',
            'status' => Payment::STATUS_PAID,
        ];

        $rawBody = json_encode($this->webhook);
        $this->signature = hash_hmac('sha256', $rawBody, (string) config('services.payments.webhook_secret'));
    }

    public function test_concurrent_duplicate_webhooks_settle_exactly_once(): void
    {
        $total = 6;

        if ($this->canFork()) {
            $this->forkWebhooks($total);
        } else {
            for ($i = 0; $i < $total; $i++) {
                $this->deliverWebhook();
            }

            fwrite(STDERR, "[note] webhook race ran sequentially — true parallel writers need pcntl + PostgreSQL.\n");
        }

        $payment = Payment::find($this->payment->id);

        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame(1, PaymentEvent::where('payment_id', $payment->id)->where('event', PaymentEvent::EVENT_PAID)->count());
        $this->assertSame(Team::STATUS_CONFIRMED, $payment->team->fresh()->status);
    }

    protected function canFork(): bool
    {
        return function_exists('pcntl_fork') && config('database.default') === 'pgsql';
    }

    protected function forkWebhooks(int $total): void
    {
        $pids = [];

        for ($i = 0; $i < $total; $i++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();

                try {
                    $this->deliverWebhook();
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

        $this->assertSame(array_fill(0, $total, 0), $exitCodes, 'all webhook children must succeed');
    }

    protected function deliverWebhook(): int
    {
        $request = Request::create(
            '/webhooks/payments/bkash',
            'POST',
            [],
            [],
            [],
            ['HTTP_X-Signature' => $this->signature, 'CONTENT_TYPE' => 'application/json'],
            json_encode($this->webhook),
        );

        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response->getStatusCode();
    }

    protected function tearDown(): void
    {
        if (isset($this->payment)) {
            DB::table('payment_events')->where('payment_id', $this->payment->id)->delete();
            DB::table('payments')->where('id', $this->payment->id)->delete();
            DB::table('teams')->where('id', $this->payment->team_id)->delete();
            DB::table('tournaments')->where('id', $this->payment->tournament_id)->delete();
            DB::table('users')->where('email', 'like', 'webhookrace-%@ffarena.local')->delete();
        }

        parent::tearDown();
    }
}
