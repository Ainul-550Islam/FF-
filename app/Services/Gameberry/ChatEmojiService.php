<?php

namespace App\Services\Gameberry;

use App\Models\ChatMessage;
use App\Models\PrivateTable;

class ChatEmojiService
{
    const EMOJIS = [
        'smile' => '😊',
        'laugh' => '😂',
        'angry' => '😠',
        'cry' => '😢',
        'cool' => '😎',
        'love' => '❤️',
        'thumbs_up' => '👍',
        'thumbs_down' => '👎',
        'fire' => '🔥',
        'dice' => '🎲',
        'crown' => '👑',
        'trophy' => '🏆',
        'gg' => 'GG',
        'well_played' => 'Well Played!',
        'oops' => 'Oops!',
        'wow' => 'Wow!',
        'hurry' => 'Hurry Up!',
        'good_luck' => 'Good Luck!',
    ];

    const QUICK_MESSAGES = [
        'Good Luck!',
        'Well Played!',
        'Hurry Up!',
        'Oops!',
        'Wow!',
        'GG',
        'Nice Move!',
        'Your Turn!',
        'Haha!',
        'Oh No!',
    ];

    public function sendMessage(int $userId, string $tableCode, string $message, string $type = 'text'): ChatMessage
    {
        $table = PrivateTable::where('code', strtoupper($tableCode))->firstOrFail();

        // Validate participant
        $isParticipant = $table->participants()->where('user_id', $userId)->exists();
        if (!$isParticipant) {
            throw new \Exception('Not a participant of this table');
        }

        // Sanitize message
        $message = trim($message);
        if (strlen($message) > 200) {
            $message = substr($message, 0, 200);
        }

        return ChatMessage::create([
            'private_table_id' => $table->id,
            'user_id' => $userId,
            'message' => $message,
            'type' => $type,
            'is_system' => false,
        ]);
    }

    public function sendEmoji(int $userId, string $tableCode, string $emojiKey): ChatMessage
    {
        if (!isset(self::EMOJIS[$emojiKey])) {
            throw new \Exception('Invalid emoji');
        }

        return $this->sendMessage($userId, $tableCode, self::EMOJIS[$emojiKey], 'emoji');
    }

    public function sendQuickMessage(int $userId, string $tableCode, string $quickMessage): ChatMessage
    {
        if (!in_array($quickMessage, self::QUICK_MESSAGES)) {
            throw new \Exception('Invalid quick message');
        }

        return $this->sendMessage($userId, $tableCode, $quickMessage, 'quick');
    }

    public function sendSystemMessage(string $tableCode, string $message): ChatMessage
    {
        $table = PrivateTable::where('code', strtoupper($tableCode))->firstOrFail();

        return ChatMessage::create([
            'private_table_id' => $table->id,
            'user_id' => null,
            'message' => $message,
            'type' => 'system',
            'is_system' => true,
        ]);
    }

    public function getMessages(string $tableCode, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $table = PrivateTable::where('code', strtoupper($tableCode))->firstOrFail();
        return ChatMessage::with('user')->where('private_table_id', $table->id)->orderByDesc('created_at')->limit($limit)->get()->reverse()->values();
    }

    public function getEmojis(): array
    {
        return self::EMOJIS;
    }

    public function getQuickMessages(): array
    {
        return self::QUICK_MESSAGES;
    }

    public function muteUser(int $tableId, int $userId): void
    {
        // In real app, would have muted_users table
        // For now, log system message
        ChatMessage::create([
            'private_table_id' => $tableId,
            'user_id' => null,
            'message' => "User {$userId} muted",
            'type' => 'system',
            'is_system' => true,
        ]);
    }
}
