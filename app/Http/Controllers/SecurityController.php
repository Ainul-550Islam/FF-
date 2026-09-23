<?php

namespace App\Http\Controllers;

use App\Models\AntiCheatIncident;
use App\Models\Device;
use App\Models\GameMatch;
use App\Models\IdentityVerification;
use App\Models\MatchAnomaly;
use App\Models\Restriction;
use App\Models\RiskEvent;
use App\Models\RiskProfile;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\AuditLogService;
use App\Services\IdentityVerificationService;
use App\Services\IpIntelligenceService;
use App\Services\RestrictionService;
use DomainException;
use Illuminate\Http\Request;

/**
 * Admin/moderation security UI + actions (Phase 10).
 *
 * Every method authorizes per action via the Risk/AntiCheat/Identity
 * policies. Players can never inspect another user's risk, device or IP
 * data, and can never modify restrictions or verification state.
 */
class SecurityController extends Controller
{
    public function __construct(
        protected RestrictionService $restrictions,
        protected IdentityVerificationService $identity,
        protected AntiCheatService $antiCheat,
        protected IpIntelligenceService $ipIntel,
        protected AuditLogService $audit,
    ) {}

    // ------------------------------------------------------------------
    // Risk dashboard (admin)
    // ------------------------------------------------------------------

    public function dashboard()
    {
        $this->authorize('viewAny', RiskProfile::class);

        $stats = [
            'critical' => RiskProfile::where('risk_level', RiskProfile::LEVEL_CRITICAL)->count(),
            'high' => RiskProfile::where('risk_level', RiskProfile::LEVEL_HIGH)->count(),
            'medium' => RiskProfile::where('risk_level', RiskProfile::LEVEL_MEDIUM)->count(),
            'review_required' => RiskProfile::where('manual_review_required', true)->count(),
            'active_restrictions' => Restriction::where('status', Restriction::STATUS_ACTIVE)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            'open_incidents' => AntiCheatIncident::whereIn('status', [
                AntiCheatIncident::STATUS_FLAGGED,
                AntiCheatIncident::STATUS_UNDER_REVIEW,
            ])->count(),
            'anomalies' => MatchAnomaly::count(),
            'events' => RiskEvent::count(),
        ];

        $recentEvents = RiskEvent::with(['user', 'tournament'])
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $reviewQueue = RiskProfile::with('user')
            ->where('manual_review_required', true)
            ->orderByDesc('risk_score')
            ->limit(15)
            ->get();

        return view('admin.security.dashboard', compact('stats', 'recentEvents', 'reviewQueue'));
    }

    /**
     * Suspicious users list (admin).
     */
    public function users(Request $request)
    {
        $this->authorize('viewAny', RiskProfile::class);

        $profiles = RiskProfile::query()->with('user')->orderByDesc('risk_score');

        $level = $request->query('level');
        if ($level !== null && in_array($level, RiskProfile::LEVELS, true)) {
            $profiles->where('risk_level', $level);
        }

        if ($request->query('review') === '1') {
            $profiles->where('manual_review_required', true);
        }

        $profiles = $profiles->paginate(25)->withQueryString();

        return view('admin.security.users', compact('profiles', 'level'));
    }

    /**
     * Investigation detail for one user (admin).
     */
    public function user(User $user)
    {
        $this->authorize('viewUser', [RiskProfile::class, $user]);

        $profile = $user->riskProfile()->first();
        $events = RiskEvent::where('user_id', $user->id)->orderByDesc('id')->limit(100)->get();
        $devices = Device::query()
            ->whereHas('links', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('links')
            ->get();
        $ipIntel = $user->ipLinks()->with('ipIntel')->get();
        $links = $user->linkedAccounts()->orderByDesc('id')->get();
        $restrictions = $user->restrictions()->with('actor', 'liftedBy')->orderByDesc('id')->get();
        $identity = $this->identity->effectiveStatus($user);
        $incidents = AntiCheatIncident::where('accused_user_id', $user->id)
            ->orWhere('reporter_user_id', $user->id)
            ->orderByDesc('id')
            ->get();

        return view('admin.security.user', [
            'subject' => $user,
            'profile' => $profile,
            'events' => $events,
            'devices' => $devices,
            'ipIntel' => $ipIntel,
            'links' => $links,
            'restrictions' => $restrictions,
            'identity' => $identity,
            'incidents' => $incidents,
        ]);
    }

    /**
     * Risk events list (admin).
     */
    public function events(Request $request)
    {
        $this->authorize('viewEvents', RiskProfile::class);

        $events = RiskEvent::query()->with(['user', 'tournament'])->orderByDesc('id');

        $severity = $request->query('severity');
        if ($severity !== null && in_array($severity, RiskEvent::SEVERITIES, true)) {
            $events->where('severity', $severity);
        }

        $events = $events->paginate(30)->withQueryString();

        return view('admin.security.events', compact('events', 'severity'));
    }

    // ------------------------------------------------------------------
    // Anti-cheat incidents (staff)
    // ------------------------------------------------------------------

    public function incidents(Request $request)
    {
        $this->authorize('viewAny', AntiCheatIncident::class);

        $user = $request->user();
        $incidents = AntiCheatIncident::query()
            ->with(['tournament', 'match', 'team', 'accusedUser', 'reviewer'])
            ->orderByDesc('created_at');

        if ($user->isOrganizer() && ! $user->isAdmin() && ! $user->isModerator()) {
            $incidents->whereHas('tournament', fn ($q) => $q->where('organizer_id', $user->id));
        }

        $status = $request->query('status');
        if ($status !== null && in_array($status, AntiCheatIncident::STATUSES, true)) {
            $incidents->where('status', $status);
        }

        $incidents = $incidents->paginate(25)->withQueryString();

        $tournaments = Tournament::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.security.incidents', compact('incidents', 'tournaments', 'status'));
    }

    /**
     * Open a new incident (staff).
     */
    public function openIncident(Request $request)
    {
        $this->authorize('create', AntiCheatIncident::class);

        $data = $request->validate([
            'tournament_id' => 'required|integer|exists:tournaments,id',
            'match_id' => 'nullable|integer|exists:matches,id',
            'team_id' => 'nullable|integer|exists:teams,id',
            'accused_user_id' => 'nullable|integer|exists:users,id',
            'category' => 'required|in:'.implode(',', AntiCheatService::CATEGORIES),
            'severity' => 'required|in:low,medium,high,critical',
            'description' => 'nullable|string|max:5000',
            'evidence_reference' => 'nullable|string|max:120',
        ]);

        $tournament = Tournament::findOrFail($data['tournament_id']);

        try {
            $incident = $this->antiCheat->openIncident(
                $tournament,
                ! empty($data['match_id']) ? GameMatch::find($data['match_id']) : null,
                ! empty($data['team_id']) ? Team::find($data['team_id']) : null,
                ! empty($data['accused_user_id']) ? User::find($data['accused_user_id']) : null,
                $request->user(),
                AntiCheatIncident::SOURCE_STAFF,
                $data['category'],
                $data['severity'],
                $data['description'] ?? null,
                $data['evidence_reference'] ?? null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'anti_cheat.opened', 'anti_cheat_incident', $incident->id, [
            'tournament_id' => $tournament->id,
            'metadata' => ['category' => $data['category'], 'severity' => $data['severity']],
        ]);

        return redirect()->route('security.incidents.index')->with('success', 'Anti-cheat incident opened.');
    }

    public function reviewIncident(AntiCheatIncident $incident)
    {
        $this->authorize('review', $incident);

        try {
            $this->antiCheat->review($incident, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Incident moved under review.');
    }

    public function resolveIncident(Request $request, AntiCheatIncident $incident)
    {
        $this->authorize('resolve', $incident);

        $data = $request->validate([
            'resolution' => 'required|in:'.implode(',', AntiCheatIncident::RESOLUTIONS),
            'resolution_text' => 'required|string|max:5000',
        ]);

        try {
            $this->antiCheat->resolve($incident, $request->user(), $data['resolution'], $data['resolution_text']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'anti_cheat.resolved', 'anti_cheat_incident', $incident->id, [
            'target_user_id' => $incident->accused_user_id,
            'tournament_id' => $incident->tournament_id,
            'metadata' => ['resolution' => $data['resolution']],
        ]);

        return back()->with('success', 'Incident resolved.');
    }

    // ------------------------------------------------------------------
    // Restrictions + identity (admin)
    // ------------------------------------------------------------------

    public function restrict(Request $request, User $user)
    {
        $this->authorize('manageRestrictions', RiskProfile::class);

        $data = $request->validate([
            'type' => 'required|in:'.implode(',', Restriction::TYPES),
            'reason' => 'required|string|max:255',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            $this->restrictions->restrict(
                $user,
                $data['type'],
                $data['reason'],
                'manual',
                $request->user(),
                ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'restriction.applied', 'user', $user->id, [
            'target_user_id' => $user->id,
            'metadata' => ['type' => $data['type']],
        ]);

        return back()->with('success', 'Restriction applied.');
    }

    public function liftRestriction(Restriction $restriction)
    {
        $this->authorize('liftRestriction', [RiskProfile::class, $restriction]);

        try {
            $this->restrictions->lift($restriction, auth()->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly(auth()->user(), 'restriction.lifted', 'restriction', $restriction->id, [
            'target_user_id' => $restriction->user_id,
            'metadata' => ['type' => $restriction->type],
        ]);

        return back()->with('success', 'Restriction lifted.');
    }

    public function verifyIdentity(Request $request, User $user)
    {
        $this->authorize('verify', IdentityVerification::class);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
            'expires_in_days' => 'nullable|integer|min:1|max:3650',
        ]);

        try {
            $this->identity->verifyManually(
                $user,
                $request->user(),
                $data['notes'] ?? null,
                ! empty($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'identity.verified', 'identity_verification', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', 'Identity verified (manual review).');
    }

    public function rejectIdentity(Request $request, User $user)
    {
        $this->authorize('reject', IdentityVerification::class);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->identity->reject($user, $request->user(), $data['notes'] ?? null);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->audit->recordQuietly($request->user(), 'identity.rejected', 'identity_verification', $user->id, [
            'target_user_id' => $user->id,
        ]);

        return back()->with('success', 'Identity verification rejected.');
    }

    /**
     * Self-service verification request (authenticated user).
     */
    public function requestVerification()
    {
        $this->authorize('request', IdentityVerification::class);

        $this->identity->request(auth()->user());

        return back()->with('success', 'Verification requested. A staff member will review it.');
    }
}
