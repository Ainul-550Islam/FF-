<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class SupportController extends Controller
{
    public function __construct() { $this->middleware(['auth','active']); }
    public function index() { return view('support.index', ['tickets'=>collect()]); }
    public function create() { return view('support.create'); }
    public function store(Request $request) { $request->validate(['subject'=>'required|string|max:255','body'=>'required|string|max:2000']); return redirect()->route('support.index')->with('success','Ticket created'); }
    public function show($ticket) { return view('support.show', ['ticket'=>(object)['id'=>$ticket,'subject'=>'Support Ticket #'.$ticket]]); }
}
