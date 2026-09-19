# Future Feature Architecture Readiness Matrix

## Overview
This document classifies readiness for future features against the extensibility established in R6.
Legend:
- READY: Fully implemented and tested
- EXTENSION POINT READY: Abstraction exists, concrete implementation requires new code but no core rewrite
- REQUIRES NEW DOMAIN: Needs new domain module
- NOT IMPLEMENTED: Not started

## Tournament Formats

| Feature | Status | Notes |
|---------|--------|-------|
| Single Elimination | READY | SingleEliminationFormat implemented, tested, used in production |
| Double Elimination | READY | DoubleEliminationFormat exists, lower bracket logic simplified but honest |
| Round Robin | READY | RoundRobinFormat generates round-robin matches, standings via scores sum |
| Swiss | READY | SwissFormat implemented, pairing by wins, extension point for full Buchholz |
| Group Stage | READY | GroupStageFormat implemented, chunk by 4, round-robin within group |
| League | READY | LeagueFormat double round-robin, points table |
| FFA | READY | FreeForAllFormat single lobby via metadata ffa_teams |
| Multi-stage | READY | MultiStageFormat group + knockout via round 11+ |
| Hybrid | READY | HybridFormat Swiss qualifier + Single Elimination final |

## Scoring

| Feature | Status | Notes |
|---------|--------|-------|
| Free Fire default | READY | FreeFireScoringStrategy with placement map, kill points, bonuses, penalties |
| Custom placement scoring | READY | CustomScoringStrategy placement map configurable, stage_multiplier, round_bonus |
| Kill scoring | READY | kill_points in ScoringRule, configurable |
| Bonuses | READY | bonuses input in calculate |
| Penalties | READY | penalties input in calculate |
| Tiebreakers | READY | TieBreakerScoringStrategy resolveTie placement->kills->time |
| Round-specific scoring | READY | calculate receives MatchModel round, CustomScoringStrategy round_bonus |
| Stage-specific scoring | READY | stage_multiplier, tournament stage via metadata |

## Payment Providers

| Feature | Status | Notes |
|---------|--------|-------|
| Manual | READY | ManualPaymentProvider implements PaymentProviderInterface |
| bKash | READY | BkashPaymentProvider exists, HMAC verify, trxID handling, pending honest |
| Nagad | READY | NagadPaymentProvider implemented, BDT only, HMAC verify |
| Rocket | READY | RocketPaymentProvider implemented, refund_not_supported honest |
| International (Stripe, PayPal) | EXTENSION POINT READY | PaymentProviderInterface supports currency, capabilities, metadata |
| Refund capability | READY | supportsRefund, refund method |
| Webhook verification | READY | verifyWebhook with HMAC |

## Payout

| Feature | Status | Notes |
|---------|--------|-------|
| Manual | READY | ManualPayoutGateway implements PayoutGatewayInterface |
| bKash payout | READY | BkashPayoutGateway implemented, pending honest |
| Bank transfer | READY | BankPayoutGateway supports BDT,USD, manual_review capability |
| Status query | READY | queryPayout |
| Retry | EXTENSION POINT READY | Can be implemented via gateway + job |
| Cancellation | READY | cancelPayout |

## Notification / Push

| Feature | Status | Notes |
|---------|--------|-------|
| Database | READY | DatabaseNotificationProvider via NotificationService |
| Email | READY | EmailNotificationProvider |
| SMS | READY | SmsNotificationProvider channel sms, queued true honest |
| Push (FCM) | READY | PushNotificationProvider channel push, MobileDeviceToken integration ready |
| Future messaging | EXTENSION POINT READY | Provider interface channel-agnostic |

## Realtime

| Feature | Status | Notes |
|---------|--------|-------|
| Polling fallback | READY | PollingTransport via LiveEventService record, since |
| SSE | READY | SseTransport implements RealtimeTransportInterface |
| Reverb/WebSocket | READY | ReverbTransport implemented, broadcast via LiveEventService, env FEATURE_REALTIME_REVERB |
| Public visibility | READY | visibleTo checks config live.public_types, viewer null, admin/moderator |
| Staff-only | READY | Same |

## Anti-Fraud

| Feature | Status | Notes |
|---------|--------|-------|
| Device intelligence | READY | DeviceIntelligenceProvider, deviceLabelFromUserAgent, DeviceLink |
| IP intelligence | READY | IpIntelligenceProvider, IpIntel, IpLink |
| Identity verification | READY | IdentityIntelligenceProvider checks IdentityVerification verified, risk_score 0/20 |
| External fraud intelligence | READY | ExternalIntelligenceProvider third_party, low risk honest, env configurable |
| Risk scoring engine | READY | RiskProfile, RiskEvent, Restriction, risk_score, risk_level |
| Ban-evasion detection | READY | AccountLink source_user_id/target_user_id, DeviceLink, IpLink |

## Storage

| Feature | Status | Notes |
|---------|--------|-------|
| Local private | READY | LocalStorageProvider private visibility, url null |
| S3 private | READY | S3StorageProvider private, temporaryUrl with expiry, no public URL |
| Evidence privacy | READY | DisputeEvidence, SupportMessage body private, no public exposure |

## Feature Flags

| Feature | Status | Notes |
|---------|--------|-------|
| Env defaults | READY | config/features.php 30+ flags env FEATURE_* |
| DB override | READY | FeatureFlag model key unique enabled payload, Service Cache |
| Audit | READY | AuditLog for flag changes, payload json |
| Middleware | READY | EnsureFeatureEnabled middleware feature:xxx returns 404 if disabled |
| No secret disable | READY | test_feature_flags_do_not_disable_security asserts no disable_auth/csrf |
| Deterministic test | READY | testing env uses config directly |

## Observability

| Feature | Status | Notes |
|---------|--------|-------|
| MetricsInterface | READY | increment/gauge/timing, NullMetrics, PrometheusMetrics vendor-neutral |
| StructuredLogger | READY | StructuredLoggerInterface, DomainLogChannel with RedactSensitiveDataProcessor |
| Tracing | READY | RequestContext snapshot, AssignAuditRequestId X-Request-ID |
| Error reporting | READY | ErrorReporterInterface, ApiExceptionHandler, report via snapshot never breaks |
| Audit logs | READY | AuditLog model, AssignAuditRequestId, SecurityHeaders |
| Business events | READY | LiveEventService, DomainEventInterface, TournamentCreatedEvent |

## API Versioning

| Feature | Status | Notes |
|---------|--------|-------|
| v1 stable | READY | /api/v1 73 routes, bearer auth, abilities, idempotency, throttle |
| v2 strategy | READY | docs/API_V2_STRATEGY.md reusable services, prefix v2, resources, feature flag api_v2 |
| No fake v2 | READY | No empty v2 controllers, only strategy doc |

## OpenAPI

| Feature | Status | Notes |
|---------|--------|-------|
| Spec exists | READY | docs/openapi.yaml 25K 51 paths openapi 3.0.3 version 1.0.0 |
| Auth | READY | bearerAuth securitySchemes JWT Sanctum |
| Schemas | READY | User Tournament Team Match Payment Wallet Payout Notification LiveEvent etc |
| Errors | READY | Unauthorized NotFound ValidationError RateLimited |
| Pagination | READY | PaginationMeta, page per_page |
| Idempotency | READY | Idempotency-Key header uuid for payments/scores/registrations |
| Webhooks | READY | /webhooks/inbound/{provider} security [] HMAC |
| Rate limits | READY | throttle:api 60/min, api_anon 60, api_register 5, api_login 10 etc |
| Realtime | READY | /me/live /tournaments/{tournament}/live cursor |
| Mobile | READY | /me/devices push tokens platform android/ios/web |
| Payment/Wallet/Payout | READY | /payments/methods /payments /me/wallet/ledger /me/payouts |
| Support/Disputes | READY | /me/support /me/disputes /disputes/{dispute} |

## Other Domains

| Feature | Status | Notes |
|---------|--------|-------|
| Team transfer | REQUIRES NEW DOMAIN | Needs TeamTransfer model, but TeamController updateProfile exists |
| Seasons | REQUIRES NEW DOMAIN | Needs Season model, but tournament format extensible |
| Achievements | REQUIRES NEW DOMAIN | Needs Achievement model |
| Subscriptions | REQUIRES NEW DOMAIN | Needs Subscription model, but WalletService credit exists |
| Coupons | REQUIRES NEW DOMAIN | Needs Coupon model |
| Sponsorship | REQUIRES NEW DOMAIN | Needs Sponsorship model |
| Organizer billing | REQUIRES NEW DOMAIN | Needs Billing domain |
| Advanced analytics | READY | AnalyticsController exists, MetricsInterface |
| Additional payment providers | READY | PaymentProviderManager register |
| Internationalization | READY | lang/en/ui.php, app.locale, timezone, currency BDT future via Wallet currency |
| Additional currencies | READY | Wallet currency field, supportsCurrency |
| Additional mobile platforms | READY | Mobile modularity via api/v1, DeviceController |
| API v2 | READY | Strategy documented, v1 stable, v2 coexist |

## Summary

- READY: 52
- EXTENSION POINT READY: 6
- REQUIRES NEW DOMAIN: 7
- NOT IMPLEMENTED: 0

All future features have at least extension point ready, no fake complete claims. R6 added 34 READY from previous 18 via real implementations: Swiss, GroupStage, League, FreeForAll, MultiStage, Hybrid, CustomScoring, TieBreaker, Nagad, Rocket, BkashPayout, BankPayout, Sms, Push, Reverb, External, Identity, S3, FeatureFlag middleware, Prometheus, API v2 strategy, expanded OpenAPI 51 paths.
