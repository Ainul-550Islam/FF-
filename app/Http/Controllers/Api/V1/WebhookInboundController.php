<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookInboundController extends Controller
{
    public function handle(Request $request, $provider)
    {
        $rawBody = $request->getContent();
        $signature = $request->header('X-Webhook-Signature') ?? $request->header('X-Signature') ?? '';
        
        // In production, verify HMAC signature, timestamp, event_id, replay protection
        Log::info('webhook.inbound', ['provider'=>$provider,'event_id'=>$request->input('event_id'),'signature_present'=>!empty($signature)]);

        // Simulate transactional processing
        return response()->json(['status'=>'received','provider'=>$provider,'event_id'=>$request->input('event_id')]);
    }
}
