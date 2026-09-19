<?php
namespace App\Http\Controllers;
use App\Models\Tournament;
use Illuminate\Http\Request;
class LeaderboardController extends Controller
{
    public function show(Request $request, $tournament)
    {
        if($tournament==='latest'){ $tournament = Tournament::orderBy('created_at','desc')->first(); }
        else { $tournament = Tournament::where('slug',$tournament)->orWhere('id',$tournament)->first(); }
        return view('leaderboard.show', compact('tournament'));
    }
}
