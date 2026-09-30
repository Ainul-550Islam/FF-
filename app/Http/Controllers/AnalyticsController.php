<?php

namespace App\Http\Controllers;

use App\Models\Tournament;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function index(AnalyticsService $analytics): View
    {
        $overview = $analytics->platformOverview();

        return view('admin.analytics.index', compact('overview'));
    }

    public function financial(AnalyticsService $analytics): View
    {
        $metrics = $analytics->financialMetrics();

        return view('admin.analytics.financial', compact('metrics'));
    }

    public function security(AnalyticsService $analytics): View
    {
        $metrics = $analytics->securityMetrics();

        return view('admin.analytics.security', compact('metrics'));
    }

    public function disputes(AnalyticsService $analytics): View
    {
        $metrics = $analytics->disputeMetrics();

        return view('admin.analytics.disputes', compact('metrics'));
    }

    public function support(AnalyticsService $analytics): View
    {
        $metrics = $analytics->supportMetrics();

        return view('admin.analytics.support', compact('metrics'));
    }

    public function tournaments(): View
    {
        $tournaments = Tournament::query()->with('organizer')->latest()->paginate(25);

        return view('admin.analytics.tournaments', compact('tournaments'));
    }

    public function tournament(Tournament $tournament, AnalyticsService $analytics): View
    {
        $user = auth()->user();

        if (! $user || (! $user->isStaff() && $tournament->organizer_id !== $user->id)) {
            abort(403);
        }

        $metrics = $analytics->tournamentMetrics($tournament);

        return view('admin.analytics.tournament', compact('tournament', 'metrics'));
    }

    public function exportTournaments(): StreamedResponse
    {
        $tournaments = Tournament::query()->with(['organizer'])->orderBy('id')->get();

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tournaments-analytics-export.csv"',
        ];

        return response()->stream(function () use ($tournaments) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Name', 'Slug', 'Organizer', 'Status', 'Game Mode', 'Entry Fee', 'Prize Pool', 'Created At']);

            foreach ($tournaments as $t) {
                fputcsv($handle, [
                    $t->id,
                    $t->name,
                    $t->slug,
                    $t->organizer?->name ?? 'Unknown',
                    $t->status,
                    $t->game_mode,
                    $t->entry_fee,
                    $t->prize_pool,
                    $t->created_at?->toISOString(),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
