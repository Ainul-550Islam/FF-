<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Models\User;
use App\Services\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — public player profiles. Privacy is honoured via
 * ProfileService::publicProfile: a private profile is never exposed merely
 * because the caller knows the id.
 */
class PlayerController extends Controller
{
    public function __construct(
        protected ProfileService $profiles,
    ) {}

    /**
     * GET /api/v1/players/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $profile = $this->profiles->publicProfile($user, $request->user());

        return ApiResponse::data(new ProfileResource($profile));
    }
}
