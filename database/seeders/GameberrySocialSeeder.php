<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\GameBuddy;
use App\Models\UserOnlineStatus;
class GameberrySocialSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::take(20)->get();
        foreach ($users as $user) {
            UserOnlineStatus::firstOrCreate(['user_id' => $user->id], ['is_online' => fake()->boolean(30), 'hide_online_status' => fake()->boolean(10), 'notify_friends_online' => true, 'is_in_auto_mode' => false]);
        }
        // Create random buddies max 25
        foreach ($users as $user) {
            $buddyCount = rand(0, 10);
            $buddies = User::where('id', '!=', $user->id)->inRandomOrder()->take($buddyCount)->get();
            foreach ($buddies as $buddy) {
                GameBuddy::firstOrCreate(['user_id' => $user->id, 'buddy_id' => $buddy->id], ['status' => 'accepted']);
            }
        }
        $this->command->info('Seeded social: online statuses, game buddies max 25, hide online status, notify friends online');
    }
}
