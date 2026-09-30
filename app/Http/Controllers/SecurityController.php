<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\IdentityVerification;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AccountLinkService;
use App\Services\AntiCheatService;
use App\Services\IdentityVerificationService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function dashboard(): View
    {
        $stats = [
            'critical' => RiskProfile::where('risk_level', 'critical')->count(),
            'high' => RiskProfile::where('risk_level', 'high')->count(),
            'medium' => RiskProfile::where('risk_level', 'medium')->count(),
            'review_required' => RiskProfile::where('manual_review_required', true)->count(),
            'active_restrictions' => Restriction::where('status', Restriction::STATUS_ACTIVE)->count(),
            'open_incidents' => AntiCheatIncident::whereIn('status', [AntiCheatIncident::STATUS_FLAGGED, 'under_review'])->count(),
            'anomalies' => 0,
            'events' => RiskEvent::count(),
        ];

        $reviewQueue = RiskProfile::with('user')->where('manual_review_required', true)->latest()->take(10)->get();
        $recentEvents = RiskEvent::with('user')->latest()->take(10)->get();

        return view('admin.security.dashboard', compact('stats', 'reviewQueue', 'recentEvents'));
    }

    public function events(Request $request): View
    {
        $severity = $request->input('severity');

        $events = RiskEvent::query()
            ->with('user')
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->latest()
            ->paginate(25);

        return view('admin.security.events', compact('events', 'severity'));
    }

    public function incidents(): View
    {
        $incidents = AntiCheatIncident::query()
            ->with(['tournament', 'team', 'accusedUser', 'reporter'])
            ->latest()
            ->paginate(25);

        $tournaments = Tournament::query()->orderBy('name')->get();

        return view('admin.security.incidents', compact('incidents', 'tournaments'));
    }

    public function users(Request $request): View
    {
        $level = $request->input('level');
        $review = $request->input('review') === '1';

        $profiles = RiskProfile::query()
            ->with('user')
            ->when($level, fn ($q) => $q->where('risk_level', $level))
            ->when($review, fn ($q) => $q->where('manual_review_required', true))
            ->latest()
            ->paginate(25);

        return view('admin.security.users', compact('profiles', 'level', 'review'));
    }

    public function user(User $user, AccountLinkService $linkService): View
    {
        $subject = $user;
        $profile = $subject->riskProfile;
        $identity = $subject->identityVerification()->first() ?? new IdentityVerification(['status' => 'unverified']);
        $devices = $subject->devices ?? collect();
        $ipIntel = $subject->ipLinks ?? collect();
        $links = $linkService->linksFor($subject);
        $restrictions = $subject->restrictions()->latest()->get();

        return view('admin.security.user', compact(
            'subject',
            'profile',
            'identity',
            'devices',
            'ipIntel',
            'links',
            'restrictions',
        ));
    }

    public function requestVerification(Request $request, IdentityVerificationService $service): RedirectResponse
    {
        $service->request($request->user());

        return back()->with('success', 'Identity verification requested.');
    }

    public function verifyIdentity(Request $request, User $user, IdentityVerificationService $service): RedirectResponse
    {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isAdmin()) {
            abort(403);
        }

        $notes = $request->input('notes') ? (string) $request->input('notes') : null;

        try {
            $service->verifyManually($user, $userActor, $notes);

            return back()->with('success', 'Identity verified.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function rejectIdentity(Request $request, User $user, IdentityVerificationService $service): RedirectResponse
    {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isAdmin()) {
            abort(403);
        }

        $notes = $request->input('notes') ? (string) $request->input('notes') : null;

        try {
            $service->reject($user, $userActor, $notes);

            return back()->with('success', 'Identity rejected.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function restrict(Request $request, User $user, RestrictionService $service): RedirectResponse
    {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'type' => 'required|string',
            'reason' => 'required|string|max:500',
            'expires_in_days' => 'nullable|integer|min:1',
        ]);

        $expiresAt = isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null;

        try {
            $service->restrict($user, $data['type'], $data['reason'], $userActor, $expiresAt);

            return back()->with('success', 'Restriction applied.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function liftRestriction(Restriction $restriction, RestrictionService $service): RedirectResponse
    {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isAdmin()) {
            abort(403);
        }

        try {
            $service->lift($restriction, $userActor);

            return back()->with('success', 'Restriction lifted.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function openIncident(Request $request, AntiCheatService $service): RedirectResponse
    {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isStaff()) {
            abort(403);
        }

        $data = $request->validate([
            'tournament_id' => 'required|integer|exists:tournaments,id',
            'accused_user_id' => 'nullable|integer|exists:users,id',
            'category' => 'required|string',
            'severity' => 'required|string',
            'description' => 'nullable|string',
        ]);

        try {
            $service->openIncident(
                $userActor,
                (int) $data['tournament_id'],
                (int) ($data['accused_user_id'] ?? 0),
                $data['category'],
                $data['severity'],
                $data['description'] ?? null,
            );

            return redirect()->route('security.incidents.index')->with('success', 'Incident opened.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reviewIncident(AntiCheatIncident $incident, AntiCheatService $service): RedirectResponse
    {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isStaff()) {
            abort(403);
        }

        try {
            $service->review($incident, $userActor);

            return back()->with('success', 'Incident marked under review.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function resolveIncident(
        Request $request,
        AntiCheatIncident $incident,
        AntiCheatService $service,
    ): RedirectResponse {
        $userActor = auth()->user();

        if (! $userActor || ! $userActor->isStaff()) {
            abort(403);
        }

        $data = $request->validate([
            'resolution' => 'required|string',
            'resolution_text' => 'nullable|string',
        ]);

        try {
            $service->resolve(
                $incident,
                $userActor,
                $data['resolution'],
                (string) ($data['resolution_text'] ?? ''),
            );

            return back()->with('success', 'Incident resolved.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
