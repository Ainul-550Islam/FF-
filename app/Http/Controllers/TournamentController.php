<?php
namespace App\Http\Controllers;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentController extends Controller
{
    public function index(Request $request)
    {
        $tournaments = Tournament::orderBy('starts_at','desc')->paginate(12);
        return view('tournaments.index', compact('tournaments'));
    }
    public function show(Request $request, Tournament $tournament)
    {
        return view('tournaments.show', compact('tournament'));
    }
    public function create() { return view('tournaments.create'); }
    public function store(Request $request)
    {
        $validated = $request->validate(['name'=>'required|string|max:255','entry_fee_minor'=>'required|integer|min:0','prize_pool_minor'=>'required|integer|min:0','max_teams'=>'required|integer|min:2|max:100']);
        $validated['slug'] = Str::slug($validated['name']).'-'.Str::random(6);
        $validated['status'] = 'draft';
        $tournament = Tournament::create($validated);
        return redirect()->route('tournaments.show',$tournament)->with('success','Tournament created');
    }
    public function edit(Tournament $tournament) { return view('tournaments.edit', compact('tournament')); }
    public function update(Request $request, Tournament $tournament)
    {
        $validated = $request->validate(['name'=>'required|string|max:255','status'=>'required|in:draft,open,ongoing,completed,cancelled']);
        $tournament->update($validated);
        return redirect()->route('tournaments.show',$tournament)->with('success','Updated');
    }
    public function scoring(Tournament $tournament) { return view('tournaments.scoring', compact('tournament')); }
}
