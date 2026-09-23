<?php

namespace App\Http\Controllers\Api\V1\Gameberry;

use App\Http\Controllers\Controller;
use App\Services\Gameberry\ChatEmojiService;
use Illuminate\Http\Request;

class ChatApiController extends Controller
{
    protected ChatEmojiService $chatService;

    public function __construct(ChatEmojiService $chatService)
    {
        $this->chatService = $chatService;
    }

    public function send(Request $request, string $code)
    {
        $request->validate(['message' => 'required|string|max:200', 'type' => 'in:text,emoji,quick']);
        $userId = $request->user()->id;
        try {
            $message = $this->chatService->sendMessage($userId, $code, $request->message, $request->get('type', 'text'));

            return response()->json(['success' => true, 'data' => $message->load('user')]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function emoji(Request $request, string $code)
    {
        $request->validate(['emoji_key' => 'required|string']);
        $userId = $request->user()->id;
        try {
            $message = $this->chatService->sendEmoji($userId, $code, $request->emoji_key);

            return response()->json(['success' => true, 'data' => $message->load('user')]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function quick(Request $request, string $code)
    {
        $request->validate(['quick_message' => 'required|string']);
        $userId = $request->user()->id;
        try {
            $message = $this->chatService->sendQuickMessage($userId, $code, $request->quick_message);

            return response()->json(['success' => true, 'data' => $message->load('user')]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function messages(Request $request, string $code)
    {
        try {
            $messages = $this->chatService->getMessages($code, 50);

            return response()->json(['success' => true, 'data' => $messages, 'emojis' => $this->chatService->getEmojis(), 'quick_messages' => $this->chatService->getQuickMessages()]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}
