<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MatchController extends Controller
{
    public function show(Request $request, $match)
    {
        return response()->json(['data'=>['id'=>$match,'status'=>'ongoing','bracket'=>null]]);
    }

    public function submitScore(Request $request, $match)
    {
        $validated = $request->validate([
            'score' => ['required','integer','min:0'],
            'team_id' => ['nullable','integer'],
            'evidence' => ['nullable','string'],
        ]);
        return response()->json(['message'=>'Score submitted','match_id'=>$match,'score'=>$validated['score']], 201);
    }
}
