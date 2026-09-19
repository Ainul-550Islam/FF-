<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required','string'],
            'platform' => ['required','in:android,ios,web'],
            'device_id' => ['nullable','string'],
        ]);
        return response()->json(['data'=>['token'=>$validated['token'],'platform'=>$validated['platform']],'message'=>'Device registered'], 201);
    }

    public function destroy(Request $request, $device)
    {
        return response()->json(['message'=>'Device removed','id'=>$device]);
    }
}
