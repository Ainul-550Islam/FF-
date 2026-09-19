<?php
namespace App\Http\Controllers\Auth;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class GoogleAuthController extends Controller
{
    public function redirect() { return redirect()->route('login')->with('warning','Google OAuth requires configuration - use email login'); }
    public function callback(Request $request) { return redirect()->route('login')->with('error','Google OAuth not configured in demo'); }
}
