<?php

namespace App\Services;

use App\Models\MarketingArticle;
use App\Models\MarketingArticleCategory;
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
