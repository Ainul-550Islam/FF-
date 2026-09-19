<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Services\Gameberry\ReferralService;
class GameberryReferralSeeder extends Seeder
{
    public function run(): void
    {
        $referralService = app(ReferralService::class);
        $users = User::take(10)->get();
        foreach ($users as $user) {
            try {
                $referralService->generateReferralCode($user->id);
            } catch (\Exception $e) {
                // ignore
            }
        }
        $this->command->info('Seeded referral BGI20 style codes for users - ₹25 bonus');
    }
}
