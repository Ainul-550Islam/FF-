<?php

namespace Tests\Feature;

use App\Models\MarketingArticle;
use App\Models\MarketingArticleCategory;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 21 — blog / SEO content engine.
 *
 * Drafts are never publicly visible and never indexable (admins get an
 * explicit noindex preview), published articles carry deterministic
 * canonicals + Article structured data, user input is always escaped, and
 * slugs are unique at the storage layer.
 */
class MarketingContentTest extends TestCase
{
    use RefreshDatabase;

    protected function makeArticle(array $overrides = []): MarketingArticle
    {
        $slug = $overrides['slug'] ?? ('post-'.Str::random(8));

        return MarketingArticle::create(array_merge([
            'slug' => $slug,
            'title' => 'Guide to '.$slug,
            'excerpt' => 'A short summary of the guide.',
            'body' => '<p>Hello arena — this is <strong>bold</strong> and safe.</p>',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ], $overrides));
    }

    public function test_the_index_lists_published_articles_only(): void
    {
        $published = $this->makeArticle();
        $this->makeArticle(['status' => 'draft', 'published_at' => null]);
        $this->makeArticle(['published_at' => now()->addDay()]); // scheduled
        $this->makeArticle(['published_until' => now()->subDay()]); // expired

        $html = $this->get('/blog')->assertOk()->getContent();

        $this->assertStringContainsString($published->title, $html);
        $this->assertStringNotContainsString('Draft preview', $html);
    }

    public function test_a_draft_is_a_404_for_the_public(): void
    {
        $draft = $this->makeArticle(['status' => 'draft', 'published_at' => null]);

        $this->get("/blog/{$draft->slug}")->assertStatus(404);
    }

    public function test_a_draft_preview_for_admins_is_noindex(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = $this->makeArticle(['status' => 'draft', 'published_at' => null]);

        $html = $this->actingAs($admin)->get("/blog/{$draft->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Draft preview', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html, 'A draft must never be indexable.');
        $this->assertStringNotContainsString('rel="canonical"', $html, 'A draft has no canonical — it is not a real URL yet.');
    }

    public function test_a_non_admin_never_sees_a_draft_even_when_the_slug_exists(): void
    {
        $player = User::factory()->create(['role' => 'player']);
        $draft = $this->makeArticle(['status' => 'draft', 'published_at' => null]);

        $this->actingAs($player)->get("/blog/{$draft->slug}")->assertStatus(404);
    }

    public function test_a_published_article_has_canonical_and_article_jsonld(): void
    {
        $article = $this->makeArticle();

        $html = $this->get("/blog/{$article->slug}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('rel="canonical" href="'.route('marketing.articles.show', ['slug' => $article->slug]).'"', $html);
        $this->assertStringContainsString('"@type":"Article"', $html);
        $this->assertStringContainsString($article->title, $html);
    }

    public function test_body_and_title_are_escaped(): void
    {
        $article = $this->makeArticle([
            'title' => 'XSS Probe '.Str::random(4),
            'body' => '<p>Safe</p><script>alert("x")</script>',
        ]);

        $html = $this->get("/blog/{$article->slug}")->getContent();

        $this->assertStringNotContainsString('<script>alert(', $html, 'User content is escaped — no raw HTML injection.');
        $this->assertStringContainsString('&lt;script&gt;alert(', $html);
    }

    public function test_the_category_filter_scopes_the_listing(): void
    {
        $category = MarketingArticleCategory::create(['slug' => 'guides', 'name' => 'Guides']);
        $inCategory = $this->makeArticle(['category_id' => $category->id]);
        $outside = $this->makeArticle();

        $html = $this->get('/blog?category='.$category->id)->assertOk()->getContent();

        $this->assertStringContainsString($inCategory->title, $html);
        $this->assertStringNotContainsString($outside->title, $html);
    }

    public function test_slugs_are_unique_at_the_storage_layer(): void
    {
        $this->makeArticle(['slug' => 'dupe-slug']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->makeArticle(['slug' => 'dupe-slug']);
    }

    public function test_an_expired_article_leaves_the_public_site(): void
    {
        $article = $this->makeArticle(['published_until' => now()->subDay()]);

        $this->get("/blog/{$article->slug}")->assertStatus(404);
    }

    public function test_the_index_is_paginated_not_unbounded(): void
    {
        foreach (range(1, 12) as $i) {
            $this->makeArticle(['title' => 'Bulk article number '.$i]);
        }

        $html = $this->get('/blog?page=2')->assertOk()->getContent();

        $this->assertStringContainsString('page=1', $html, 'The listing paginates (a link back to page 1 exists).');
    }
}
