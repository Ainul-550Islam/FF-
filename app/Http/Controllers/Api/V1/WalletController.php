<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LedgerEntryResource;
use App\Http\Resources\Api\V1\PayoutResource;
use App\Http\Resources\Api\V1\WalletResource;
use App\Services\WalletService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 15 — wallet & payouts (read-only for ordinary users).
 *
 * There are deliberately NO credit/debit endpoints: balances and the ledger
 * change only through the Phase 08/09 services.
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletService $wallets,
    ) {}

    /**
     * GET /api/v1/me/wallet
     */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::data(new WalletResource($this->wallets->walletFor($request->user())));
    }

    /**
     * GET /api/v1/me/wallet/ledger
     */
    public function ledger(Request $request): JsonResponse
    {
        $wallet = $this->wallets->walletFor($request->user());

        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        $entries = $wallet->ledgerEntries()->orderByDesc('id')->paginate($perPage);

        return ApiResponse::data(
            LedgerEntryResource::collection($entries),
            [
                'pagination' => [
                    'current_page' => $entries->currentPage(),
                    'last_page' => $entries->lastPage(),
                    'per_page' => $entries->perPage(),
                    'total' => $entries->total(),
                ],
            ]
        );
    }

    /**
     * GET /api/v1/me/payouts — own payouts only.
     */
    public function payouts(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 30)));

        $payouts = $request->user()->payouts()
            ->with('tournament')
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::data(
            PayoutResource::collection($payouts),
            [
                'pagination' => [
                    'current_page' => $payouts->currentPage(),
                    'last_page' => $payouts->lastPage(),
                    'per_page' => $payouts->perPage(),
                    'total' => $payouts->total(),
                ],
            ]
        );
    }
}
