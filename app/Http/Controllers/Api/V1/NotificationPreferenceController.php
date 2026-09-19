<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>['email'=>true,'push'=>true,'in_app'=>true]]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'email_enabled' => ['nullable','boolean'],
            'push_enabled' => ['nullable','boolean'],
            'in_app_enabled' => ['nullable','boolean'],
        ]);
        return response()->json(['data'=>$validated,'message'=>'Preferences updated']);
    }
}
