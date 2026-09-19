<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class DisputeController extends Controller
{
    public function __construct() { $this->middleware(['auth','active']); }
    public function create($match) { return view('disputes.create', ['match'=>(object)['id'=>$match]]); }
    public function store(Request $request) { $request->validate(['reason'=>'required|string|max:1000']); return redirect()->route('home')->with('success','Dispute submitted'); }
    public function show($dispute) { return view('disputes.show', ['dispute'=>(object)['id'=>$dispute]]); }
}
