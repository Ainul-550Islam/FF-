<?php
namespace App\Services\Gameberry;
use App\Models\UserOnlineStatus;
use App\Models\PrivateTableParticipant;
use App\Models\AutoModeLog;
use Illuminate\Support\Facades\DB;
class AutoModeService
{
    public function enableAutoMode(int $userId, ?int $tableId = null, string $reason = 'disconnect'): AutoModeLog
    {
        return DB::transaction(function () use ($userId, $tableId, $reason) {
            $status = UserOnlineStatus::firstOrCreate(['user_id' => $userId], ['is_online' => false, 'hide_online_status' => false, 'notify_friends_online' => true, 'is_in_auto_mode' => false]);
            $status->is_in_auto_mode = true;
            $status->save();
            if ($tableId) {
                $participant = PrivateTableParticipant::where('private_table_id', $tableId)->where('user_id', $userId)->first();
                if ($participant) {
                    $participant->is_in_auto_mode = true;
                    $participant->auto_mode_on_at = now();
                    $participant->save();
                }
            }
            return AutoModeLog::create(['user_id' => $userId, 'private_table_id' => $tableId, 'reason' => $reason, 'is_auto_on' => true, 'auto_on_at' => now()]);
        });
    }
    public function disableAutoMode(int $userId, ?int $tableId = null): AutoModeLog
    {
        return DB::transaction(function () use ($userId, $tableId) {
            $status = UserOnlineStatus::where('user_id', $userId)->first();
            if ($status) { $status->is_in_auto_mode = false; $status->save(); }
            if ($tableId) {
                $participant = PrivateTableParticipant::where('private_table_id', $tableId)->where('user_id', $userId)->first();
                if ($participant) { $participant->is_in_auto_mode = false; $participant->auto_mode_off_at = now(); $participant->save(); }
            }
            return AutoModeLog::create(['user_id' => $userId, 'private_table_id' => $tableId, 'reason' => 'manual', 'is_auto_on' => false, 'auto_off_at' => now()]);
        });
    }
    public function isInAutoMode(int $userId): bool
    {
        $status = UserOnlineStatus::where('user_id', $userId)->first();
        return $status ? $status->is_in_auto_mode : false;
    }
    public function getAutoModeLogs(int $userId, int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        return AutoModeLog::where('user_id', $userId)->orderByDesc('created_at')->limit($limit)->get();
    }
    public function handleDisconnect(int $userId, ?int $tableId = null): AutoModeLog
    {
        return $this->enableAutoMode($userId, $tableId, 'disconnect');
    }
}
