<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WebhookSubscriptionController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['url'=>['required','url'],'events'=>['required','array']]);
        return response()->json(['data'=>['url'=>$validated['url'],'events'=>$validated['events']],'message'=>'Endpoint created'], 201);
    }

    public function show(Request $request, $endpoint)
    {
        return response()->json(['data'=>['id'=>$endpoint,'url'=>'https://example.com/webhook']]);
    }

    public function rotateSecret(Request $request, $endpoint)
    {
        return response()->json(['message'=>'Secret rotated','endpoint_id'=>$endpoint]);
    }

    public function toggle(Request $request, $endpoint)
    {
        return response()->json(['message'=>'Toggled','endpoint_id'=>$endpoint]);
    }

    public function deliveries(Request $request, $endpoint)
    {
        return response()->json(['data'=>[],'endpoint_id'=>$endpoint]);
    }

    public function events(Request $request)
    {
        return response()->json(['data'=>[]]);
    }
}
