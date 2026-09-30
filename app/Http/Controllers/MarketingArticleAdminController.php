<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMarketingArticleCategoryRequest;
use App\Http\Requests\StoreMarketingArticleRequest;
use App\Http\Requests\UpdateMarketingArticleRequest;
use App\Models\MarketingArticle;
use App\Services\MarketingContentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingArticleAdminController
{
    /**
     * Admin article management listing.
     */
    public function index(Request $request, MarketingContentService $contentService): View
    {
        $status = $request->query('status');
        $categoryId = $request->filled('category_id') ? (int) $request->query('category_id') : null;

        $articles = $contentService->adminPaginated($status, $categoryId, 15);
        $categories = $contentService->categories();

        return view('admin.marketing.articles.index', compact('articles', 'categories', 'status', 'categoryId'));
    }

    /**
     * Show authoring form for a new article.
     */
    public function create(MarketingContentService $contentService): View
    {
        $categories = $contentService->categories();
        $article = new MarketingArticle(['status' => MarketingArticle::STATUS_DRAFT]);

        return view('admin.marketing.articles.create', compact('categories', 'article'));
    }

    /**
     * Store a freshly created article.
     */
    public function store(
        StoreMarketingArticleRequest $request,
        MarketingContentService $contentService
    ): RedirectResponse {
        $article = $contentService->createArticle($request->validated(), $request->user());

        return redirect()->route('admin.marketing.articles.index')
            ->with('success', sprintf('Article "%s" created successfully.', $article->title));
    }

    /**
     * Show edit form for an existing article.
     */
    public function edit(MarketingArticle $article, MarketingContentService $contentService): View
    {
        $categories = $contentService->categories();

        return view('admin.marketing.articles.edit', compact('article', 'categories'));
    }

    /**
     * Update an existing article.
     */
    public function update(
        MarketingArticle $article,
        UpdateMarketingArticleRequest $request,
        MarketingContentService $contentService
    ): RedirectResponse {
        $contentService->updateArticle($article, $request->validated());

        return redirect()->route('admin.marketing.articles.index')
            ->with('success', sprintf('Article "%s" updated successfully.', $article->title));
    }

    /**
     * Publish an article immediately.
     */
    public function publish(MarketingArticle $article, MarketingContentService $contentService): RedirectResponse
    {
        $contentService->publish($article);

        return back()->with('success', sprintf('Article "%s" is now published.', $article->title));
    }

    /**
     * Unpublish an article (return to draft).
     */
    public function unpublish(MarketingArticle $article, MarketingContentService $contentService): RedirectResponse
    {
        $contentService->unpublish($article);

        return back()->with('success', sprintf('Article "%s" moved back to draft.', $article->title));
    }

    /**
     * Archive an article.
     */
    public function archive(MarketingArticle $article): RedirectResponse
    {
        $article->forceFill(['status' => MarketingArticle::STATUS_ARCHIVED])->save();

        return back()->with('success', sprintf('Article "%s" archived.', $article->title));
    }

    /**
     * Store a new article category.
     */
    public function storeCategory(
        StoreMarketingArticleCategoryRequest $request,
        MarketingContentService $contentService
    ): RedirectResponse {
        $category = $contentService->createCategory($request->validated());

        return back()->with('success', sprintf('Category "%s" created successfully.', $category->name));
    }
}
