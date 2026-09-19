<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function show(Request $request, $tournament)
    {
        if ($tournament === 'latest') {
            $t = Tournament::orderBy('created_at','desc')->first();
        } else {
            $t = Tournament::where('id',$tournament)->orWhere('slug',$tournament)->first();
        }
        return response()->json(['data'=>[],'tournament'=>$t]);
    }

    public function playerRanking(Request $request, $user)
    {
        return response()->json(['data'=>['user_id'=>$user,'rank'=>1,'points'=>0]]);
    }
}
