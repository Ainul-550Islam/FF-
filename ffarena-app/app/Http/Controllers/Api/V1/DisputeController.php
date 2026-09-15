<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\DisputeResource;
use App\Models\Dispute;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — disputes: read-only for participants; private evidence is never
 * serialized. Staff additionally see the disputes they are party to via the
 * DisputePolicy.
 */
class DisputeController extends Controller
{
    /**
     * GET /api/v1/me/disputes — disputes the caller opened or (as staff) is
     * assigned to.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $disputes = Dispute::query()
            ->with(['match', 'team'])
            ->where(function ($q) use ($user) {
                $q->where('opened_by', $user->id);

                if ($user->isStaff()) {
                    $q->orWhere('assigned_to', $user->id);
                }
            })
            ->orderByDesc('id')
            ->paginate(min(50, max(1, (int) $request->query('per_page', 15))));

        return ApiResponse::data(
            DisputeResource::collection($disputes),
            [
                'pagination' => [
                    'current_page' => $disputes->currentPage(),
                    'last_page' => $disputes->lastPage(),
                    'per_page' => $disputes->perPage(),
                    'total' => $disputes->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/disputes/{dispute} — authorized read (participant or staff).
     */
    public function show(Request $request, Dispute $dispute): JsonResponse
    {
        $this->authorize('view', $dispute);

        return ApiResponse::data(new DisputeResource($dispute));
    }
}
