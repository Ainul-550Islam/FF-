<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function show(Request $request, $team)
    {
        return response()->json(['data'=>['id'=>$team,'name'=>'Team '.$team]]);
    }

    public function update(Request $request, $team)
    {
        $request->validate(['name'=>['required','string','max:100']]);
        return response()->json(['data'=>['id'=>$team,'name'=>$request->input('name')],'message'=>'Updated']);
    }

    public function roster(Request $request, $team)
    {
        return response()->json(['data'=>[],'team_id'=>$team]);
    }

    public function addMember(Request $request, $team)
    {
        $request->validate(['user_id'=>['required','integer']]);
        return response()->json(['message'=>'Member added','team_id'=>$team], 201);
    }

    public function removeMember(Request $request, $team, $member)
    {
        return response()->json(['message'=>'Member removed','team_id'=>$team,'member_id'=>$member]);
    }

    public function withdraw(Request $request, $team)
    {
        return response()->json(['message'=>'Withdrawn','team_id'=>$team]);
    }
}
