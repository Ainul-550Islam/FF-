<?php

namespace App\Services\Gameberry;

use App\Models\WeeklyEvent;
use App\Models\WeeklyEventParticipant;
use Illuminate\Support\Facades\DB;

class WeeklyEventService
{
    public function getActiveEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return WeeklyEvent::active()->orderBy('starts_at')->get();
    }

    public function getUpcomingEvents(): \Illuminate\Database\Eloquent\Collection
    {
        return WeeklyEvent::upcoming()->orderBy('starts_at')->get();
    }

    public function joinEvent(int $userId, int $eventId): WeeklyEventParticipant
    {
        $event = WeeklyEvent::findOrFail($eventId);

        if (!$event->isActive()) {
            throw new \Exception('Event not active');
        }

        if ($event->participants()->count() >= $event->max_participants && $event->max_participants > 0) {
            throw new \Exception('Event full');
        }

        return WeeklyEventParticipant::firstOrCreate(
            ['weekly_event_id' => $eventId, 'user_id' => $userId],
            ['progress' => 0, 'is_completed' => false]
        );
    }

    public function addProgress(int $userId, int $eventId, int $progress): WeeklyEventParticipant
    {
        return DB::transaction(function () use ($userId, $eventId, $progress) {
            $participant = WeeklyEventParticipant::where('weekly_event_id', $eventId)->where('user_id', $userId)->firstOrFail();
            $participant->progress += $progress;

            $event = $participant->weeklyEvent;
            if ($event->target_progress && $participant->progress >= $event->target_progress) {
                $participant->is_completed = true;
            }

            $participant->save();
            return $participant;
        });
    }

    public function getLeaderboard(int $eventId, int $limit = 100): \Illuminate\Database\Eloquent\Collection
    {
        return WeeklyEventParticipant::with('user')
            ->where('weekly_event_id', $eventId)
            ->orderByDesc('progress')
            ->limit($limit)
            ->get();
    }

    public function claimReward(int $userId, int $eventId): array
    {
        $participant = WeeklyEventParticipant::where('weekly_event_id', $eventId)->where('user_id', $userId)->firstOrFail();

        if (!$participant->is_completed) {
            throw new \Exception('Event not completed');
        }

        if (!empty($participant->rewards_claimed)) {
            throw new \Exception('Rewards already claimed');
        }

        $event = $participant->weeklyEvent;
        $rewards = $event->rewards ?? ['gold' => 100, 'gems' => 5];

        DB::transaction(function () use ($participant, $rewards, $userId, $eventId) {
            if (!empty($rewards['gold'])) {
                app(GoldEconomyService::class)->getOrCreateWallet($userId)->addGold($rewards['gold'], 'weekly_event', 'weekly_event', (string)$eventId, 'Weekly event reward');
            }
            if (!empty($rewards['gems'])) {
                app(GemEconomyService::class)->getOrCreateWallet($userId)->addGems($rewards['gems'], 'weekly_event', 'weekly_event', (string)$eventId, 'Weekly event reward');
            }

            $participant->rewards_claimed = $rewards;
            $participant->save();
        });

        return $rewards;
    }

    public function createWeeklyEvent(array $data): WeeklyEvent
    {
        return WeeklyEvent::create([
            'name' => $data['name'],
            'slug' => \Illuminate\Support\Str::slug($data['name']) . '-' . time(),
            'description' => $data['description'] ?? '',
            'type' => $data['type'] ?? 'special',
            'starts_at' => $data['starts_at'] ?? now(),
            'ends_at' => $data['ends_at'] ?? now()->addWeek(),
            'target_progress' => $data['target_progress'] ?? 100,
            'max_participants' => $data['max_participants'] ?? 0,
            'rewards' => $data['rewards'] ?? ['gold' => 500, 'gems' => 10],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function getUserProgress(int $userId): array
    {
        $active = $this->getActiveEvents();
        return $active->map(function ($event) use ($userId) {
            $participant = WeeklyEventParticipant::where('weekly_event_id', $event->id)->where('user_id', $userId)->first();
            return [
                'event' => $event,
                'progress' => $participant?->progress ?? 0,
                'is_completed' => $participant?->is_completed ?? false,
                'percent' => $event->target_progress ? round((($participant?->progress ?? 0) / $event->target_progress) * 100, 2) : 0,
            ];
        })->toArray();
    }
}
