<?php

namespace App\Services;

use App\Contracts\GoogleOAuthProviderInterface;
use App\Models\LoginEvent;
use App\Models\Notification;
use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Google Sign-In orchestration (Phase 14).
 *
 * Wraps the GoogleOAuthProviderInterface (Socialite in production, a fake in
 * tests) and turns a verified Google identity into an authenticated session:
 * linking, duplicate-account prevention, risk signals, login history,
 * notifications and audit are all applied here. No credentials or tokens are
 * ever persisted.
 */
class GoogleAuthService
{
    public function __construct(
        protected GoogleOAuthProviderInterface $provider,
        protected IdentityService $identities,
        protected LoginEventService $loginEvents,
        protected NotificationService $notifications,
        protected AuditLogService $audit,
        protected FraudRiskService $risk,
        protected DeviceFingerprintService $devices,
        protected IpIntelligenceService $ipIntel,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }

    /**
     * @throws DomainException when Google Sign-In is not configured.
     */
    public function redirect(): RedirectResponse
    {
        return $this->provider->redirect();
    }

    /**
     * Handle the OAuth callback: resolve/create the account, link identities,
     * record security events and return the authenticated user.
     */
    public function handleCallback(Request $request): User
    {
        $googleUser = $this->provider->user();

        $result = $this->identities->resolveGoogle($googleUser);
        $user = $result['user'];
        $created = $result['created'];
        $linked = $result['linked'];

        $this->loginEvents->record(
            $user,
            LoginEvent::EVENT_LOGIN_GOOGLE,
            LoginEvent::STATUS_SUCCESS,
            $request,
            ['linked' => $linked, 'created' => $created],
        );

        $this->devices->register($request, $user);
        $this->ipIntel->observe($request, $user);

        if ($this->loginEvents->isNewDevice($request, $user)) {
            $this->risk->recordSignal($user, \App\Models\RiskEvent::TYPE_RISK_FLAG, \App\Models\RiskEvent::SEVERITY_INFO, 'auth', [
                'context' => 'google_login_new_device',
            ]);

            $this->audit->recordQuietly($user, 'auth.suspicious_login', 'user', $user->id, [
                'target_user_id' => $user->id,
            ]);
        }

        if ($created) {
            $this->notifications->send(
                $user,
                Notification::TYPE_WELCOME,
                'Welcome to FF Arena',
                'Your account was created with Google Sign-In.',
                NotificationService::link('home'),
            );
        } elseif ($linked) {
            $this->notifications->send(
                $user,
                Notification::TYPE_GOOGLE_LINKED,
                'Google connected',
                'Google Sign-In was connected to your existing FF Arena account.',
                NotificationService::link('settings.connected-accounts'),
            );
        }

        $this->audit->recordQuietly(
            $user,
            $linked ? 'auth.google_linked' : ($created ? 'auth.register' : 'auth.login'),
            'user',
            $user->id,
            [
                'target_user_id' => $user->id,
                'metadata' => ['provider' => 'google', 'linked' => $linked, 'created' => $created],
            ],
        );

        return $user;
    }

    /**
     * Link a Google identity to the already-authenticated user (settings).
     */
    public function linkToCurrentUser(Request $request, User $user): void
    {
        $googleUser = $this->provider->user();

        $this->identities->linkGoogle(
            $user,
            (string) $googleUser['id'],
            $googleUser['email'],
            (bool) $googleUser['email_verified'],
            $googleUser['name'],
        );

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_LINKED, LoginEvent::STATUS_SUCCESS, $request, [
            'provider' => 'google',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_GOOGLE_LINKED,
            'Google connected',
            'Google Sign-In was connected to your account.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.google_linked', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);
    }

    /**
     * Unlink the Google identity from the user (settings).
     */
    public function unlinkFromUser(User $user): void
    {
        $this->identities->unlink($user, 'google');

        $this->loginEvents->record($user, LoginEvent::EVENT_ACCOUNT_UNLINKED, LoginEvent::STATUS_SUCCESS, null, [
            'provider' => 'google',
        ]);

        $this->notifications->send(
            $user,
            Notification::TYPE_GOOGLE_UNLINKED,
            'Google disconnected',
            'Google Sign-In was disconnected from your account.',
            NotificationService::link('settings.connected-accounts'),
        );

        $this->audit->recordQuietly($user, 'auth.google_unlinked', 'user', $user->id, [
            'target_user_id' => $user->id,
        ]);
    }
}
