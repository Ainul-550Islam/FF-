<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\SpinWheel;
class GameberrySpinSeeder extends Seeder
{
    public function run(): void
    {
        SpinWheel::firstOrCreate(['slug' => 'default-spin2win'], [
            'name' => 'Default Spin2Win',
            'cost_gold' => 100,
            'is_active' => true,
            'rewards_config' => [
                ['result' => 'gold', 'gold' => 200, 'gems' => 0, 'weight' => 40],
                ['result' => 'gems', 'gold' => 0, 'gems' => 5, 'weight' => 30],
                ['result' => 'dice', 'gold' => 0, 'gems' => 0, 'weight' => 20],
                ['result' => 'jackpot', 'gold' => 1000, 'gems' => 20, 'weight' => 10],
            ],
            'daily_free_spins' => 1,
            'max_spins_per_day' => 10,
        ]);
        SpinWheel::firstOrCreate(['slug' => 'premium-spin'], [
            'name' => 'Premium Spin',
            'cost_gold' => 500,
            'is_active' => true,
            'rewards_config' => [
                ['result' => 'gold', 'gold' => 1000, 'gems' => 0, 'weight' => 30],
                ['result' => 'gems', 'gold' => 0, 'gems' => 20, 'weight' => 30],
                ['result' => 'dice', 'gold' => 0, 'gems' => 0, 'weight' => 20],
                ['result' => 'jackpot', 'gold' => 5000, 'gems' => 100, 'weight' => 20],
            ],
            'daily_free_spins' => 0,
            'max_spins_per_day' => 5,
        ]);
        $this->command->info('Seeded Spin2Win wheels: default 100 gold and premium 500 gold');
    }
}
