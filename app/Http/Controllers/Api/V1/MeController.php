<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'data' => $user,
            'avatar_url' => $user->avatar_url,
            'initials' => $user->initials,
            'wallets' => $user->wallets,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes','string','max:255'],
            'display_name' => ['sometimes','nullable','string','max:50'],
            'username' => ['sometimes','nullable','string','min:3','max:30', Rule::unique('users','username')->ignore($user->id)],
            'bio' => ['sometimes','nullable','string','max:500'],
            'country' => ['sometimes','nullable','string','size:2'],
            'timezone' => ['sometimes','nullable','string','max:50'],
            'locale' => ['sometimes','nullable','in:en,bn'],
        ]);

        if (isset($validated['username']) && $validated['username'] !== $user->username) {
            if (method_exists($user,'canChangeUsername') && !$user->canChangeUsername()) {
                return response()->json(['error'=>'username_cooldown','message'=>'Username change cooldown','days'=> $user->daysUntilUsernameChange()], 422);
            }
            $validated['username_changed_at'] = now();
        }

        $user->fill($validated);
        $user->save();

        return response()->json(['data'=>$user,'message'=>'Profile updated']);
    }

    public function security(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'two_factor_enabled' => $user->two_factor_enabled ?? false,
            'email_verified' => !is_null($user->email_verified_at),
            'phone_verified' => !is_null($user->phone_verified_at),
            'has_avatar' => $user->hasAvatar(),
        ]);
    }

    public function sessions(Request $request)
    {
        $user = $request->user();
        try {
            $sessions = DB::table('user_sessions')->where('user_id',$user->id)->orderBy('last_active_at','desc')->get();
            if ($sessions->isEmpty()) {
                $sessions = collect([[
                    'id'=>1,'session_id'=>$request->session()->getId() ?? 'current','ip_address'=>$request->ip(),'device_label'=>'Current Device','is_current'=>true
                ]]);
            }
        } catch (\Throwable $e) {
            $sessions = collect();
        }
        return response()->json(['data'=>$sessions]);
    }

    public function revokeSession(Request $request, $session)
    {
        try { DB::table('user_sessions')->where('user_id',$request->user()->id)->where('id',$session)->update(['is_revoked'=>true]); } catch (\Throwable $e) {}
        return response()->json(['message'=>'Session revoked']);
    }

    public function revokeOthers(Request $request)
    {
        try {
            DB::table('user_sessions')->where('user_id',$request->user()->id)->where('session_id','!=',$request->session()->getId())->update(['is_revoked'=>true]);
            DB::table('sessions')->where('user_id',$request->user()->id)->where('id','!=',$request->session()->getId())->delete();
        } catch (\Throwable $e) {}
        return response()->json(['message'=>'Other sessions revoked']);
    }

    public function revokeAll(Request $request)
    {
        try {
            DB::table('user_sessions')->where('user_id',$request->user()->id)->update(['is_revoked'=>true]);
            DB::table('sessions')->where('user_id',$request->user()->id)->delete();
        } catch (\Throwable $e) {}
        return response()->json(['message'=>'All sessions revoked']);
    }
}
