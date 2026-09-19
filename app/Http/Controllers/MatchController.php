<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class MatchController extends Controller
{
    public function show(Request $request, $match) { return view('matches.show', ['match'=>(object)['id'=>$match,'status'=>'ongoing']]); }
}
