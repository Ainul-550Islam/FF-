<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\ScratchCard;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\ReferralService;
use Illuminate\Http\Request;

class ReferralApiController extends Controller
{
    protected ReferralService $referralService;

    public function __construct(ReferralService $referralService)
    {
        $this->referralService = $referralService;
    }

    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->referralService->getReferralStats($userId);
        $referrals = $this->referralService->getReferralList($userId);
        $scratchCards = ScratchCard::where('user_id', $userId)->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $stats,
                'referrals' => $referrals,
                'scratch_cards' => $scratchCards,
            ],
        ]);
    }

    public function generateCode(Request $request)
    {
        $userId = $request->user()->id;
        try {
            $code = $this->referralService->generateReferralCode($userId);

            return response()->json(['success' => true, 'code' => $code, 'message' => "Referral code BGI20 style: {$code} - Share for ₹25 bonus"]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function applyCode(Request $request)
    {
        $request->validate(['code' => 'required|string|max:20']);
        $userId = $request->user()->id;
        try {
            $referral = $this->referralService->applyReferralCode($userId, $request->code);

            return response()->json(['success' => true, 'data' => $referral, 'message' => 'Applied! Both got ₹25 bonus + scratch card']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function scratchCards(Request $request)
    {
        $userId = $request->user()->id;
        $cards = ScratchCard::where('user_id', $userId)->orderByDesc('created_at')->get();

        return response()->json(['success' => true, 'data' => $cards]);
    }

    public function scratch(Request $request, int $cardId)
    {
        $userId = $request->user()->id;
        try {
            $card = ScratchCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
            $rewards = $card->scratch();

            if ($rewards['gold'] > 0) {
                app(GoldEconomyService::class)->getOrCreateWallet($userId)->addGold($rewards['gold'], 'scratch_card', 'scratch_card', (string) $card->id, 'Scratch card reward');
            }
            if ($rewards['gems'] > 0) {
                app(GemEconomyService::class)->getOrCreateWallet($userId)->addGems($rewards['gems'], 'scratch_card', 'scratch_card', (string) $card->id, 'Scratch card gem reward');
            }

            $card->claim();

            return response()->json(['success' => true, 'data' => $card, 'rewards' => $rewards]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function stats(Request $request)
    {
        $userId = $request->user()->id;
        $stats = $this->referralService->getReferralStats($userId);

        return response()->json(['success' => true, 'data' => $stats]);
    }
}
