<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class OpsController extends Controller
{
    public function dashboard() { $failed=DB::table('failed_jobs')->count(); $jobs=DB::table('jobs')->count(); return view('admin.ops.dashboard', compact('failed','jobs')); }
    public function failedJobs() { $jobs=DB::table('failed_jobs')->orderBy('failed_at','desc')->paginate(20); return view('admin.ops.failed-jobs', compact('jobs')); }
}
