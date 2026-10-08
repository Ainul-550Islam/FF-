<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\MarketingAffiliate;
use App\Models\MarketingAffiliatePayout;
use App\Models\MarketingAffiliateReferral;
use App\Models\MarketingArticle;
use App\Models\MarketingArticleCategory;
use App\Models\User;
use App\Services\MarketingAffiliatePayoutService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 22 — Phase C admin workflow tests:
 * Affiliate payouts lifecycle, approval/rejection, financial safety,
 * article management CRUD, draft noindex protection, and category creation.
 */
class MarketingAdminPhaseCTest extends TestCase
{
    use RefreshDatabase;

    protected function createAffiliateWithSignups(int $signups = 3): array
    {
        $user = User::factory()->create();
        $affiliate = MarketingAffiliate::create([
            'user_id' => $user->id,
            'code' => 'TESTAFF',
            'status' => MarketingAffiliate::STATUS_ACTIVE,
            'name' => 'Partner One',
            'approved_at' => now(),
        ]);

        for ($i = 0; $i < $signups; $i++) {
            $referred = User::factory()->create();
            MarketingAffiliateReferral::create([
                'affiliate_id' => $affiliate->id,
                'anonymous_id' => 'anon_'.uniqid(),
                'referred_user_id' => $referred->id,
                'status' => MarketingAffiliateReferral::STATUS_SIGNED_UP,
                'clicked_at' => now()->subDay(),
                'signed_up_at' => now(),
            ]);
        }

        return [$user, $affiliate];
    }

    public function test_affiliate_can_request_payout_with_sufficient_balance(): void
    {
        [$user, $affiliate] = $this->createAffiliateWithSignups(3); // 3 * ৳50 = ৳150

        $response = $this->actingAs($user)->post('/affiliates/payouts', [
            'amount_bdt' => 100,
            'notes' => 'First withdrawal',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('marketing_affiliate_payouts', [
            'affiliate_id' => $affiliate->id,
            'user_id' => $user->id,
            'amount_minor' => 10000,
            'status' => MarketingAffiliatePayout::STATUS_PENDING,
        ]);
    }

    public function test_payout_request_exceeding_available_balance_is_rejected(): void
    {
        [$user, $affiliate] = $this->createAffiliateWithSignups(1); // 1 * ৳50 = ৳50

        $response = $this->actingAs($user)->post('/affiliates/payouts', [
            'amount_bdt' => 500, // Exceeds ৳50
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, MarketingAffiliatePayout::query()->where('affiliate_id', $affiliate->id)->count());
    }

    public function test_duplicate_pending_payout_request_is_prevented(): void
    {
        [$user, $affiliate] = $this->createAffiliateWithSignups(5);

        // First request succeeds
        $this->actingAs($user)->post('/affiliates/payouts', ['amount_bdt' => 50])->assertSessionHas('success');
        $this->assertSame(1, MarketingAffiliatePayout::query()->where('affiliate_id', $affiliate->id)->count());

        // Second pending request fails
        $this->actingAs($user)->post('/affiliates/payouts', ['amount_bdt' => 50])->assertSessionHas('error');
        $this->assertSame(1, MarketingAffiliatePayout::query()->where('affiliate_id', $affiliate->id)->count());
    }

    public function test_guest_or_non_affiliate_cannot_request_payout(): void
    {
        $this->post('/affiliates/payouts', ['amount_bdt' => 50])->assertRedirect(route('login'));

        $regularUser = User::factory()->create();
        $this->actingAs($regularUser)->post('/affiliates/payouts', ['amount_bdt' => 50])->assertForbidden();
    }

    public function test_admin_can_approve_payout_and_wallet_is_credited(): void
    {
        [$partnerUser, $affiliate] = $this->createAffiliateWithSignups(2); // ৳100
        $admin = User::factory()->create(['role' => 'admin']);

        // Create pending payout of ৳80.00 (8000 poisha)
        $payout = app(MarketingAffiliatePayoutService::class)->requestPayout($affiliate, 8000);

        $walletService = app(WalletService::class);
        $wallet = $walletService->walletFor($partnerUser);
        $this->assertSame(0, $wallet->balance_minor);

        // Step-up (password.recent): the admin has confirmed their password.
        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/marketing/affiliates/payouts/{$payout->id}/approve", [
            'review_notes' => 'Approved after verification',
        ]);

        $response->assertRedirect(route('admin.marketing.affiliates.payouts.index'));
        $response->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame(MarketingAffiliatePayout::STATUS_COMPLETED, $payout->status);
        $this->assertSame($admin->id, $payout->reviewed_by);

        // Wallet was credited
        $wallet->refresh();
        $this->assertSame(8000, $wallet->balance_minor);

        // Ledger entry exists
        $this->assertDatabaseHas('ledger_entries', [
            'wallet_id' => $wallet->id,
            'amount_minor' => 8000,
            'type' => LedgerEntry::TYPE_PAYOUT,
        ]);
    }

    public function test_non_admin_cannot_approve_or_reject_payout(): void
    {
        [$partnerUser, $affiliate] = $this->createAffiliateWithSignups(2);
        $player = User::factory()->create(['role' => 'player']);
        $payout = app(MarketingAffiliatePayoutService::class)->requestPayout($affiliate, 5000);

        $this->actingAs($player)
            ->post("/admin/marketing/affiliates/payouts/{$payout->id}/approve")
            ->assertForbidden();

        $this->actingAs($player)
            ->post("/admin/marketing/affiliates/payouts/{$payout->id}/reject", ['rejection_reason' => 'Invalid'])
            ->assertForbidden();
    }

    public function test_admin_can_reject_payout_with_reason(): void
    {
        [$partnerUser, $affiliate] = $this->createAffiliateWithSignups(2);
        $admin = User::factory()->create(['role' => 'admin']);
        $payout = app(MarketingAffiliatePayoutService::class)->requestPayout($affiliate, 5000);

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/marketing/affiliates/payouts/{$payout->id}/reject", [
            'rejection_reason' => 'Suspected artificial traffic',
            'review_notes' => 'Flagged by audit',
        ]);

        $response->assertRedirect(route('admin.marketing.affiliates.payouts.index'));
        $payout->refresh();
        $this->assertSame(MarketingAffiliatePayout::STATUS_REJECTED, $payout->status);
        $this->assertSame('Suspected artificial traffic', $payout->rejection_reason);
    }

    public function test_invalid_payout_state_transitions_are_blocked(): void
    {
        [$partnerUser, $affiliate] = $this->createAffiliateWithSignups(2);
        $admin = User::factory()->create(['role' => 'admin']);
        $payoutService = app(MarketingAffiliatePayoutService::class);

        $payout = $payoutService->requestPayout($affiliate, 5000);
        $payoutService->approve($payout, $admin);

        // Cannot reject a completed payout
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/admin/marketing/affiliates/payouts/{$payout->id}/reject", [
            'rejection_reason' => 'Too late',
        ])->assertSessionHas('error');

        $payout->refresh();
        $this->assertSame(MarketingAffiliatePayout::STATUS_COMPLETED, $payout->status);
    }

    public function test_admin_can_create_article_and_category(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Create category
        $this->actingAs($admin)->post('/admin/marketing/articles/categories', [
            'name' => 'Tournament Strategy',
            'slug' => 'tournament-strategy',
            'description' => 'Tactics and gameplay advice',
        ])->assertSessionHas('success');

        $category = MarketingArticleCategory::query()->where('slug', 'tournament-strategy')->firstOrFail();

        // Create article
        $response = $this->actingAs($admin)->post('/admin/marketing/articles', [
            'title' => 'How to Rank Up in Squads',
            'slug' => 'how-to-rank-up-in-squads',
            'category_id' => $category->id,
            'excerpt' => 'A guide for competitive teams.',
            'body' => 'Coordinate callouts and land safe.',
            'status' => MarketingArticle::STATUS_DRAFT,
            'seo_title' => 'Squad Rank Up Guide — FF Arena',
            'seo_description' => 'Guide to reach heroic rank with your squad.',
        ]);

        $response->assertRedirect(route('admin.marketing.articles.index'));

        $this->assertDatabaseHas('marketing_articles', [
            'slug' => 'how-to-rank-up-in-squads',
            'status' => MarketingArticle::STATUS_DRAFT,
            'category_id' => $category->id,
        ]);
    }

    public function test_duplicate_article_slug_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        MarketingArticle::create([
            'title' => 'Existing Article',
            'slug' => 'existing-article',
            'body' => 'Body text',
            'status' => MarketingArticle::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($admin)->post('/admin/marketing/articles', [
            'title' => 'Duplicate Slug Post',
            'slug' => 'existing-article',
            'body' => 'Another post',
            'status' => MarketingArticle::STATUS_DRAFT,
        ]);

        $response->assertSessionHasErrors(['slug']);
        $this->assertSame(1, MarketingArticle::query()->where('slug', 'existing-article')->count());
    }

    public function test_admin_can_update_article_and_publish_unpublish(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $article = MarketingArticle::create([
            'title' => 'Initial Title',
            'slug' => 'initial-title',
            'body' => 'Initial body',
            'status' => MarketingArticle::STATUS_DRAFT,
        ]);

        // Update title and keep slug
        $this->actingAs($admin)->put("/admin/marketing/articles/{$article->id}", [
            'title' => 'Updated Title',
            'slug' => 'initial-title',
            'body' => 'Updated body',
            'status' => MarketingArticle::STATUS_DRAFT,
        ])->assertSessionHas('success');

        $article->refresh();
        $this->assertSame('Updated Title', $article->title);

        // Publish
        $this->actingAs($admin)->post("/admin/marketing/articles/{$article->id}/publish")->assertSessionHas('success');
        $article->refresh();
        $this->assertSame(MarketingArticle::STATUS_PUBLISHED, $article->status);
        $this->assertNotNull($article->published_at);

        // Unpublish
        $this->actingAs($admin)->post("/admin/marketing/articles/{$article->id}/unpublish")->assertSessionHas('success');
        $article->refresh();
        $this->assertSame(MarketingArticle::STATUS_DRAFT, $article->status);
    }

    public function test_draft_articles_are_never_publicly_indexable(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $article = MarketingArticle::create([
            'title' => 'Secret Draft',
            'slug' => 'secret-draft',
            'body' => 'Not yet ready for public.',
            'status' => MarketingArticle::STATUS_DRAFT,
        ]);

        // Public guest receives 404
        $this->get('/blog/secret-draft')->assertNotFound();

        // Admin preview receives 200 with noindex alert
        $adminPreview = $this->actingAs($admin)->get('/blog/secret-draft');
        $adminPreview->assertOk();
        $adminPreview->assertSee('Draft preview');
        $adminPreview->assertSee('not indexable');
    }
}
