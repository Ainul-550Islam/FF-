#!/usr/bin/env bash
# Phase 16 + 17 + 18 + 19 — code-style gate (Laravel Pint).
#
# Runs Pint in test mode over the Phase 16–19-owned file set. The legacy
# Phase 01–15 codebase predates the Pint configuration and is adopted
# incrementally; see the PHASE16 report § "known limitations".

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

php vendor/bin/pint --test \
  app/Console/Commands \
  app/Support \
  app/Gateways/BkashGateway.php \
  app/Gateways/BkashTokenizedClient.php \
  app/Gateways/NagadGateway.php \
  app/Gateways/NagadClient.php \
  app/Gateways/SslCommerzGateway.php \
  app/Gateways/SslCommerzClient.php \
  app/Gateways/SslCommerzCheckout.php \
  app/Gateways/CardGateway.php \
  app/Contracts/PaymentStatusQueryable.php \
  app/Http/Controllers/PaymentGatewayCallbackController.php \
  tests/Feature/PaymentBkashTokenizedTest.php \
  tests/Feature/PaymentNagadTest.php \
  tests/Feature/PaymentSslCommerzTest.php \
  tests/Feature/PaymentCardGatewayTest.php \
  app/Contracts/ErrorReporterInterface.php \
  app/Contracts/MetricsInterface.php \
  app/Services/HealthService.php \
  app/Services/CacheInvalidationService.php \
  app/Services/BackupService.php \
  app/Services/OperationsService.php \
  app/Services/NotificationService.php \
  app/Services/PushPreferenceService.php \
  app/Services/Push \
  app/Http/Middleware/SecurityHeaders.php \
  app/Http/Middleware/HttpMetrics.php \
  app/Http/Middleware/AssignAuditRequestId.php \
  app/Http/Controllers/HealthController.php \
  app/Http/Controllers/OpsController.php \
  app/Http/Controllers/SitemapController.php \
  app/Http/Controllers/HomeController.php \
  app/Http/Controllers/TournamentController.php \
  app/Http/Controllers/LeaderboardController.php \
  app/Http/Controllers/ProfileController.php \
  app/Http/Controllers/Api/V1/DeviceController.php \
  app/Http/Controllers/Api/V1/AppMetaController.php \
  app/Http/Controllers/Api/V1/NotificationPreferenceController.php \
  app/Models/MobileDevice.php \
  app/Models/NotificationPreference.php \
  app/Policies/MobileDevicePolicy.php \
  app/Providers/AppServiceProvider.php \
  bootstrap/app.php \
  routes/health.php \
  routes/console.php \
  routes/web.php \
  routes/api.php \
  config/observability.php \
  config/mobile.php \
  config/backup.php \
  config/cors.php \
  config/logging.php \
  config/app.php \
  config/database.php \
  database/migrations/2026_09_09_110000_create_operations_heartbeats_table.php \
  database/migrations/2026_09_11_000000_create_mobile_device_tokens_table.php \
  database/migrations/2026_09_11_000001_add_release_columns_to_mobile_device_tokens.php \
  database/migrations/2026_09_11_000002_create_notification_preferences_table.php \
  database/migrations/2026_09_11_000003_add_encrypted_token_to_mobile_device_tokens.php \
  database/migrations/2026_09_12_000000_convert_json_to_jsonb_on_postgres.php \
  tests/Feature/Api/ApiDeviceTokensTest.php \
  tests/Feature/Api/ApiAppMetaTest.php \
  tests/Feature/Api/ApiNotificationPreferencesTest.php \
  tests/Feature/Api/ApiDeviceReleaseMetadataTest.php \
  tests/Unit/Push \
  tests/Feature/Phase16 \
  tests/Feature/Phase17 \
  tests/Feature/Postgres
