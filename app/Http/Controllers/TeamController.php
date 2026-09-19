<?php
namespace App\Http\Controllers;
use App\Models\Tournament;
use Illuminate\Http\Request;
class TeamController extends Controller
{
    public function __construct() { $this->middleware(['auth','active'])->except(['show']); }
    public function show(Request $request, $team) { return view('teams.show', ['team' => (object)['id'=>$team,'name'=>'Team '.$team]]); }
    public function showRegisterForm(Tournament $tournament) { return view('teams.register', compact('tournament')); }
    public function register(Request $request, Tournament $tournament)
    {
        $request->validate(['team_name'=>'required|string|max:100']);
        return redirect()->route('tournaments.show',$tournament)->with('success','Team registered for tournament');
    }
}
