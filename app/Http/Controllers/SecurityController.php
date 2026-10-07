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
        // The queue is readable by platform staff (admin/moderator) and by
        // organizers, but never by players. `self::incidents` is registered
        // twice in routes/web.php (the later `security.incidents.index`
        // definition currently wins), so the gate lives here rather than only
        // in one of the two route groups.
        $actor = auth()->user();

        if (! $actor || ! ($actor->isStaff() || $actor->isOrganizer())) {
            abort(403);
        }

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
        // IdentityVerification is fully guarded ($fillable = []) — build the
        // placeholder through forceFill() exactly like AdminAccountController
        // does; the previous constructor array raised MassAssignmentException
        // and the security user page returned a 500.
        $identity = $subject->identityVerification()->first()
            ?? (new IdentityVerification())->forceFill(['status' => 'unverified']);
        $devices = $subject->devices ?? collect();
        $ipIntel = $subject->ipLinks ?? collect();
        $links = $linkService->linksFor($subject);
        $restrictions = $subject->restrictions()->latest()->get();
        // The investigation page renders the account's risk-event history; the
        // variable was never passed and the page returned a 500 for admins.
        $events = $subject->riskEvents()->latest()->limit(50)->get();
        // …plus the anti-cheat incidents this account is involved in (as the
        // accused or as the reporter — the table labels the role per row).
        $incidents = AntiCheatIncident::query()
            ->with('tournament')
            ->where('accused_user_id', $subject->id)
            ->orWhere('reporter_user_id', $subject->id)
            ->latest()
            ->limit(50)
            ->get();

        return view('admin.security.user', compact(
            'subject',
            'profile',
            'identity',
            'devices',
            'ipIntel',
            'links',
            'restrictions',
            'events',
            'incidents',
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
            // `source` is a short varchar(20) column, so it must be the
            // literal tag and the admin must be passed as the *actor*
            // (previously the actor landed in $source, which cast the whole
            // User model to JSON and overflowed the column on PostgreSQL).
            $service->restrict($user, $data['type'], $data['reason'], 'admin_manual', $userActor, $expiresAt);

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
            // The service signature is
            // openIncident(Tournament $tournament, ?GameMatch $match, ?Team $team,
            //              ?User $accusedUser, User $reporter, string $source,
            //              string $category, string $severity, ?string $description).
            // The calling code used to pass the acting user as the tournament,
            // which raised a TypeError (HTTP 500) for every staff-opened
            // incident; resolve the validated ids into models instead.
            $tournament = Tournament::findOrFail((int) $data['tournament_id']);
            $accused = isset($data['accused_user_id'])
                ? User::find((int) $data['accused_user_id'])
                : null;

            $service->openIncident(
                $tournament,
                null,
                null,
                $accused,
                $userActor,
                AntiCheatIncident::SOURCE_STAFF,
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
