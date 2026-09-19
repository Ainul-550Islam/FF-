<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function show(Request $request, User $user)
    {
        return response()->json(['data'=>[
            'id'=>$user->id,
            'name'=>$user->display_name_or_name,
            'username'=>$user->username,
            'avatar_url'=>$user->avatar_url,
            'initials'=>$user->initials,
            'bio'=>$user->bio,
        ]]);
    }
}
