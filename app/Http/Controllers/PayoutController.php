<?php

namespace App\Http\Controllers;

use App\Models\Payout;
use App\Models\Tournament;
use App\Services\AuditLogService;
use App\Services\PayoutService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Admin payout queue (Phase 09).
 *
 * Every state transition here is money-adjacent, so each action runs the
 * service call and its audit row inside ONE database transaction:
 *
 *  - the audit row is written with the loud `record()` API (not
 *    `recordQuietly()`), so if the audit trail cannot be written the whole
 *    action rolls back — a payout can never complete without the audit row
 *    that explains who released it (GAP-10 A4),
 *  - a failure raised by PayoutService (including the maker-checker refusal)
 *    aborts the transaction before anything is committed.
 *
 * Step-up authentication (recent password confirmation) is applied to these
 * routes by `EnsureRecentPasswordConfirmation`, not by this controller.
 */
class PayoutController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $tournamentId = $request->input('tournament_id') ? (int) $request->input('tournament_id') : null;

        $statuses = ['pending', 'processing', 'completed', 'failed', 'cancelled', 'held'];
        $tournaments = Tournament::query()->orderBy('name')->get();

        $payouts = Payout::query()
            ->with(['recipient', 'tournament', 'team'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($tournamentId, fn ($q) => $q->where('tournament_id', $tournamentId))
            ->latest()
            ->paginate(25);

        return view('admin.payouts', compact('payouts', 'statuses', 'tournaments', 'status', 'tournamentId'));
    }

    public function approve(Payout $payout, PayoutService $service, AuditLogService $audit): RedirectResponse
    {
        return $this->transition(
            $payout,
            $audit,
            'payout.approved',
            'Payout approved.',
            [],
            fn (Payout $fresh) => $service->approve($fresh, auth()->user()),
        );
    }

    public function process(Payout $payout, PayoutService $service, AuditLogService $audit): RedirectResponse
    {
        return $this->transition(
            $payout,
            $audit,
            'payout.processed',
            'Payout processed.',
            [],
            fn (Payout $fresh) => $service->process($fresh, auth()->user()),
        );
    }

    public function processOverride(Request $request, Payout $payout, PayoutService $service, AuditLogService $audit): RedirectResponse
    {
        $request->validate(['reason' => 'required|string|min:3|max:500']);
        $reason = (string) $request->input('reason');

        return $this->transition(
            $payout,
            $audit,
            'payout.override',
            'Payout processed with override.',
            ['reason' => $reason],
            fn (Payout $fresh) => $service->processWithOverride($fresh, auth()->user(), $reason),
        );
    }

    public function complete(Request $request, Payout $payout, PayoutService $service, AuditLogService $audit): RedirectResponse
    {
        $reference = $request->input('reference') ? (string) $request->input('reference') : null;

        return $this->transition(
            $payout,
            $audit,
            'payout.completed',
            'Payout marked as completed.',
            ['reference' => $reference],
            fn (Payout $fresh) => $service->completeManually($fresh, auth()->user(), $reference),
        );
    }

    public function fail(Request $request, Payout $payout, PayoutService $service, AuditLogService $audit): RedirectResponse
    {
        $reason = (string) $request->input('reason', 'Marked failed by administrator');

        return $this->transition(
            $payout,
            $audit,
            'payout.failed',
            'Payout marked as failed.',
            ['reason' => $reason],
            fn (Payout $fresh) => $service->markFailed($fresh, auth()->user(), $reason),
        );
    }

    public function cancel(Payout $payout, PayoutService $service, AuditLogService $audit): RedirectResponse
    {
        return $this->transition(
            $payout,
            $audit,
            'payout.cancelled',
            'Payout cancelled.',
            [],
            fn (Payout $fresh) => $service->cancel($fresh, auth()->user()),
        );
    }

    /**
     * Run one payout transition and its audit row in a single transaction.
     *
     * @param  array<string, mixed>  $extraMetadata
     * @param  callable(Payout): Payout  $action
     */
    protected function transition(
        Payout $payout,
        AuditLogService $audit,
        string $actionName,
        string $successMessage,
        array $extraMetadata,
        callable $action,
    ): RedirectResponse {
        $actor = auth()->user();

        try {
            DB::transaction(function () use ($payout, $audit, $actor, $action, $actionName, $extraMetadata) {
                $fresh = $action($payout);

                $audit->record($actor, $actionName, 'payout', $fresh->id, [
                    'tournament_id' => $fresh->tournament_id,
                    'target_user_id' => $fresh->recipient_user_id,
                    'after' => ['status' => $fresh->status],
                    'metadata' => array_merge([
                        'amount_minor' => $fresh->amountMinor(),
                        'currency' => (string) $fresh->currency,
                        'provider' => (string) $fresh->provider,
                        'rank' => (int) $fresh->rank,
                        'approved_by' => $fresh->approved_by,
                        'processed_by' => $fresh->processed_by,
                    ], $extraMetadata),
                ]);
            });

            return back()->with('success', $successMessage);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            // A non-domain failure (including an audit write failure) has
            // already rolled the transaction back. Surface it instead of
            // pretending the payout moved.
            report($e);

            return back()->with('error', 'The payout action could not be completed and was rolled back.');
        }
    }

}
