<?php

namespace App\Http\Controllers;

use App\Models\User;

class AdminSecurityController extends Controller
{
    public function dashboard()
    {
        return view('admin.security.dashboard');
    }

    public function events()
    {
        return view('admin.security.events');
    }

    public function incidents()
    {
        return view('admin.security.incidents');
    }

    public function users()
    {
        $users = User::orderBy('created_at', 'desc')->paginate(20);

        return view('admin.security.users', compact('users'));
    }

    public function user(User $user)
    {
        return view('admin.security.user', compact('user'));
    }
}
