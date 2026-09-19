<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LiveController extends Controller
{
    public function me(Request $request)
    {
        return response()->json(['data'=>[],'cursor'=>$request->input('cursor',0)+1]);
    }
}
