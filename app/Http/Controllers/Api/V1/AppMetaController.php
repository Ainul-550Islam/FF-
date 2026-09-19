<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AppMetaController extends Controller
{
    public function meta(Request $request)
    {
        return response()->json([
            'version' => '1.0.0',
            'service' => 'ffarena',
            'name' => config('app.name', 'FF Arena'),
            'env' => config('app.env'),
            'urls' => [
                'privacy' => config('services.mobile.privacy_url', url('/privacy')),
                'support' => config('services.mobile.support_url', url('/support')),
                'terms' => url('/terms'),
            ],
            'features' => [
                'payments' => ['bkash','nagad','rocket','manual'],
                'auth' => ['email','google','phone_otp'],
                'tournaments' => true,
                'wallet' => true,
                'avatar' => true,
                'internet_check' => true,
            ],
            'min_app_version' => '1.0.0',
            'maintenance' => false,
        ]);
    }
}
