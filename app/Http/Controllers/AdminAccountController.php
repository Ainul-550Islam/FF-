<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
class AdminAccountController extends Controller
{
    public function index(Request $request) { $users=User::orderBy('created_at','desc')->paginate(20); return view('admin.accounts.index', compact('users')); }
    public function show(User $user) { return view('admin.accounts.show', compact('user')); }
}
