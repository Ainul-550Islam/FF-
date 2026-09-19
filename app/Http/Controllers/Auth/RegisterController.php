<?php
namespace App\Http\Controllers\Auth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
class RegisterController extends Controller
{
    public function showRegistrationForm() { return view('auth.register'); }
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'=>'required|string|max:255',
            'username'=>'nullable|string|min:3|max:30|unique:users,username|regex:/^[a-zA-Z0-9_\.]+$/',
            'email'=>'required|email|max:255|unique:users,email',
            'phone'=>'nullable|string|max:20',
            'password'=>'required|string|min:8|confirmed',
            'avatar'=>'nullable|image|mimes:jpeg,png,webp,gif|max:2048',
            'terms'=>'required|accepted',
        ]);
        $user = User::create([
            'name'=>$validated['name'],
            'username'=>$validated['username'] ?? null,
            'email'=>$validated['email'],
            'phone'=>$validated['phone'] ?? null,
            'password'=>Hash::make($validated['password']),
            'is_active'=>true,
            'timezone'=>'Asia/Dhaka',
            'locale'=>'en',
        ]);
        if($request->hasFile('avatar')){
            try{
                $file=$request->file('avatar');
                $filename='avatars/'.$user->id.'/'.Str::uuid().'.'.$file->getClientOriginalExtension();
                $path=$file->storeAs('',$filename,'local');
                $user->forceFill(['avatar_path'=>$path])->save();
            }catch(\Throwable $e){}
        }
        try{
            Wallet::create(['user_id'=>$user->id,'currency'=>'BDT','balance_minor'=>0]);
        }catch(\Throwable $e){}
        Auth::login($user);
        return redirect()->route('home')->with('success','Account created successfully!');
    }
}
