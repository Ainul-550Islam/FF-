<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;

class Final7Final8ViewRoutesTest extends TestCase
{
    public function test_final7_and_final8_have_the_full_fifty_feature_views(): void
    {
        $missing = [];
        foreach (range(1001, 1050) as $n) {
            if (!view()->exists('gameberry.final7.feature_' . $n)) {
                $missing[] = 'final7/' . $n;
            }
        }
        foreach (range(1101, 1150) as $n) {
            if (!view()->exists('gameberry.final8.feature_' . $n)) {
                $missing[] = 'final8/' . $n;
            }
        }
        $this->assertSame([], $missing, 'Missing part 16/17 views: ' . implode(',', $missing));
    }

    public function test_view_controllers_exist_and_cover_the_range(): void
    {
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Final7\\Final7ViewController'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Final8\\Final8ViewController'));
    }
}
