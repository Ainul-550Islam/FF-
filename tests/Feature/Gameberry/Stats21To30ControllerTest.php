<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;

class Stats21To30ControllerTest extends TestCase
{
    public function test_stats_controllers_21_to_30_exist_for_web_and_api(): void
    {
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Stats\\Stat21Controller'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Stats\\Stat30Controller'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Api\\V1\\Gameberry\\Stats\\Stat21ApiController'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Api\\V1\\Gameberry\\Stats\\Stat30ApiController'));
    }

    public function test_stats_services_and_views_are_wired(): void
    {
        foreach (range(21, 30) as $n) {
            $this->assertTrue(class_exists('App\\Services\\Gameberry\\Stats\\Stat' . $n . 'Service'));
            $this->assertTrue(view()->exists('gameberry.dashboard.stat_' . $n));
        }
    }
}
