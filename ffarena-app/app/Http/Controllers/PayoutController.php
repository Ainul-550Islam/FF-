<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\PayoutService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin payout management (Phase 09).
 *
 * List payouts and drive the payout state machine. All routes sit behind the
 * `admin` middleware and call the PayoutPolicy.
 */
class PayoutController extends Controller
{
    public function __construct(
        protected PayoutService $payouts,
        protected AuditLogService $audit,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Payout::class);

        $payouts = Payout::query()
            ->with(['tournament', 'recipient', 'team', 'processedBy'])
            ->orderByDesc('created_at');

        $status = $request->query('status');

        if ($status !== null && $status !== '') {
            $payouts->where('status', $status);
        }

        $tournamentId = (int) $request->query('tournament_id');

        if ($tournamentId > 0) {
            $payouts->where('tournament_id', $tournamentId);
        }

        $payouts = $payouts->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);

        $statuses = [
            Payout::STATUS_PENDING,
            Payout::STATUS_APPROVED,
            Payout::STATUS_PROCESSING,
            Payout::STATUS_COMPLETED,
            Payout::STATUS_FAILED,
            Payout::STATUS_CANCELLED,
        ];

        return view('admin.payouts', compact('payouts', 'tournaments', 'statuses', 'status', 'tournamentId'));
    }

    public function approve(Payout $payout)
    {
        $this->authorize('approve', $payout);

        try {
            $this->payouts->approve($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payout.approved', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
        ]);

        return back()->with('success', 'Payout approved.');
    }

    public function process(Payout $payout)
    {
        $this->authorize('process', $payout);

        try {
            $this->payouts->process($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payout.processed', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
        ]);

        return back()->with('success', 'Payout processed.');
    }

    public function processOverride(Request $request, Payout $payout)
    {
        $this->authorize('process', $payout);

        $reason = (string) $request->input('reason', '');

        try {
            $this->payouts->processWithOverride($payout, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payout.override', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
            'metadata' => ['reason' => $reason],
        ]);

        return back()->with('success', 'Payout processed with fraud-review override.');
    }

    public function complete(Request $request, Payout $payout)
    {
        $this->authorize('complete', $payout);

        $reference = (string) $request->input('reference', '');

        try {
            $this->payouts->completeManually($payout, auth()->user(), $reference);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payout.completed', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
            'metadata' => ['reference' => $reference],
        ]);

        return back()->with('success', 'Payout marked completed.');
    }

    public function fail(Request $request, Payout $payout)
    {
        $this->authorize('fail', $payout);

        $reason = (string) $request->input('reason', '');

        try {
            $this->payouts->markFailed($payout, auth()->user(), $reason);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payout.failed', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
            'metadata' => ['reason' => $reason],
        ]);

        return back()->with('success', 'Payout marked failed.');
    }

    public function cancel(Payout $payout)
    {
        $this->authorize('cancel', $payout);

        try {
            $this->payouts->cancel($payout, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'payout.cancelled', 'payout', $payout->id, [
            'tournament_id' => $payout->tournament_id,
        ]);

        return back()->with('success', 'Payout cancelled.');
    }
}
