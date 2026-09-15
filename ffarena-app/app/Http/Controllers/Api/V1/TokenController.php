<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApiClientResource;
use App\Http\Resources\Api\V1\TokenResource;
use App\Models\ApiClient;
use App\Services\ApiClientService;
use App\Services\ApiTokenService;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — personal access token + API client management.
 *
 * A token's plaintext is returned exactly once at creation and never stored,
 * logged, or listed again.
 */
class TokenController extends Controller
{
    public function __construct(
        protected ApiTokenService $tokens,
        protected ApiClientService $clients,
    ) {
    }

    /**
     * POST /api/v1/me/tokens
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'scopes' => 'sometimes|array',
            'scopes.*' => 'string',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $token = $this->tokens->issue(
                $request->user(),
                $data['name'],
                $data['scopes'] ?? (array) config('api.scopes', []),
                $data['expires_in_days'] ?? null,
                null,
                $request,
            );
        } catch (DomainException $e) {
            return ApiResponse::error('token_refused', $e->getMessage(), [], 422);
        }

        return ApiResponse::created([
            'token' => $token->plainTextToken,
            'token_info' => new TokenResource($token->accessToken),
        ]);
    }

    /**
     * GET /api/v1/me/tokens
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::data(TokenResource::collection($this->clients->tokensFor($request->user())));
    }

    /**
     * DELETE /api/v1/me/tokens/{tokenId}
     */
    public function destroy(Request $request, int $tokenId): JsonResponse
    {
        try {
            $this->tokens->revokeToken($request->user(), $tokenId);
        } catch (DomainException $e) {
            return ApiResponse::error('not_found', $e->getMessage(), [], 404);
        }

        return ApiResponse::noContent();
    }

    /**
     * GET /api/v1/me/clients
     */
    public function clients(Request $request): JsonResponse
    {
        return ApiResponse::data(ApiClientResource::collection($this->clients->forUser($request->user())));
    }

    /**
     * POST /api/v1/me/clients
     */
    public function storeClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:255',
            'scopes' => 'sometimes|array',
            'scopes.*' => 'string',
            'expires_in_days' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $token = $this->clients->create(
                $request->user(),
                $data['name'],
                $data['description'] ?? '',
                $data['scopes'] ?? (array) config('api.scopes', []),
                $data['expires_in_days'] ?? null,
            );
        } catch (DomainException $e) {
            return ApiResponse::error('client_refused', $e->getMessage(), [], 422);
        }

        $client = ApiClient::find($token->accessToken->api_client_id);

        return ApiResponse::created([
            'client' => new ApiClientResource($client),
            'token' => $token->plainTextToken,
            'token_info' => new TokenResource($token->accessToken),
        ]);
    }

    /**
     * DELETE /api/v1/me/clients/{client}
     */
    public function destroyClient(Request $request, ApiClient $client): JsonResponse
    {
        try {
            $this->tokens->revokeClient($request->user(), $client);
        } catch (DomainException $e) {
            return ApiResponse::error('client_refused', $e->getMessage(), [], 403);
        }

        return ApiResponse::noContent();
    }
}
