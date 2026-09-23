<?php

namespace App\Http\Middleware;

use App\Models\Level;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GameberryLevelCheck
{
    protected array $leagueLevelRequirements = [
        'bronze' => 4,
        'silver' => 4,
        'gold' => 6,
        'platinum' => 8,
        'diamond' => 10,
        'titan' => 12,
    ];

    public function handle(Request $request, Closure $next, ?string $leagueSlug = null): Response
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login');
        }

        $userLevel = Level::where('user_id', $user->id)->first();
        $levelNumber = $userLevel?->level ?? 1;

        if ($leagueSlug) {
            $required = $this->leagueLevelRequirements[$leagueSlug] ?? 4;
            if ($levelNumber < $required) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'error' => "Level {$required} required to access {$leagueSlug} league - you are Level {$levelNumber}. Bronze unlocks at Level 4 (Gameberry FAQ)",
                        'current_level' => $levelNumber,
                        'required_level' => $required,
                    ], 403);
                }

                return redirect()->route('gameberry.league.index')->with('error', "Need Level {$required} to access {$leagueSlug} league - you are Level {$levelNumber}. Keep playing to reach Level 4 Bronze unlock!");
            }
        }

        return $next($request);
    }
}
