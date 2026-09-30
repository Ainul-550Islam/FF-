<?php

namespace App\Services;

use App\Models\MarketingArticle;
use App\Models\MarketingArticleCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Phase 21 — blog / SEO content engine.
 *
 * The public contract is narrow: only published articles inside their
 * publish window are publicly visible, drafts 404 (admins get an explicit
 * noindex preview), slugs are unique at the storage layer and canonicals
 * are deterministic. The existing Seo helper stays the single source of
 * title/meta behavior — this service never emits HTML itself.
 */
class MarketingContentService
{
    /**
     * Publicly visible articles, newest first, optionally scoped to a
     * category. Paginated — never an unbounded listing.
     */
    public function publishedPaginated(?int $categoryId = null): LengthAwarePaginator
    {
        return $this->publicQuery()
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) config('marketing.blog.per_page', 9))
            ->withQueryString();
    }

    /**
     * A single publicly visible article by slug; null = public 404.
     */
    public function findPublishedBySlug(string $slug): ?MarketingArticle
    {
        /** @var MarketingArticle|null $article */
        $article = $this->publicQuery()->where('slug', $slug)->first();

        return $article;
    }

    /**
     * Any article by slug (draft preview path — the controller decides
     * who may see it).
     */
    public function findBySlug(string $slug): ?MarketingArticle
    {
        return MarketingArticle::query()->where('slug', $slug)->first();
    }

    /**
     * Categories for the listing filter.
     */
    public function categories(): Collection
    {
        return MarketingArticleCategory::query()->orderBy('name')->get();
    }

    /**
     * Admin view of all articles with optional status and category filters.
     */
    public function adminPaginated(?string $status = null, ?int $categoryId = null, int $perPage = 15): LengthAwarePaginator
    {
        return MarketingArticle::query()
            ->with('category')
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Create an article (author is optional admin user).
     */
    public function createArticle(array $data, ?User $author = null): MarketingArticle
    {
        return MarketingArticle::create([
            'category_id' => $data['category_id'] ?? null,
            'slug' => mb_strtolower(trim((string) $data['slug'])),
            'title' => trim((string) $data['title']),
            'excerpt' => isset($data['excerpt']) ? trim((string) $data['excerpt']) : null,
            'body' => (string) $data['body'],
            'status' => $data['status'] ?? MarketingArticle::STATUS_DRAFT,
            'author_id' => $author?->id,
            'seo_title' => isset($data['seo_title']) ? trim((string) $data['seo_title']) : null,
            'seo_description' => isset($data['seo_description']) ? trim((string) $data['seo_description']) : null,
            'published_at' => $data['published_at'] ?? ($data['status'] === MarketingArticle::STATUS_PUBLISHED ? now() : null),
            'published_until' => $data['published_until'] ?? null,
        ]);
    }

    /**
     * Update an article.
     */
    public function updateArticle(MarketingArticle $article, array $data): MarketingArticle
    {
        $article->fill([
            'category_id' => array_key_exists('category_id', $data) ? $data['category_id'] : $article->category_id,
            'slug' => isset($data['slug']) ? mb_strtolower(trim((string) $data['slug'])) : $article->slug,
            'title' => isset($data['title']) ? trim((string) $data['title']) : $article->title,
            'excerpt' => array_key_exists('excerpt', $data) ? trim((string) $data['excerpt']) : $article->excerpt,
            'body' => $data['body'] ?? $article->body,
            'status' => $data['status'] ?? $article->status,
            'seo_title' => array_key_exists('seo_title', $data) ? trim((string) $data['seo_title']) : $article->seo_title,
            'seo_description' => array_key_exists('seo_description', $data) ? trim((string) $data['seo_description']) : $article->seo_description,
            'published_at' => array_key_exists('published_at', $data) ? $data['published_at'] : $article->published_at,
            'published_until' => array_key_exists('published_until', $data) ? $data['published_until'] : $article->published_until,
        ]);

        $article->save();

        return $article;
    }

    /**
     * Publish an article immediately.
     */
    public function publish(MarketingArticle $article): MarketingArticle
    {
        $article->forceFill([
            'status' => MarketingArticle::STATUS_PUBLISHED,
            'published_at' => $article->published_at ?? now(),
        ])->save();

        return $article;
    }

    /**
     * Unpublish an article (return to draft).
     */
    public function unpublish(MarketingArticle $article): MarketingArticle
    {
        $article->forceFill([
            'status' => MarketingArticle::STATUS_DRAFT,
        ])->save();

        return $article;
    }

    /**
     * Create an article category.
     */
    public function createCategory(array $data): MarketingArticleCategory
    {
        return MarketingArticleCategory::create([
            'name' => trim((string) $data['name']),
            'slug' => mb_strtolower(trim((string) $data['slug'])),
            'description' => isset($data['description']) ? trim((string) $data['description']) : null,
        ]);
    }

    /**
     * The public visibility predicate: published and inside the window.
     */
    protected function publicQuery()
    {
        return MarketingArticle::query()
            ->where('status', MarketingArticle::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('published_until')
                    ->orWhere('published_until', '>', now());
            })
            ->with('category');
    }
}
