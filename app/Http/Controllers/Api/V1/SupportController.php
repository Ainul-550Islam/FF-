<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data'=>[]]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => ['required','string','max:255'],
            'body' => ['required','string','max:5000'],
            'priority' => ['nullable','in:low,medium,high'],
        ]);
        return response()->json(['data'=>['subject'=>$validated['subject'],'status'=>'open'],'message'=>'Ticket created'], 201);
    }

    public function show(Request $request, $ticket)
    {
        return response()->json(['data'=>['id'=>$ticket,'subject'=>'Support #'.$ticket,'status'=>'open']]);
    }

    public function messages(Request $request, $ticket)
    {
        return response()->json(['data'=>[],'ticket_id'=>$ticket]);
    }

    public function reply(Request $request, $ticket)
    {
        $request->validate(['body'=>['required','string','max:5000']]);
        return response()->json(['message'=>'Reply sent','ticket_id'=>$ticket], 201);
    }
}
