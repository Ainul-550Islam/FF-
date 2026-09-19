<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Tournament;
use App\Models\Payment;
use App\Models\Payout;
use Illuminate\Http\Request;
class AdminController extends Controller
{
    public function dashboard() { $stats=['users'=>User::count(),'tournaments'=>Tournament::count(),'payments'=>Payment::count(),'payouts'=>Payout::count()]; return view('admin.dashboard', compact('stats')); }
    public function wallet() { return view('admin.wallet'); }
    public function payments() { return view('admin.payments', ['payments'=>Payment::orderBy('created_at','desc')->paginate(20)]); }
    public function payouts() { return view('admin.payouts', ['payouts'=>Payout::orderBy('created_at','desc')->paginate(20)]); }
    public function support() { return view('admin.support'); }
    public function supportTicket($ticket) { return view('admin.support_ticket', ['ticket'=>(object)['id'=>$ticket]]); }
    public function moderation() { return view('moderation.index'); }
    public function audit() { return view('admin.audit'); }
}
