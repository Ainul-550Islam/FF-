<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\Gameberry\ReferralService;
use Illuminate\Database\Seeder;

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
