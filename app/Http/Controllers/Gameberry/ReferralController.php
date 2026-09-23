<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Models\ScratchCard;
use App\Services\Gameberry\GemEconomyService;
use App\Services\Gameberry\GoldEconomyService;
use App\Services\Gameberry\ReferralService;
use Illuminate\Http\Request;

class ReferralController extends Controller
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

        return view('gameberry.referral.index', compact('stats', 'referrals', 'scratchCards'));
    }

    public function generateCode(Request $request)
    {
        $userId = $request->user()->id;
        try {
            $code = $this->referralService->generateReferralCode($userId);

            return redirect()->back()->with('success', "Referral code generated: {$code} - Share for ₹25 bonus! BGI20 style");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function applyCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $userId = $request->user()->id;
        try {
            $referral = $this->referralService->applyReferralCode($userId, $request->code);

            return redirect()->back()->with('success', 'Referral applied! You and referrer got ₹25 bonus + scratch card!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function scratchCards(Request $request)
    {
        $userId = $request->user()->id;
        $cards = ScratchCard::where('user_id', $userId)->orderByDesc('created_at')->get();
        $unscratched = $cards->where('status', 'unscratched');

        return view('gameberry.referral.scratch_cards', compact('cards', 'unscratched'));
    }

    public function scratch(Request $request, int $cardId)
    {
        $userId = $request->user()->id;
        try {
            $card = ScratchCard::where('id', $cardId)->where('user_id', $userId)->firstOrFail();
            $rewards = $card->scratch();

            // Give rewards
            if ($rewards['gold'] > 0) {
                app(GoldEconomyService::class)->getOrCreateWallet($userId)->addGold($rewards['gold'], 'scratch_card', 'scratch_card', (string) $card->id, 'Scratch card reward');
            }
            if ($rewards['gems'] > 0) {
                app(GemEconomyService::class)->getOrCreateWallet($userId)->addGems($rewards['gems'], 'scratch_card', 'scratch_card', (string) $card->id, 'Scratch card gem reward');
            }

            $card->claim();

            return redirect()->back()->with('success', "Scratched! +{$rewards['gold']} gold, +{$rewards['gems']} gems");
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
