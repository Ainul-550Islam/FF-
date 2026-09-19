<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RustSecurityController extends Controller
{
    public function evaluate(Request $request)
    {
        return response()->json(['overall_score'=>0,'level'=>'low','recommendation'=>'allow']);
    }

    public function evaluateDevice(Request $request)
    {
        return response()->json(['score'=>0,'level'=>'low','provider'=>'device']);
    }

    public function evaluateIp(Request $request)
    {
        return response()->json(['score'=>0,'level'=>'low','provider'=>'ip']);
    }

    public function evaluateIdentity(Request $request)
    {
        return response()->json(['score'=>0,'level'=>'low','provider'=>'identity']);
    }

    public function riskScore(Request $request)
    {
        return response()->json(['score'=>0,'level'=>'low','recommendation'=>'allow']);
    }

    public function providers(Request $request)
    {
        return response()->json(['providers'=>['device','ip','external','identity']]);
    }

    public function health(Request $request)
    {
        return response()->json(['status'=>'ok','service'=>'rust-security','enabled'=>config('services_go_rust.rust_security.enabled', false)]);
    }
}
