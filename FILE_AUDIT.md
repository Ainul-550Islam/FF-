# FF Arena — A-to-Z File Audit (কিছুই স্কিপ হয়নি — প্রমাণ)

Generated: 2026-09-23 · প্রতিটি ফাইল ডিস্কে আছে কিনা সরাসরি যাচাই করা।

## Phase B (Task 4) — next-30: Models (11) (11)

- ✅ `app/Models/MarketingAffiliate.php`
- ✅ `app/Models/MarketingAffiliateReferral.php`
- ✅ `app/Models/MarketingPromoCode.php`
- ✅ `app/Models/MarketingPromoRedemption.php`
- ✅ `app/Models/MarketingArticle.php`
- ✅ `app/Models/MarketingArticleCategory.php`
- ✅ `app/Models/MarketingExperiment.php`
- ✅ `app/Models/MarketingExperimentVariant.php`
- ✅ `app/Models/MarketingAutomation.php`
- ✅ `app/Models/MarketingPushSubscription.php`
- ✅ `app/Models/MarketingUtmSnapshot.php`

## Phase B (Task 4) — next-30: Services (7) (7)

- ✅ `app/Services/MarketingAffiliateService.php`
- ✅ `app/Services/MarketingPromoCodeService.php`
- ✅ `app/Services/MarketingContentService.php`
- ✅ `app/Services/MarketingExperimentService.php`
- ✅ `app/Services/MarketingAutomationService.php`
- ✅ `app/Services/MarketingPushService.php`
- ✅ `app/Services/MarketingUtmGovernanceService.php`

## Phase B (Task 4) — next-30: Controllers (6) (6)

- ✅ `app/Http/Controllers/MarketingAffiliateController.php`
- ✅ `app/Http/Controllers/MarketingPromoCodeController.php`
- ✅ `app/Http/Controllers/MarketingArticleController.php`
- ✅ `app/Http/Controllers/MarketingExperimentController.php`
- ✅ `app/Http/Controllers/MarketingAutomationController.php`
- ✅ `app/Http/Controllers/MarketingPushController.php`

## Phase B (Task 4) — next-30: FormRequests (3) (3)

- ✅ `app/Http/Requests/StoreMarketingAffiliateRequest.php`
- ✅ `app/Http/Requests/ApplyMarketingPromoCodeRequest.php`
- ✅ `app/Http/Requests/StoreMarketingPushSubscriptionRequest.php`

## Phase B — migrations (11) (11)

- ✅ `database/migrations/2026_09_24_000001_create_marketing_affiliates_table.php`
- ✅ `database/migrations/2026_09_24_000002_create_marketing_affiliate_referrals_table.php`
- ✅ `database/migrations/2026_09_24_000003_create_marketing_promo_codes_table.php`
- ✅ `database/migrations/2026_09_24_000004_create_marketing_promo_redemptions_table.php`
- ✅ `database/migrations/2026_09_24_000005_create_marketing_articles_table.php`
- ✅ `database/migrations/2026_09_24_000006_create_marketing_article_categories_table.php`
- ✅ `database/migrations/2026_09_24_000007_create_marketing_experiments_table.php`
- ✅ `database/migrations/2026_09_24_000008_create_marketing_experiment_variants_table.php`
- ✅ `database/migrations/2026_09_24_000009_create_marketing_automations_table.php`
- ✅ `database/migrations/2026_09_24_000010_create_marketing_push_subscriptions_table.php`
- ✅ `database/migrations/2026_09_24_000011_create_marketing_utm_snapshots_table.php`

## Phase A (P0) — Models (5) (5)

- ✅ `app/Models/MarketingAttribution.php`
- ✅ `app/Models/MarketingEvent.php`
- ✅ `app/Models/MarketingConsent.php`
- ✅ `app/Models/MarketingLead.php`
- ✅ `app/Models/MarketingCampaign.php`

## Phase A (P0) — Services (4) (4)

- ✅ `app/Services/MarketingAttributionService.php`
- ✅ `app/Services/MarketingTrackingService.php`
- ✅ `app/Services/MarketingLeadService.php`
- ✅ `app/Services/MarketingCampaignService.php`

## Phase A (P0) — Controllers (5) (5)

- ✅ `app/Http/Controllers/MarketingPageController.php`
- ✅ `app/Http/Controllers/MarketingLeadController.php`
- ✅ `app/Http/Controllers/MarketingTrackingController.php`
- ✅ `app/Http/Controllers/MarketingCampaignController.php`
- ✅ `app/Http/Requests/StoreMarketingLeadRequest.php`

## Phase A — infra (middleware, config, views, OG) (13)

- ✅ `app/Http/Middleware/CaptureMarketingAttribution.php`
- ✅ `config/marketing.php`
- ✅ `resources/views/marketing/privacy.blade.php`
- ✅ `resources/views/marketing/terms.blade.php`
- ✅ `resources/views/marketing/faq.blade.php`
- ✅ `resources/views/marketing/contact.blade.php`
- ✅ `resources/views/marketing/landing.blade.php`
- ✅ `resources/views/marketing/blog/index.blade.php`
- ✅ `resources/views/marketing/blog/show.blade.php`
- ✅ `resources/views/marketing/affiliate/dashboard.blade.php`
- ✅ `resources/views/admin/marketing/experiments.blade.php`
- ✅ `resources/views/admin/marketing/automations.blade.php`
- ✅ `public/img/og-default.png`

## Phase A — migrations (5) (5)

- ✅ `database/migrations/2026_09_23_000001_create_marketing_attributions_table.php`
- ✅ `database/migrations/2026_09_23_000002_create_marketing_events_table.php`
- ✅ `database/migrations/2026_09_23_000003_create_marketing_leads_table.php`
- ✅ `database/migrations/2026_09_23_000004_create_marketing_consents_table.php`
- ✅ `database/migrations/2026_09_23_000005_create_marketing_campaigns_table.php`

## Test suites (12) (12)

- ✅ `tests/Feature/MarketingAttributionTest.php`
- ✅ `tests/Feature/MarketingTrackingTest.php`
- ✅ `tests/Feature/MarketingLeadTest.php`
- ✅ `tests/Feature/MarketingPagesTest.php`
- ✅ `tests/Feature/MarketingConversionTest.php`
- ✅ `tests/Feature/MarketingAffiliateTest.php`
- ✅ `tests/Feature/MarketingPromoCodeTest.php`
- ✅ `tests/Feature/MarketingContentTest.php`
- ✅ `tests/Feature/MarketingExperimentTest.php`
- ✅ `tests/Feature/MarketingAutomationTest.php`
- ✅ `tests/Feature/MarketingPushTest.php`
- ✅ `tests/Feature/MarketingUtmGovernanceTest.php`

## Automation hooks (3, in product code) (2)

- ✅ `app/Providers/AppServiceProvider.php`
- ✅ `app/Services/PaymentService.php`

## Packaging & docs (10)

- ✅ `VSCODE_SETUP.md`
- ✅ `MARKETING_PHASE_B_REPORT.md`
- ✅ `README.md`
- ✅ `composer.json`
- ✅ `composer.lock`
- ✅ `pint.json`
- ✅ `.env.example`
- ✅ `.gitignore`
- ✅ `artisan`
- ✅ `routes/web.php`

## Hook / route sanity (content checks)

- ✅ AppServiceProvider → user.registered hook: `TRIGGER_USER_REGISTERED`
- ✅ MarketingLeadService → lead.subscribed hook: `TRIGGER_LEAD_SUBSCRIBED`
- ✅ PaymentService → payment.failed hook: `TRIGGER_PAYMENT_FAILED`
- ✅ PaymentService → payment_success conversion: `payment_success`
- ✅ routes/web.php → Phase 21 block: `Phase 21`

---
**Total files checked: 94 + 5 content checks**
**MISSING: 0**

উপরের সবগুলো ✅ — অর্থাৎ শুরু থেকে শেষ পর্যন্ত লেখা **প্রতিটি ফাইল** এই রিপো/জিপে আছে; কিছুই বাদ পড়েনি।
(vendor/, database.sqlite, logs, bin/k6 ইচ্ছাকৃতভাবে বাদ — এগুলো source নয়; composer install / php artisan migrate দিয়ে তৈরি হয়।)