<?php

namespace Tests\Feature\Gameberry;

use Tests\TestCase;

class CoreSeriesRoutesTest extends TestCase
{
    public function test_core_view_controller_serves_every_generated_feature_view(): void
    {
        $controller = 'App\\Http\\Controllers\\Gameberry\\Core\\CoreFeatureViewController';
        $this->assertTrue(class_exists($controller), 'CoreFeatureViewController must exist');

        foreach ([121, 160, 201, 250, 312, 331, 362, 381] as $feature) {
            $view = 'gameberry.core.feature_' . $feature;
            $this->assertTrue(view()->exists($view), 'Missing core view: ' . $view);
        }
    }

    public function test_core_service_family_is_complete_for_every_number(): void
    {
        $missing = [];
        foreach (range(161, 180) as $n) {
            if (!class_exists('App\\Services\\Gameberry\\Core\\Core' . $n . 'Service')) {
                $missing[] = $n;
            }
        }
        foreach (range(251, 281) as $n) {
            if (!class_exists('App\\Services\\Gameberry\\Core\\Core' . $n . 'Service')) {
                $missing[] = $n;
            }
        }
        foreach (range(332, 394) as $n) {
            if (!class_exists('App\\Services\\Gameberry\\Core\\Core' . $n . 'Service')) {
                $missing[] = $n;
            }
        }
        $this->assertSame([], $missing, 'Core services missing: ' . implode(',', $missing));
    }

    public function test_core_controllers_exist_for_all_blocks(): void
    {
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Core\\Core181Controller'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Core\\Core282Controller'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Core\\Core352Controller'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Gameberry\\Core\\Core395Controller'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core191ApiController'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core297ApiController'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core357ApiController'));
        $this->assertTrue(class_exists('App\\Http\\Controllers\\Api\\V1\\Gameberry\\Core\\Core398ApiController'));
    }
}
