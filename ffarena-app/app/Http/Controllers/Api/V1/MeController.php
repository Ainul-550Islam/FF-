<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Http\Resources\Api\V1\SessionResource;
use App\Services\ProfileService;
use App\Services\SessionManagementService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 15 — /me: own profile, privacy, and session security.
 */
class MeController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
        protected SessionManagementService $sessions,
    ) {
    }

    /**
     * GET /api/v1/me
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::data(new MeResource($request->user()));
    }

    /**
     * PUT/PATCH /api/v1/me/profile
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'bio' => 'nullable|string|max:500',
            'country' => 'nullable|string|max:2',
            'region' => 'nullable|string|max:100',
            'avatar' => 'nullable|string|max:255',
            'privacy' => ['sometimes', Rule::in((array) config('account.privacy', ['public', 'registered', 'private']))],
        ]);

        $user = $request->user();

        // Username changes go through the cooldown/availability rules.
        if (isset($data['privacy'])) {
            $this->profiles->updatePrivacy($user, $data['privacy']);
        }

        if (array_key_exists('name', $data) || array_key_exists('bio', $data)
            || array_key_exists('country', $data) || array_key_exists('region', $data)
            || array_key_exists('avatar', $data)) {
            $user = $this->profiles->update($user, $data);
        }

        return ApiResponse::data(new MeResource($user->fresh()));
    }

    /**
     * GET /api/v1/me/security — sign-in methods, no internal signals.
     */
    public function security(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::data([
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'has_password' => $this->profiles->hasPassword($user),
            'sign_in_methods' => app(\App\Services\IdentityService::class)->signInMethodCount($user),
            'account_status' => $user->account_status,
        ]);
    }

    /**
     * GET /api/v1/me/sessions
     */
    public function sessions(Request $request): JsonResponse
    {
        return ApiResponse::data(
            SessionResource::collection($this->sessions->sessionsFor($request->user()))
        );
    }

    /**
     * DELETE /api/v1/me/sessions/{session}
     */
    public function revokeSession(Request $request, string $session): JsonResponse
    {
        // Revoke a single named session that belongs to the caller. The id is
        // opaque and ownership is checked before deletion.
        $deleted = \Illuminate\Support\Facades\DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->id)
            ->delete();

        if ($deleted === 0) {
            return ApiResponse::error('not_found', 'Session not found.', [], 404);
        }

        return ApiResponse::data(['revoked' => $deleted]);
    }

    /**
     * POST /api/v1/me/sessions/revoke-others
     */
    public function revokeOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeOtherSessions($request->user());

        return ApiResponse::data(['revoked' => $count]);
    }

    /**
     * POST /api/v1/me/sessions/revoke-all
     */
    public function revokeAll(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeAllSessions($request->user());

        return ApiResponse::data(['revoked' => $count]);
    }
}
