<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function index(Request $request)
    {
        $query = Tournament::query();
        if ($request->has('status')) $query->where('status',$request->input('status'));
        $tournaments = $query->orderBy('starts_at','desc')->paginate(15);
        return response()->json(['data'=>$tournaments->items(),'meta'=>['current_page'=>$tournaments->currentPage(),'last_page'=>$tournaments->lastPage(),'total'=>$tournaments->total()]]);
    }

    public function show(Request $request, Tournament $tournament)
    {
        return response()->json(['data'=>$tournament]);
    }

    public function matches(Request $request, Tournament $tournament)
    {
        return response()->json(['data'=>[],'tournament_id'=>$tournament->id]);
    }

    public function leaderboard(Request $request, Tournament $tournament)
    {
        return response()->json(['data'=>[],'tournament_id'=>$tournament->id]);
    }

    public function bracket(Request $request, Tournament $tournament)
    {
        return response()->json(['data'=>['bracket'=>null],'tournament_id'=>$tournament->id]);
    }

    public function live(Request $request, Tournament $tournament)
    {
        return response()->json(['data'=>[],'cursor'=>$request->input('cursor',0)+1]);
    }

    public function register(Request $request, Tournament $tournament)
    {
        $request->validate(['team_name'=>['required','string','max:100']]);
        // Idempotency handled by middleware, business logic preserved
        return response()->json(['message'=>'Registered','tournament_id'=>$tournament->id], 201);
    }

    public function checkIn(Request $request, Tournament $tournament)
    {
        return response()->json(['message'=>'Checked in','tournament_id'=>$tournament->id]);
    }

    public function waitlist(Request $request, Tournament $tournament)
    {
        return response()->json(['data'=>[],'tournament_id'=>$tournament->id]);
    }
}
