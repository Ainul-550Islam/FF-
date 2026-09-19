<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    public function index(Request $request)
    {
        $tokens = $request->user()->tokens()->get()->map(fn($t)=>['id'=>$t->id,'name'=>$t->name,'abilities'=>$t->abilities,'last_used_at'=>$t->last_used_at]);
        return response()->json(['data'=>$tokens]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name'=>['required','string','max:255'],'abilities'=>['nullable','array']]);
        $token = $request->user()->createToken($validated['name'], $validated['abilities'] ?? ['*']);
        return response()->json(['data'=>['token'=>$token->plainTextToken,'name'=>$validated['name']]], 201);
    }

    public function destroy(Request $request, $tokenId)
    {
        $request->user()->tokens()->where('id',$tokenId)->delete();
        return response()->json(['message'=>'Token deleted']);
    }

    public function clients(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function storeClient(Request $request)
    {
        $validated = $request->validate(['name'=>['required','string','max:255']]);
        return response()->json(['data'=>['name'=>$validated['name']],'message'=>'Client created'], 201);
    }

    public function destroyClient(Request $request, $client)
    {
        return response()->json(['message'=>'Client deleted','id'=>$client]);
    }
}
