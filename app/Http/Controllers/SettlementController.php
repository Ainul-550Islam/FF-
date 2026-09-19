<?php
namespace App\Http\Controllers;
use App\Models\FinancialSettlement;
use App\Models\Tournament;
use Illuminate\Http\Request;
class SettlementController extends Controller
{
    public function index() { $settlements=FinancialSettlement::orderBy('created_at','desc')->paginate(20); return view('admin.settlements', compact('settlements')); }
    public function show(FinancialSettlement $settlement) { return view('admin.settlement', compact('settlement')); }
    public function showByTournament(Tournament $tournament) { $settlement=FinancialSettlement::where('tournament_id',$tournament->id)->first(); return view('admin.settlement', compact('settlement','tournament')); }
}
