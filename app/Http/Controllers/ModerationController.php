<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\Dispute;
use App\Models\RiskProfile;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModerationController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $tournamentId = $request->input('tournament_id') ? (int) $request->input('tournament_id') : null;
        $tournaments = Tournament::query()->orderBy('name')->get();

        $disputes = Dispute::query()
            ->with(['match.tournament', 'team', 'assignee'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($tournamentId, fn ($q) => $q->whereHas('match', fn ($mq) => $mq->where('tournament_id', $tournamentId)))
            ->latest()
            ->paginate(25);

        return view('moderation.index', compact('status', 'tournamentId', 'tournaments', 'disputes'));
    }

    public function security(): View
    {
        $flaggedUsers = RiskProfile::query()
            ->with('user')
            ->where(function ($q) {
                $q->where('manual_review_required', true)
                    ->orWhere('risk_score', '>=', 50);
            })
            ->latest()
            ->get();

        $openIncidents = AntiCheatIncident::query()
            ->with(['tournament', 'team'])
            ->whereIn('status', [AntiCheatIncident::STATUS_FLAGGED, 'under_review'])
            ->latest()
            ->get();

        return view('moderation.security', compact('flaggedUsers', 'openIncidents'));
    }
}
