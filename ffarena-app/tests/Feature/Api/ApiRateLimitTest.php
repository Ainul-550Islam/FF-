<?php

namespace Tests\Feature\Api;

/**
 * Phase 15 — route-level, named API rate limits.
 */
class ApiRateLimitTest extends ApiTestCase
{
    public function test_login_is_rate_limited_per_identifier(): void
    {
        // 5 attempts/min per identifier — the 6th is a 429.
        $responses = [];

        for ($i = 0; $i < 6; $i++) {
            $responses[] = $this->postJson('/api/v1/auth/login', [
                'email' => 'ratelimited@example.com',
                'password' => 'wrong-password',
            ])->getStatusCode();
        }

        $this->assertSame(401, $responses[0]);
        $this->assertSame(429, $responses[5]);
    }

    public function test_anonymous_discovery_is_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 65; $i++) {
            $statuses[] = $this->getJson('/api/v1/tournaments')->getStatusCode();
        }

        $this->assertSame(200, $statuses[0]);
        $this->assertContains(429, $statuses);
    }

    public function test_rate_limit_response_uses_standard_envelope(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'envelope@example.com',
                'password' => 'x',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'envelope@example.com',
            'password' => 'x',
        ])->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }
}
