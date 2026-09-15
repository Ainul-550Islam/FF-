<?php

namespace Tests\Feature\Api;

/**
 * Phase 18 — server-driven app metadata endpoint.
 */
class ApiAppMetaTest extends ApiTestCase
{
    public function test_app_meta_is_public_and_contains_no_secrets(): void
    {
        $res = $this->getJson('/api/v1/app/meta');

        $res->assertOk()
            ->assertJsonPath('data.app.name', config('app.name', 'FF Arena'))
            ->assertJsonPath('data.app.api_version', '1')
            ->assertJsonPath('data.app.deep_link_scheme', (string) config('mobile.deep_link_scheme', 'ffarena'))
            ->assertJsonPath('data.platform.currency', 'BDT')
            ->assertJsonStructure([
                'data' => [
                    'app' => ['name', 'api_version', 'min_supported_app_version', 'latest_app_version', 'deep_link_scheme'],
                    'push' => ['fcm_enabled', 'apns_enabled'],
                    'urls' => ['support', 'privacy', 'terms'],
                    'platform' => ['currency', 'timezone', 'locale'],
                ],
            ]);
    }

    public function test_push_flags_are_off_by_default(): void
    {
        $this->getJson('/api/v1/app/meta')
            ->assertOk()
            ->assertJsonPath('data.push.fcm_enabled', false)
            ->assertJsonPath('data.push.apns_enabled', false);
    }

    public function test_release_and_maintenance_fields_are_present(): void
    {
        $this->getJson('/api/v1/app/meta')
            ->assertOk()
            ->assertJsonPath('data.app.update_required', false)
            ->assertJsonPath('data.maintenance.active', false)
            ->assertJsonStructure([
                'data' => [
                    'app' => ['name', 'api_version', 'min_supported_app_version', 'latest_app_version', 'update_required', 'deep_link_scheme'],
                    'maintenance' => ['active', 'message'],
                    'urls' => ['support', 'privacy', 'terms', 'release_notes', 'web_base', 'store'],
                ],
            ]);
    }
}
