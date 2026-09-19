<?php

namespace App\Http\Controllers\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\ChatEmojiService;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    protected ChatEmojiService $chatService;

    public function __construct(ChatEmojiService $chatService)
    {
        $this->chatService = $chatService;
    }

    public function send(Request $request, string $code)
    {
        $request->validate([
            'message' => 'required|string|max:200',
            'type' => 'in:text,emoji,quick',
        ]);

        $userId = $request->user()->id;

        try {
            $message = $this->chatService->sendMessage($userId, $code, $request->message, $request->get('type', 'text'));
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'message' => $message->load('user')]);
            }
            return redirect()->back();
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function emoji(Request $request, string $code)
    {
        $request->validate([
            'emoji_key' => 'required|string',
        ]);

        $userId = $request->user()->id;

        try {
            $message = $this->chatService->sendEmoji($userId, $code, $request->emoji_key);
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'message' => $message->load('user')]);
            }
            return redirect()->back();
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function quick(Request $request, string $code)
    {
        $request->validate([
            'quick_message' => 'required|string',
        ]);

        $userId = $request->user()->id;

        try {
            $message = $this->chatService->sendQuickMessage($userId, $code, $request->quick_message);
            if ($request->expectsJson()) {
                return response()->json(['success' => true, 'message' => $message->load('user')]);
            }
            return redirect()->back();
        } catch (\Exception $e) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function messages(Request $request, string $code)
    {
        try {
            $messages = $this->chatService->getMessages($code, 50);
            return response()->json(['success' => true, 'messages' => $messages]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
