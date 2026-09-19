<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function show(Request $request, $dispute)
    {
        return response()->json(['data'=>['id'=>$dispute,'status'=>'open']]);
    }
}
