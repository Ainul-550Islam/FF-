# FF Arena — R9 Exact Source Inventory

**Date:** 2026-09-17 UTC
**PHPUnit R9:** 107 tests, 226 assertions, 26 skipped

## Total

- Total files non-vendor: 331
- Total lines non-vendor:   44423 total
- Total bytes non-vendor: 1773866 total

## Breakdown
- app: files=58 lines=4951 bytes=150059
- config: files=29 lines=2433 bytes=87780
- routes: files=6 lines=260 bytes=17547
- database: files=7 lines=351 bytes=13432
- tests: files=12 lines=1987 bytes=73332
- services/payment-gateway-go: files=88 lines=6241 bytes=172274
- services/security-rust: files=28 lines=1645 bytes=47633
- deploy: files=12 lines=941 bytes=27692
- docs: files=27 lines=4271 bytes=168789
- scripts: files=6 lines=808 bytes=27828

## Classification

BELOW 40MB — NO FILLER ADDED (genuine ~1.5MB non-vendor, ~85MB with vendor)

## Files list (top 200 non-vendor)

./.editorconfig
./.env
./.env.example
./.env.testing
./.gitattributes
./.gitignore
./.phpunit.result.cache
./.sudo_as_admin_successful
./GO_RUST_PAYMENT_SECURITY_REPORT.md
./R3_VIEW_RECOVERY_REPORT.md
./R4_BUSINESS_LOGIC_SCHEMA_RECOVERY_REPORT.md
./R5_FULL_TEST_RECOVERY_REPORT.md
./R6_COMPLETE_SOURCE_AND_FUTURE_ARCHITECTURE_REPORT.md
./R6_FINAL_COMPLETION_SUMMARY.md
./R6_FINAL_VERIFIED.md
./R8_FINAL_PRODUCTION_EXPANSION_REPORT.md
./R9_EXACT_SOURCE_INVENTORY.md
./R9_REAL_INFRASTRUCTURE_INTEGRATION_REPORT.md
./app/Contracts/ErrorReporterInterface.php
./app/Exceptions/ApiExceptionHandler.php
./app/Exceptions/Handler.php
./app/Fraud/Providers/RustFraudProvider.php
./app/Http/Controllers/Api/V1/AppMetaController.php
./app/Http/Controllers/Api/V1/AuthController.php
./app/Http/Controllers/Api/V1/DeviceController.php
./app/Http/Controllers/Api/V1/DisputeController.php
./app/Http/Controllers/Api/V1/GoPaymentController.php
./app/Http/Controllers/Api/V1/LeaderboardController.php
./app/Http/Controllers/Api/V1/LiveController.php
./app/Http/Controllers/Api/V1/MatchController.php
./app/Http/Controllers/Api/V1/MeController.php
./app/Http/Controllers/Api/V1/NotificationController.php
./app/Http/Controllers/Api/V1/NotificationPreferenceController.php
./app/Http/Controllers/Api/V1/PaymentController.php
./app/Http/Controllers/Api/V1/PlayerController.php
./app/Http/Controllers/Api/V1/RustSecurityController.php
./app/Http/Controllers/Api/V1/SupportController.php
./app/Http/Controllers/Api/V1/TeamController.php
./app/Http/Controllers/Api/V1/TokenController.php
./app/Http/Controllers/Api/V1/TournamentController.php
./app/Http/Controllers/Api/V1/WalletController.php
./app/Http/Controllers/Api/V1/WebhookInboundController.php
./app/Http/Controllers/Api/V1/WebhookSubscriptionController.php
./app/Http/Controllers/Controller.php
./app/Http/Controllers/HealthController.php
./app/Http/Middleware/AssignAuditRequestId.php
./app/Http/Middleware/EnsureActiveAccount.php
./app/Http/Middleware/EnsureBearerToken.php
./app/Http/Middleware/EnsureFeatureEnabled.php
./app/Http/Middleware/EnsureIdempotency.php
./app/Http/Middleware/EnsureTokenIsValid.php
./app/Http/Middleware/EnsureUserIsAdmin.php
./app/Http/Middleware/EnsureUserIsStaff.php
./app/Http/Middleware/HttpMetrics.php
./app/Http/Middleware/SecurityHeaders.php
./app/Models/FinancialSettlement.php
./app/Models/IdempotencyRecord.php
./app/Models/LedgerEntry.php
./app/Models/Payment.php
./app/Models/Payout.php
./app/Models/Tournament.php
./app/Models/User.php
./app/Models/Wallet.php
./app/Models/WebhookEvent.php
./app/Payments/Providers/GoPaymentProvider.php
./app/Providers/AppServiceProvider.php
./app/Services/GoPaymentGatewayAdapter.php
./app/Services/Integration/EventPublisher.php
./app/Services/Integration/HealthCheckService.php
./app/Services/Integration/ServiceAuthenticator.php
./app/Services/RustFraudServiceAdapter.php
./app/Services/WalletService.php
./app/Support/Logging/DomainLogChannel.php
./app/Support/Logging/RedactSensitiveDataProcessor.php
./app/Support/Logging/RequestContextProcessor.php
./app/Support/RequestContext.php
./apply_audit_hooks.py
./artisan
./bootstrap.sh
./bootstrap/app.php
./bootstrap/cache/packages.php
./bootstrap/cache/services.php
./bootstrap/providers.php
./composer.json
./composer.lock
./config/account.php
./config/antifraud.php
./config/api.php
./config/app.php
./config/audit.php
./config/auth.php
./config/backup.php
./config/broadcasting.php
./config/cache.php
./config/cors.php
./config/database.php
./config/features.php
./config/filesystems.php
./config/finance.php
./config/live.php
./config/logging.php
./config/mail.php
./config/mobile.php
./config/notifications.php
./config/observability.php
./config/payments.php
./config/queue.php
./config/reverb.php
./config/sanctum.php
./config/services.php
./config/services_go_rust.php
./config/session.php
./config/trustedproxy.php
./config/webhooks.php
./database/.gitignore
./database/database.sqlite
./database/factories/TournamentFactory.php
./database/factories/UserFactory.php
./database/factories/WalletFactory.php
./database/migrations/2026_09_04_000000_create_all_tables.php
./database/migrations/2026_09_17_000000_create_r9_tables.php
./deploy/Dockerfile
./deploy/deploy.sh
./deploy/docker-compose.production.yml
./deploy/docker-compose.tls.yml
./deploy/entrypoint.sh
./deploy/nginx.conf
./deploy/nginx.tls.conf
./deploy/prometheus.yml
./deploy/rollback.sh
./deploy/supervisor-ffarena.conf
./deploy/systemd-ffarena-scheduler.service
./deploy/systemd-ffarena-scheduler.timer
./docker-compose.yml
./docs/API_V2_STRATEGY.md
./docs/DEPLOYMENT.md
./docs/DEPLOYMENT_GATE.md
./docs/DISASTER_RECOVERY.md
./docs/FUTURE_FEATURE_ARCHITECTURE.md
./docs/INCIDENT_RESPONSE.md
./docs/LOAD_TEST_RUNBOOK.md
./docs/LOGGING.md
./docs/MOBILE_APP_SETUP.md
./docs/MOBILE_DEEP_LINKS.md
./docs/MOBILE_DEVICE_QA.md
./docs/MOBILE_PRIVACY.md
./docs/MOBILE_PUSH.md
./docs/MOBILE_RELEASE.md
./docs/MOBILE_SECURITY.md
./docs/MOBILE_STORE_READINESS.md
./docs/MOBILE_TESTING.md
./docs/OBSERVABILITY.md
./docs/PAYMENT_GATEWAY_GO_RUST.md
./docs/PERFORMANCE_ENGINEERING.md
./docs/PRODUCTION_RUNBOOK.md
./docs/SECRETS.md
./docs/SECURITY.md
./docs/SECURITY_SERVICES_GO_RUST.md
./docs/TLS.md
./docs/openapi.js
./docs/openapi.yaml
./gen_report12.sh
./gen_report13.py
./gen_report15.py
./gen_report16.py
./gen_report17.py
./mobile/.gitignore
./mobile/.metadata
./mobile/README.md
./mobile/analysis_options.yaml
./mobile/pubspec.lock
./mobile/pubspec.yaml
./package.json
./phpunit.coverage.xml
./phpunit.pgsql.xml
./phpunit.redis.xml
./phpunit.xml
./pint.json
./public/.htaccess
./public/apple-touch-icon.png
./public/favicon.ico
./public/favicon.svg
./public/index.php
./routes/api.php
./routes/channels.php
./routes/console.php
./routes/health.php
./routes/realtime.php
./routes/web.php
./scripts/r9-backup-restore-smoke.sh
./scripts/r9-log-collection.sh
./scripts/r9-observability-verification.sh
./scripts/r9-placeholder-scan.sh
./scripts/r9-production-smoke.sh
./scripts/r9-security-verification.sh
./services/README.md
./services/docker-compose.yml
./services/payment-gateway-go/Dockerfile
./services/payment-gateway-go/cmd/migrate/main.go
./services/payment-gateway-go/cmd/server/main.go
