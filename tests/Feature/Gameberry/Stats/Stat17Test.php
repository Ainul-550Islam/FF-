<?php
namespace Tests\Feature\Gameberry\Stats;
use Tests\TestCase;
use App\Models\User;
use App\Services\Gameberry\Stats\Stat17Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
class Stat17Test extends TestCase
{
    use RefreshDatabase;
    protected Stat17Service $service;
    protected function setUp(): void { parent::setUp(); $this->service = app(Stat17Service::class); }
    public function test_stat_17_get_stats(): void
    {
        $user = User::factory()->create();
        $stats = $this->service->getStats($user->id);
        $this->assertEquals($user->id, $stats['user_id']);
        $this->assertEquals(17 * 100, $stats['stat_17_value']);
        $this->assertArrayHasKey('description', $stats);
        $this->assertStringContainsString('250+ dice collection', $stats['description']);
        $this->assertStringContainsString('6-step league Bronze', $stats['description']);
        $this->assertStringContainsString('Game Buddies max 25', $stats['description']);
        $this->assertStringContainsString('private table code/link', $stats['description']);
        $this->assertStringContainsString('gold at stake', $stats['description']);
        $this->assertStringContainsString('magic chest', $stats['description']);
        $this->assertStringContainsString('video ads free gold', $stats['description']);
        $this->assertStringContainsString('gems', $stats['description']);
        $this->assertStringContainsString('lucky dice gem reward', $stats['description']);
        $this->assertStringContainsString('spin2win', $stats['description']);
        $this->assertStringContainsString('auto mode', $stats['description']);
        $this->assertStringContainsString('hide online status', $stats['description']);
        $this->assertStringContainsString('notify friends', $stats['description']);
        $this->assertStringContainsString('Level 4 Bronze unlock', $stats['description']);
        $this->assertStringContainsString('referral BGI20', $stats['description']);
        $this->assertStringContainsString('scratch cards', $stats['description']);
        $this->assertStringContainsString('reconciliation', $stats['description']);
    }
    public function test_stat_17_calculate(): void
    {
        $user = User::factory()->create();
        $result = $this->service->calculate($user->id, 100);
        $this->assertEquals(100 * 17 + $user->id, $result);
    }
}
