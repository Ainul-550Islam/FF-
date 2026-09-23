<?php

namespace Tests\Feature\Api;

use App\Contracts\GoogleIdTokenVerifierInterface;
use App\Contracts\PhoneOtpProviderInterface;
use App\Models\Team;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared base for Phase 15 API feature tests.
 *
 * Provides token issuance, auth-guard resetting between requests (a test-only
 * necessity — the container memoizes the sanctum guard user across requests
 * within one test), and deterministic fakes for SMS and Google.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function user(array $attributes = []): User
    {
        $user = User::factory()->create();

        // `role` and `account_status` are intentionally not mass-assignable.
        $user->role = $attributes['role'] ?? 'player';
        $user->account_status = $attributes['account_status'] ?? 'active';

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['role', 'account_status'], true)) {
                continue;
            }

            $user->{$key} = $value;
        }

        $user->save();

        return $user;
    }

    protected function admin(array $attributes = []): User
    {
        return $this->user(array_merge(['role' => 'admin'], $attributes));
    }

    /**
     * Mint a bearer token for the given user/abilities.
     */
    protected function tokenFor(User $user, array $abilities = ['*']): string
    {
        return $user->createToken('test-token', $abilities)->plainTextToken;
    }

    /**
     * Reset the auth guard between requests with different tokens. Without
     * this the RequestGuard memoizes the first resolved user for the rest of
     * the test method.
     */
    protected function authForget(): void
    {
        $this->app['auth']->forgetGuards();
    }

    protected function asUser(User $user, array $abilities = ['*'])
    {
        $token = $this->tokenFor($user, $abilities);
        $this->authForget();

        return $this->withToken($token);
    }

    protected function makeTournament(User $organizer, string $status = 'open', array $o = []): Tournament
    {
        $t = new Tournament();
        $t->organizer_id = $organizer->id;
        $t->name = $o['name'] ?? 'API Tournament '.Str::random(5);
        $t->slug = $o['slug'] ?? ('api-'.Str::random(8));
        $t->game_mode = $o['game_mode'] ?? 'squad';
        $t->map = $o['map'] ?? 'Bermuda';
        $t->entry_fee = $o['entry_fee'] ?? 0;
        $t->prize_pool = $o['prize_pool'] ?? 5000;
        $t->team_slots = $o['team_slots'] ?? 8;
        $t->team_size = $o['team_size'] ?? 4;
        $t->rules = $o['rules'] ?? null;
        $t->starts_at = $o['starts_at'] ?? now()->addDay();
        $t->check_in_starts_at = $o['check_in_starts_at'] ?? null;
        $t->check_in_ends_at = $o['check_in_ends_at'] ?? null;
        $t->format = $o['format'] ?? 'single_elim';
        $t->dispute_window_hours = $o['dispute_window_hours'] ?? 24;
        $t->status = $status;
        $t->save();

        return $t;
    }

    protected function makeTeam(
        Tournament $tournament,
        ?User $captain = null,
        string $status = 'pending',
        ?string $uid = null,
        bool $checkedIn = false,
    ): Team {
        $team = new Team();
        $team->tournament_id = $tournament->id;
        $team->captain_id = $captain?->id;
        $team->name = 'Team '.Str::random(6);
        $team->captain_name = $captain?->name ?? 'Captain';
        $team->phone = '01700000000';
        $team->game_uid = $uid ?? 'UID'.strtoupper(Str::random(8));
        $team->status = $status;

        if ($status === Team::STATUS_WAITLISTED) {
            $team->waitlisted_at = now();
        }

        if ($checkedIn) {
            $team->checked_in_at = now();
        }

        $team->save();

        return $team;
    }

    protected function registrationPayload(string $name = 'API Squad', string $uid = 'UIDAPI0001'): array
    {
        return [
            'name' => $name,
            'captain_name' => 'Captain',
            'phone' => '01700000000',
            'game_uid' => $uid,
            'members' => [],
        ];
    }

    protected function bindFakeSms(): ApiFakeSmsProvider
    {
        $fake = new ApiFakeSmsProvider();
        $this->app->instance(PhoneOtpProviderInterface::class, $fake);

        return $fake;
    }

    protected function bindFakeGoogle(array $user = ['id' => 'g-123', 'email' => 'g@example.com', 'email_verified' => true, 'name' => 'G User']): ApiFakeGoogleVerifier
    {
        $fake = new ApiFakeGoogleVerifier($user);
        $this->app->instance(GoogleIdTokenVerifierInterface::class, $fake);

        return $fake;
    }
}
